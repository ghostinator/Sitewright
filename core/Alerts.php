<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Operator alerts to e-mail (your mail server), a Microsoft Teams Workflows webhook and a Telegram bot.
 * notify() only enqueues one small job per configured channel (with a per-event cooldown), so an outage of a channel can never slow or
 * break a user's request, and a failing channel is retried on its own. Message text never contains secrets: callers pass event
 * descriptions only. Channel URLs/tokens are never logged (the Telegram token sits in its URL path).
 */
final class Alerts
{
    /** @return string[] names of configured channels */
    public static function channels(): array
    {
        $a = (array)Config::get('alerts');
        $c = [];
        if (!empty($a['email_to']) && Mailer::configured()) { $c[] = 'email'; }
        if ((string)$a['teams_webhook'] !== '') { $c[] = 'teams'; }
        if ((string)$a['telegram_token'] !== '' && (string)$a['telegram_chat_id'] !== '') { $c[] = 'telegram'; }
        return $c;
    }

    /** Every event an alert can be about (Settings offers exactly these). */
    public const EVENTS = ['account_changed', 'account_deleted', 'audit_new_issues', 'gsc_sync_failed', 'health_down', 'health_recovered', 'job_failed', 'login_failures', 'mfa_disabled', 'mfa_reset',
        'server_error', 'setting_changed', 'site_sync_failed', 'user_created', 'watch_down', 'watch_recovered', 'watch_regression'];

    /**
     * Raise an alert through the policy (enabled? muted? cooldown, quiet hours, hourly cap, batch or digest?) and record the decision in the
     * alert history. Never throws. $dedupe separates cooldowns of the same event (per page, site or username); $projectId lets a site mute apply.
     */
    public static function notify(string $event, string $message, string $level = 'warning', string $dedupe = '', int $projectId = 0, ?int $now = null): void
    {
        try {
            if (self::channels() === []) { return; }
            $d = AlertPolicy::decide($event, $level, $projectId, $dedupe, $now);
            switch ($d['action']) {
                case 'send':
                    self::queue($d['channels'], $event, $message, $level);
                    self::log($event, $level, $projectId, $message, 'sent', implode(',', $d['channels']), '');
                    break;
                case 'batch':
                case 'digest':
                    Database::getInstance()->insert('alert_batch', ['created_at' => gmdate('Y-m-d H:i:s', $now ?? time()), 'mode' => $d['action'], 'event' => $event, 'level' => $level, 'project_id' => $projectId, 'message' => mb_substr($message, 0, 1500)]);
                    self::log($event, $level, $projectId, $message, $d['action'] === 'batch' ? 'batched' : 'digest', implode(',', $d['channels']), $d['reason']);
                    break;
                default:
                    self::log($event, $level, $projectId, $message, str_starts_with($d['reason'], 'muted') ? 'muted' : 'suppressed', '', $d['reason']);
            }
        } catch (\Throwable $e) {
            Logger::error('alert_enqueue_failed', ['exception' => get_class($e)]);
        }
    }

    /** Like notify() but delivers immediately and returns the per-channel result (the health check must not depend on the job queue). @return array<string,string> */
    public static function notifyDirect(string $event, string $message, string $level = 'warning', string $dedupe = '', int $projectId = 0): array
    {
        $d = AlertPolicy::decide($event, $level, $projectId, $dedupe);
        if ($d['action'] === 'send') {
            $res = self::sendNow($event, $message, $level, $d['channels']);
            self::log($event, $level, $projectId, $message, 'sent', implode(',', $d['channels']), '');
            return $res;
        }
        if ($d['action'] === 'batch' || $d['action'] === 'digest') {
            Database::getInstance()->insert('alert_batch', ['mode' => $d['action'], 'event' => $event, 'level' => $level, 'project_id' => $projectId, 'message' => mb_substr($message, 0, 1500)]);
            self::log($event, $level, $projectId, $message, $d['action'] === 'batch' ? 'batched' : 'digest', implode(',', $d['channels']), $d['reason']);
            return [];
        }
        self::log($event, $level, $projectId, $message, str_starts_with($d['reason'], 'muted') ? 'muted' : 'suppressed', '', $d['reason']);
        return [];
    }

