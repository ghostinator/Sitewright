<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Pages re-checked on a schedule. Each check is a normal analysis stored with the item's watch_id, so history and trends come for free.
 * Rules that matter for operating it:
 *  - a check NEVER fails its job: an unreachable page is recorded on the item (and alerted on a state change), so a dead site cannot turn
 *    the app's health check red or raise job_failed alerts;
 *  - alerts are state changes only (down, recovered, regression), never on the first run, one per change (dedupe = item id);
 *  - scheduling is polite: at most watch.max_per_tick starts per minute, one per host, never while a run for that item is open.
 */
final class Watchlist
{
    /** Called by the worker every minute. @return int jobs queued */
    public static function scheduleDue(): int
    {
        $db = Database::getInstance();
        $max = max(1, (int)Config::get('watch.max_per_tick'));
        $busyIds = []; $busyHosts = [];
        foreach ($db->fetchAll('SELECT payload FROM `' . $db->t('jobs') . "` WHERE type = 'watch_run' AND status IN ('queued','running')") as $j) {
            $busyIds[(int)(json_decode((string)$j['payload'], true)['watch_id'] ?? 0)] = true;
        }
        $t = $db->t('watch_items');
        if ($busyIds) {
            foreach ($db->fetchAll("SELECT url FROM `$t` WHERE id IN (" . implode(',', array_map('intval', array_keys($busyIds))) . ')') as $r) { $busyHosts[self::host($r['url'])] = true; }
        }
        $n = 0;
        foreach ($db->fetchAll("SELECT id, url FROM `$t` WHERE enabled = 1 AND next_run_at <= NOW() ORDER BY next_run_at, id LIMIT 500") as $w) {
            $host = self::host($w['url']);
            if (isset($busyIds[(int)$w['id']]) || isset($busyHosts[$host])) { continue; }
            Jobs::enqueue('watch_run', ['watch_id' => (int)$w['id']], null, 0, 1);   // no user id: the per-user cap must never answer 429 inside the worker
            $busyHosts[$host] = true;
            if (++$n >= $max) { break; }
        }
        return $n;
    }

    /** Job handler: payload {watch_id}. Always returns normally for a page that is merely unreachable. */
    public static function handleJob(array $p): array
    {
        $db = Database::getInstance();
        $w = $db->fetchOne('SELECT * FROM `' . $db->t('watch_items') . '` WHERE id = :i', ['i' => (int)($p['watch_id'] ?? 0)]);
        if (!$w || !(int)$w['enabled']) { return ['message' => 'Skipped', 'data' => ['skipped' => true]]; }
        try {
            $r = SeoAnalyzer::run((string)$w['url'], (int)$w['project_id'], 'site', (int)$w['id']);
        } catch (AnalysisException $e) {
            return self::failed($w, $e->getMessage());
        }
        return self::succeeded($w, $r);
    }

    /** @return array{message:string,data:array} */
    private static function failed(array $w, string $why): array
    {
        $db = Database::getInstance();
        $fails = (int)$w['fail_count'] + 1;
        $down = $fails >= max(1, (int)Config::get('watch.down_after'));
        $wasDown = $w['last_status'] === 'down';
        $retry = min((int)$w['interval_hours'] * 60, max(5, (int)Config::get('watch.retry_minutes')));
        $db->query('UPDATE `' . $db->t('watch_items') . "` SET last_run_at = NOW(), next_run_at = NOW() + INTERVAL :m MINUTE, fail_count = :f, last_error = :e"
            . ($down ? ", last_status = 'down'" : '') . ' WHERE id = :i', ['m' => $retry, 'f' => $fails, 'e' => mb_substr($why, 0, 255), 'i' => (int)$w['id']]);
        if ($down && !$wasDown) {
            Alerts::notify('watch_down', self::name($w) . ' is not reachable: ' . $why, 'error', 'w' . $w['id'], (int)$w['project_id']);
        }
        return ['message' => 'Page unreachable', 'data' => ['status' => $down ? 'down' : 'ok', 'error' => $why]];
    }

