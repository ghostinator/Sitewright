<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Database-backed job queue. Web requests only enqueue (and poll); `php bin/worker.php` does the slow or flaky work (crawls, image
 * batches, e-mail, alerts), so a request never blocks on a crawl or an SMTP server.
 *  - claiming is one atomic UPDATE, so two workers can never run the same job;
 *  - a job whose worker died (no heartbeat for job_stale_seconds) goes back to the queue, or fails when attempts are used up;
 *  - per-user quota: at most limits.jobs_per_user queued+running jobs.
 */
final class Jobs
{
    /** type => [controller class, method]: the existing request handlers run unchanged, fed from the stored payload. */
    private const CONTROLLER_JOBS = [
        'crawl'       => ['Sitewright\\Modules\\Sitemap\\SitemapController', 'crawlAndGenerate'],
        'bulk_images' => ['Sitewright\\Modules\\ImageSeo\\ImageSeoController', 'bulkAnalyze'],
    ];

    /** @var array<string,callable(array):array> extra handlers (mail, alert, ...) registered by their classes */
    private static array $handlers = [];

    private static ?int $currentJob = null;

    public static function register(string $type, callable $handler): void
    {
        self::$handlers[$type] = $handler;
    }

    /** @param array<string,mixed> $payload */
    public static function enqueue(string $type, array $payload, ?int $userId = null, int $delaySeconds = 0, int $maxAttempts = 1): int
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) > 65536) {
            throw new \InvalidArgumentException('Job payload too large');
        }
        $db = Database::getInstance();
        $t = $db->t('jobs');
        if ($userId !== null) {
            $open = (int)$db->fetchOne("SELECT COUNT(*) n FROM `$t` WHERE user_id = :u AND status IN ('queued','running')", ['u' => $userId])['n'];
            if ($open >= (int)Config::get('limits.jobs_per_user')) {
                Response::error('You already have ' . $open . ' jobs in progress; wait for one to finish.', 429, [], ['Retry-After' => '30']);
            }
        }
        $db->query(
            "INSERT INTO `$t` (type, payload, user_id, max_attempts, run_after) VALUES (:ty, :p, :u, :m, NOW() + INTERVAL :d SECOND)",
            ['ty' => $type, 'p' => $json, 'u' => $userId, 'm' => max(1, $maxAttempts), 'd' => max(0, $delaySeconds)]
        );
        return (int)$db->getConnection()->lastInsertId();
    }

    /** Job row for the status endpoint, or null. Non-admins only see their own jobs. */
    public static function status(int $id, int $userId, bool $isAdmin): ?array
    {
        $db = Database::getInstance();
        $r = $db->fetchOne('SELECT id, type, status, user_id, created_at, started_at, finished_at, result, error, error_status FROM `' . $db->t('jobs') . '` WHERE id = :id', ['id' => $id]);
        if (!$r || (!$isAdmin && (int)$r['user_id'] !== $userId)) {
            return null;
        }
        $r['result'] = $r['result'] !== null ? json_decode((string)$r['result'], true) : null;
        unset($r['user_id']);
        return $r;
    }

    /** Claim and run one job. @return bool false when the queue was empty */
    public static function runNext(): bool
    {
        Config::refreshSettings();   // pick up changes saved on the Settings page since the last job
        $job = self::claim();
        if ($job === null) {
            return false;
        }
        self::execute($job);
        return true;
    }

    /** @return array<string,mixed>|null */
    private static function claim(): ?array
    {
        $db = Database::getInstance();
        $t = $db->t('jobs');
        $stale = (int)Config::get('limits.job_stale_seconds');
        $db->query("UPDATE `$t` SET status = 'failed', error = 'Worker stopped before finishing', error_status = 500, finished_at = NOW() WHERE status = 'running' AND heartbeat_at < NOW() - INTERVAL :s SECOND AND attempts >= max_attempts", ['s' => $stale]);
        $db->query("UPDATE `$t` SET status = 'queued' WHERE status = 'running' AND heartbeat_at < NOW() - INTERVAL :s SECOND AND attempts < max_attempts", ['s' => $stale]);
        for ($i = 0; $i < 5; $i++) {
            $row = $db->fetchOne("SELECT id FROM `$t` WHERE status = 'queued' AND run_after <= NOW() ORDER BY id LIMIT 1");
            if (!$row) {
                return null;
            }
            $won = $db->query("UPDATE `$t` SET status = 'running', attempts = attempts + 1, started_at = NOW(), heartbeat_at = NOW() WHERE id = :id AND status = 'queued'", ['id' => (int)$row['id']])->rowCount();
            if ($won === 1) {
                return $db->fetchOne("SELECT * FROM `$t` WHERE id = :id", ['id' => (int)$row['id']]);
            }
        }
        return null;
    }

    /** @param array<string,mixed> $job */
    private static function execute(array $job): void
    {
        $db = Database::getInstance();
        $t = $db->t('jobs');
        $id = (int)$job['id'];
        self::$currentJob = $id;
        $payload = json_decode((string)$job['payload'], true) ?: [];
        $started = microtime(true);
        try {
            $result = self::run((string)$job['type'], $payload);
            $db->query("UPDATE `$t` SET status = 'done', finished_at = NOW(), result = :r, error = NULL WHERE id = :id", ['r' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 'id' => $id]);
            Logger::info('job_done', ['job' => $id, 'type' => $job['type'], 'ms' => (int)((microtime(true) - $started) * 1000)]);
        } catch (JobRetryException $r) {
            if ((int)$job['attempts'] < (int)$job['max_attempts']) {
                $db->query("UPDATE `$t` SET status = 'queued', run_after = NOW() + INTERVAL :d SECOND, error = :e WHERE id = :id", ['d' => $r->delaySeconds, 'e' => mb_substr($r->getMessage(), 0, 500), 'id' => $id]);
                Logger::warn('job_retry', ['job' => $id, 'type' => $job['type'], 'attempt' => (int)$job['attempts'], 'reason' => $r->getMessage()]);
            } else {
                self::fail($id, $job, $r->getMessage(), 502);
            }
        } catch (JobFailedException $f) {
            self::fail($id, $job, $f->getMessage(), $f->httpStatus);
        } catch (\Throwable $e) {
            Logger::error('job_crashed', ['job' => $id, 'type' => $job['type'], 'exception' => get_class($e), 'error' => $e->getMessage()]);
            self::fail($id, $job, 'Internal error', 500);
        } finally {
            self::$currentJob = null;
            Request::reset();
        }
    }

    private static function fail(int $id, array $job, string $message, int $status): void
    {
        $db = Database::getInstance();
        $db->query("UPDATE `{$db->t('jobs')}` SET status = 'failed', finished_at = NOW(), error = :e, error_status = :s WHERE id = :id", ['e' => mb_substr($message, 0, 500), 's' => $status, 'id' => $id]);
        Logger::warn('job_failed', ['job' => $id, 'type' => $job['type'], 'status' => $status, 'reason' => $message]);
        if (!in_array($job['type'], ['mail', 'alert'], true)) {                 // a failing alert must not raise another alert
            Alerts::notify('job_failed', "Job #$id ({$job['type']}) failed: $message", 'warning');
        }
    }

    /** @return array{message:string,data:mixed} */
    private static function run(string $type, array $payload): array
    {
        if (isset(self::CONTROLLER_JOBS[$type])) {
            [$class, $method] = self::CONTROLLER_JOBS[$type];
            return self::invoke($class, $method, $payload);
        }
        if ($type === 'mail') { return Mailer::handleJob($payload); }
        if ($type === 'alert') { return Alerts::handleJob($payload); }
        if ($type === 'site_sync') { return SiteSync::handleJob($payload); }
        if ($type === 'gsc_sync') { return Gsc::handleJob($payload); }
        if ($type === 'watch_run') { return Watchlist::handleJob($payload); }
        if ($type === 'site_audit') { return SiteAudit::handleJob($payload); }
        if (isset(self::$handlers[$type])) {
            return (self::$handlers[$type])($payload);
        }
        throw new JobFailedException("Unknown job type $type", 500);
    }

    /** Run an HTTP controller method with the payload as its JSON body and turn its Response into a job outcome. */
    private static function invoke(string $class, string $method, array $payload): array
    {
        Request::fake(['method' => 'POST', 'body' => $payload, 'ip' => '127.0.0.1']);
        try {
            (new $class())->$method([]);
            throw new JobFailedException('Handler produced no response', 500);
        } catch (ResponseException $r) {
            $body = json_decode($r->body, true) ?: [];
            if ($r->status >= 400) {
                throw new JobFailedException((string)($body['message'] ?? 'Failed'), $r->status);
            }
            return ['message' => (string)($body['message'] ?? 'Done'), 'data' => $body['data'] ?? null];
        }
    }

    /** A long job calls this inside its loop so it is not declared stuck (job_stale_seconds). No effect outside a running job. */
    public static function touch(): void
    {
        if (self::$currentJob === null) { return; }
        $db = Database::getInstance();
        $db->query('UPDATE `' . $db->t('jobs') . '` SET heartbeat_at = NOW() WHERE id = :id AND status = \'running\'', ['id' => self::$currentJob]);
    }

    public static function heartbeat(): void
    {
        $db = Database::getInstance();
        $db->query("INSERT INTO `{$db->t('kv')}` (k, v) VALUES ('worker_heartbeat', '1') ON DUPLICATE KEY UPDATE v = IF(v = '1', '2', '1'), updated_at = NOW()");
    }

    /** Seconds since the worker last checked in, or null if it never did. */
    public static function workerAge(): ?int
    {
        $db = Database::getInstance();
        $r = $db->fetchOne("SELECT TIMESTAMPDIFF(SECOND, updated_at, NOW()) s FROM `{$db->t('kv')}` WHERE k = 'worker_heartbeat'");
        return $r ? (int)$r['s'] : null;
    }

    /** Periodic housekeeping, called by the worker about once an hour. @return array<string,int> */
    public static function maintenance(): array
    {
        $db = Database::getInstance();
        $out = [];
        $out['jobs'] = $db->query("DELETE FROM `{$db->t('jobs')}` WHERE status IN ('done','failed') AND finished_at < NOW() - INTERVAL :d DAY", ['d' => max(1, (int)Config::get('limits.job_retention_days'))])->rowCount();
        $out['rate_limits'] = $db->query("DELETE FROM `{$db->t('rate_limits')}` WHERE window_start < :old", ['old' => time() - 86400])->rowCount();
        $out['audit'] = Audit::purge();
        $out['watch_history'] = Watchlist::purge();
        $out['audits'] = SiteAudit::purge();
        $out['404'] = (new \Sitewright\Modules\Monitor\MonitorController())->purgeExpired();
        return $out;
    }
}