    /**
     * Called by the worker every minute: send collected batches (after the batch window, never during quiet hours) and the daily digest
     * (once a day at the digest time, only if there is something to say). @return int messages queued
     */
    public static function flushDue(?int $now = null): int
    {
        $now ??= time();
        $db = Database::getInstance(); $t = $db->t('alert_batch'); $sent = 0;
        if (!AlertPolicy::inQuietHours($now)) {
            $oldest = $db->fetchOne("SELECT MIN(created_at) m FROM `$t` WHERE mode = 'batch'")['m'] ?? null;
            if ($oldest !== null && strtotime($oldest . ' UTC') <= $now - max(1, (int)Config::get('alerts.batch_minutes')) * 60) {
                $sent += self::sendBundle('alert_batch', $db->fetchAll("SELECT id, created_at, event, level, message FROM `$t` WHERE mode = 'batch' ORDER BY id LIMIT 500"));
            }
        }
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone(AlertPolicy::timezone());
        [$h, $m] = array_pad(array_map('intval', explode(':', (string)Config::get('alerts.digest_time'))), 2, 0);
        $today = $local->format('Y-m-d');
        if ((int)$local->format('G') * 60 + (int)$local->format('i') >= $h * 60 + $m && self::kvGet('alert_digest_date') !== $today) {
            self::kvSet('alert_digest_date', $today);
            $rows = $db->fetchAll("SELECT id, created_at, event, level, message FROM `$t` WHERE mode = 'digest' ORDER BY id LIMIT 500");
            if ($rows) { $sent += self::sendBundle('alert_digest', $rows); }
        }
        return $sent;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function sendBundle(string $event, array $rows): int
    {
        $db = Database::getInstance();
        $rank = ['info' => 0, 'warning' => 1, 'error' => 2]; $level = 'info';
        foreach ($rows as $r) { if (($rank[$r['level']] ?? 1) > $rank[$level]) { $level = $r['level']; } }
        $tz = AlertPolicy::timezone();
        $lines = [];
        foreach ($rows as $r) { $lines[] = (new \DateTimeImmutable($r['created_at'] . ' UTC'))->setTimezone($tz)->format('H:i') . ' [' . $r['level'] . '] ' . $r['event'] . ': ' . $r['message']; }
        $head = count($rows) . ($event === 'alert_digest' ? ' alerts in the daily digest' : ' alerts, sent together') . ":\n";
        $body = $head; $shown = 0;
        foreach ($lines as $l) { if (mb_strlen($body . $l) > 1400) { break; } $body .= $l . "\n"; $shown++; }
        if ($shown < count($lines)) { $body .= '…and ' . (count($lines) - $shown) . ' more (see Alert history).'; }
        $channels = array_values(array_intersect(self::channels(), (array)Config::get('alerts.channels_' . $level)));
        $ids = implode(',', array_map(static fn (array $r): int => (int)$r['id'], $rows));
        $db->query('DELETE FROM `' . $db->t('alert_batch') . "` WHERE id IN ($ids)");
        if ($channels === []) { self::log($event, $level, 0, $head, 'suppressed', '', "no channel is set up for $level alerts"); return 0; }
        self::queue($channels, $event, $body, $level);
        self::log($event, $level, 0, $head, 'sent', implode(',', $channels), count($rows) . ' collected alerts');
        return 1;
    }

    /** @param string[] $channels */
    private static function queue(array $channels, string $event, string $message, string $level): void
    {
        foreach ($channels as $ch) { Jobs::enqueue('alert', ['channel' => $ch, 'event' => $event, 'message' => $message, 'level' => $level], null, 0, 4); }
    }

    private static function log(string $event, string $level, int $projectId, string $message, string $outcome, string $channels, string $reason): void
    {
        try {
            Database::getInstance()->insert('alert_log', ['event' => mb_substr($event, 0, 60), 'level' => $level, 'project_id' => $projectId, 'message' => mb_substr($message, 0, 500), 'outcome' => $outcome, 'channels' => $channels, 'reason' => mb_substr($reason, 0, 120)]);
        } catch (\Throwable) {
        }
    }

    /** Hourly housekeeping: 30 days of history, 7 days for a batch that never went out. @return int rows deleted */
    public static function purgeHistory(): int
    {
        $db = Database::getInstance();
        return $db->query('DELETE FROM `' . $db->t('alert_log') . '` WHERE created_at < NOW() - INTERVAL 30 DAY')->rowCount() + $db->query('DELETE FROM `' . $db->t('alert_batch') . '` WHERE created_at < NOW() - INTERVAL 7 DAY')->rowCount();
    }

    private static function kvGet(string $k): ?string
    {
        $db = Database::getInstance();
        $r = $db->fetchOne('SELECT v FROM `' . $db->t('kv') . '` WHERE k = :k', ['k' => $k]);
        return $r ? (string)$r['v'] : null;
    }

    private static function kvSet(string $k, string $v): void
    {
        $db = Database::getInstance();
        $db->query('INSERT INTO `' . $db->t('kv') . '` (k, v) VALUES (:k, :v) ON DUPLICATE KEY UPDATE v = VALUES(v)', ['k' => $k, 'v' => $v]);
    }

    /** Count an occurrence; alert once when $threshold occurrences happen within $window seconds. Never throws. */
    public static function watch(string $key, int $threshold, int $window, string $event, string $message, string $level = 'warning'): void
    {
        try {
            $r = RateLimiter::hit('watch:' . $key, $threshold, $window);
            if ($r['remaining'] === 0 && $r['allowed']) { self::notify($event, $message, $level, $key); }
        } catch (\Throwable) {
        }
    }

    /** Send immediately on all channels (health check, `bin/alert-test.php`). @return array<string,string> channel => 'ok' | error */
    public static function sendNow(string $event, string $message, string $level = 'warning', ?array $only = null): array
    {
        $out = [];
        foreach (self::channels() as $ch) {
            if ($only !== null && !in_array($ch, $only, true)) { continue; }
            try { self::deliver($ch, $event, $message, $level); $out[$ch] = 'ok'; }
            catch (\Throwable $e) { $out[$ch] = $e instanceof JobRetryException || $e instanceof MailException ? $e->getMessage() : get_class($e); }
        }
        return $out;
    }

    /** Job handler for the 'alert' queue type. */
    public static function handleJob(array $p): array
    {
        self::deliver((string)($p['channel'] ?? ''), (string)($p['event'] ?? ''), (string)($p['message'] ?? ''), (string)($p['level'] ?? 'warning'));
        return ['message' => 'Alert sent', 'data' => ['channel' => $p['channel'] ?? '']];
    }

    private static function deliver(string $channel, string $event, string $message, string $level): void
    {
        $host = (string)(parse_url((string)Config::get('base_url'), PHP_URL_HOST) ?: 'sitewright');
        $title = strtoupper($level) . ' · ' . $event . ' · ' . $host;
        $message = mb_substr($message, 0, 1500);
        $a = (array)Config::get('alerts');
        try {
            match ($channel) {
                'email' => array_map(static fn (string $to) => Mailer::send($to, '[Sitewright] ' . $event . ' (' . $level . ')', "$title\n\n$message\n"), (array)$a['email_to']),
                'teams' => self::post((string)$a['teams_webhook'], self::teamsCard(
                    ucfirst(str_replace('_', ' ', $event)), $message, $level,
                    ['Event' => $event, 'Host' => $host, 'Time (UTC)' => gmdate('Y-m-d H:i:s')], (string)Config::get('base_url'))),
                'telegram' => self::post('https://api.telegram.org/bot' . self::telegramToken((string)$a['telegram_token']) . '/sendMessage',
                    ['chat_id' => (string)$a['telegram_chat_id'], 'text' => $title . "\n\n" . $message, 'disable_web_page_preview' => true]),
                default => throw new JobFailedException("Unknown alert channel", 500),
            };
        } catch (MailException $e) {
            throw new JobRetryException('email: ' . $e->getMessage(), 120);
        }
    }

    private static function telegramToken(string $t): string
    {
        if (!preg_match('/^[0-9A-Za-z:_\-]{10,100}$/D', $t)) { throw new JobFailedException('Telegram token has an unexpected format', 500); }
        return $t;   // the Bot API takes the token verbatim in the path (":" is legal there)
    }

    /**
     * Teams "Workflows" webhook: a message with one Adaptive Card attachment (the retired connector format is not used).
     * The header colour shows severity (the Level fact says it in words too); the button opens Sitewright, and only for an http(s) address.
     * @param array<string,string> $facts shown as a table under the message
     */
    public static function teamsCard(string $title, string $message, string $level = 'info', array $facts = [], string $url = ''): array
    {
        $style = ['error' => 'attention', 'warning' => 'warning'][$level] ?? 'emphasis';
        $label = in_array($level, ['error', 'warning', 'info'], true) ? ucfirst($level) : 'Info';
        $rows = [['title' => 'Level', 'value' => $label]];
        foreach ($facts as $k => $v) { $rows[] = ['title' => (string)$k, 'value' => (string)$v]; }
        $content = [
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json', 'type' => 'AdaptiveCard', 'version' => '1.4',
            'msteams' => ['width' => 'Full'],
            'body' => [
                ['type' => 'Container', 'style' => $style, 'bleed' => false, 'items' => [
                    ['type' => 'TextBlock', 'text' => $label . ': ' . $title, 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true]]],
                ['type' => 'TextBlock', 'text' => $message, 'wrap' => true, 'spacing' => 'Medium'],
                ['type' => 'FactSet', 'facts' => $rows, 'spacing' => 'Medium'],
            ],
        ];
        if (preg_match('#^https?://#i', $url) === 1) {
            $content['actions'] = [['type' => 'Action.OpenUrl', 'title' => 'Open Sitewright', 'url' => $url]];
        }
        return ['type' => 'message', 'attachments' => [['contentType' => 'application/vnd.microsoft.card.adaptive', 'contentUrl' => null, 'content' => $content]]];
    }

    private static function post(string $url, array $json): void
    {
        try {
            $res = SafeHttpClient::default()->request('POST', $url, [
                'headers' => ['Content-Type: application/json'], 'body' => json_encode($json, JSON_UNESCAPED_UNICODE),
                'max_bytes' => 65536, 'timeout' => 15, 'accept' => 'application/json', 'follow' => false,
            ]);
        } catch (FetchException $e) {
            throw new JobRetryException('request failed: ' . $e->getMessage(), 180);   // FetchException text never contains the URL
        }
        if ($res['status'] >= 500 || $res['status'] === 429) { throw new JobRetryException('HTTP ' . $res['status'], 180); }
        if ($res['status'] >= 300) { throw new JobFailedException('Destination answered HTTP ' . $res['status'], 502); }
    }
}