    /** @param array{id:int,score:int,results:array} $r */
    private static function succeeded(array $w, array $r): array
    {
        $db = Database::getInstance();
        $score = (int)$r['score'];
        $noindex = (bool)$r['results']['basic']['noindex'];
        $hasTitle = $r['results']['basic']['title'] !== '';
        $changes = [];
        if ($w['last_score'] !== null) {
            $drop = (int)$w['last_score'] - $score;
            if ($drop >= max(1, (int)Config::get('watch.score_drop'))) { $changes[] = "score is $score (was {$w['last_score']}, down $drop)"; }
            if ($noindex && !(int)$w['last_noindex']) { $changes[] = 'the page now has noindex'; }
            if (!$hasTitle && (int)$w['last_has_title']) { $changes[] = 'the title is gone'; }
        }
        $db->query('UPDATE `' . $db->t('watch_items') . "` SET last_run_at = NOW(), next_run_at = NOW() + INTERVAL :h HOUR, last_score = :s, last_status = 'ok', last_error = NULL, fail_count = 0,"
            . ' last_noindex = :n, last_has_title = :t WHERE id = :i', ['h' => (int)$w['interval_hours'], 's' => $score, 'n' => (int)$noindex, 't' => (int)$hasTitle, 'i' => (int)$w['id']]);
        self::queuePageSpeed($w);
        if ($w['last_status'] === 'down') {
            Alerts::notify('watch_recovered', self::name($w) . ' is reachable again (score ' . $score . ').', 'info', 'w' . $w['id'], (int)$w['project_id']);
        }
        if ($changes) {
            Alerts::notify('watch_regression', self::name($w) . ': ' . implode('; ', $changes) . '.', 'warning', 'w' . $w['id'], (int)$w['project_id']);
        }
        return ['message' => 'Checked', 'data' => ['status' => 'ok', 'score' => $score, 'changes' => $changes]];
    }

    /** Web Vitals for a page that has them switched on: at most one measurement per pagespeed.watch_days, never while one is already queued. */
    private static function queuePageSpeed(array $w): void
    {
        if (!(int)$w['pagespeed'] || !PageSpeed::enabled()) { return; }
        $db = Database::getInstance();
        $recent = $db->fetchOne('SELECT 1 x FROM `' . $db->t('pagespeed_runs') . '` WHERE watch_id = :i AND fetched_at > NOW() - INTERVAL :d DAY', ['i' => (int)$w['id'], 'd' => max(1, (int)Config::get('pagespeed.watch_days'))]);
        if ($recent) { return; }
        $payload = ['url' => (string)$w['url'], 'project_id' => (int)$w['project_id'], 'watch_id' => (int)$w['id']];
        $json = (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($db->fetchOne('SELECT 1 x FROM `' . $db->t('jobs') . "` WHERE type = 'pagespeed' AND status IN ('queued','running') AND payload = :p", ['p' => $json])) { return; }
        Jobs::enqueue('pagespeed', $payload, null, 0, 1);
    }

    /** Delete scheduled analyses past the retention window (manual analyses have no watch_id and are kept). @return int rows deleted */
    public static function purge(): int
    {
        $db = Database::getInstance();
        return $db->query('DELETE FROM `' . $db->t('seo_analysis') . '` WHERE watch_id IS NOT NULL AND analyzed_at < NOW() - INTERVAL :d DAY', ['d' => max(1, (int)Config::get('watch.retention_days'))])->rowCount();
    }

    private static function host(string $url): string
    {
        return strtolower((string)parse_url($url, PHP_URL_HOST));
    }

    private static function name(array $w): string
    {
        $db = Database::getInstance();
        $site = (int)$w['project_id'] > 0 ? $db->fetchOne('SELECT name FROM `' . $db->t('sites') . '` WHERE id = :i', ['i' => (int)$w['project_id']]) : null;
        return ($site ? $site['name'] . ': ' : '') . $w['url'];
    }
}
