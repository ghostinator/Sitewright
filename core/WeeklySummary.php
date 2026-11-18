<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * One message a week: how every site is doing, in plain lines. Sent through the normal alert path as the event "weekly_summary", so it follows the
 * channels, quiet hours and mute rules chosen on the Alerts page. Sent once per week (the first worker pass after the chosen day and time), also
 * when the worker was down at that moment. Facts only; nothing here is a new measurement, it reads what Sitewright already stored.
 */
final class WeeklySummary
{
    private const DAYS = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
    private const MAX_CHARS = 3200;

    /** Worker tick. @return bool whether a summary was queued */
    public static function sendDue(?int $now = null): bool
    {
        $now ??= time();
        $day = (string)Config::get('digest.weekly_day');
        if (!isset(self::DAYS[$day])) { return false; }
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone(AlertPolicy::timezone());
        [$h, $m] = array_pad(array_map('intval', explode(':', (string)Config::get('digest.weekly_time'))), 2, 0);
        // the moment this week's summary is due, in local time; "this week" starts on Monday
        $due = $local->modify('monday this week')->setTime(0, 0)->modify('+' . (self::DAYS[$day] - 1) . ' days')->setTime($h, $m);
        $week = $local->format('o-W');
        $db = Database::getInstance();
        $kv = $db->t('kv');
        $last = $db->fetchOne("SELECT v FROM `$kv` WHERE k = 'weekly_summary_week'")['v'] ?? null;
        if ($local < $due || $last === $week) { return false; }
        $db->query("INSERT INTO `$kv` (k, v) VALUES ('weekly_summary_week', :v) ON DUPLICATE KEY UPDATE v = VALUES(v)", ['v' => $week]);
        $body = self::build($now);
        if ($body === null) { return false; }
        Alerts::notify('weekly_summary', $body, 'info', 'weekly');
        return true;
    }

    /** The message, or null when there are no sites. */
    public static function build(?int $now = null): ?string
    {
        $now ??= time();
        $db = Database::getInstance();
        $sites = $db->fetchAll('SELECT id, name, base_url, sync_enabled, last_status FROM `' . $db->t('sites') . '` ORDER BY name LIMIT 100');
        if (!$sites) { return null; }
        $since = gmdate('Y-m-d H:i:s', $now - 7 * 86400);
        $out = 'Sitewright weekly summary, week ending ' . gmdate('Y-m-d', $now) . " (UTC)\n";
        $shown = 0;
        foreach ($sites as $s) {
            $id = (int)$s['id'];
            $lines = [];
            $w = $db->fetchOne('SELECT COUNT(*) n, SUM(last_status = \'down\') down, AVG(last_score) avg_score FROM `' . $db->t('watch_items') . '` WHERE project_id = :p AND enabled = 1', ['p' => $id]);
            if ((int)$w['n'] > 0) { $lines[] = 'Watched pages: ' . (int)$w['n'] . ($w['avg_score'] !== null ? ', average score ' . (int)round((float)$w['avg_score']) : '') . ((int)$w['down'] > 0 ? ', ' . (int)$w['down'] . ' not reachable' : ', all reachable'); }
            $a = $db->fetchOne("SELECT summary, finished_at FROM `" . $db->t('audits') . "` WHERE project_id = :p AND status = 'done' ORDER BY id DESC LIMIT 1", ['p' => $id]);
            if ($a) {
                $sev = (array)((json_decode((string)$a['summary'], true) ?: [])['by_severity'] ?? []);
                $lines[] = 'Latest audit (' . substr((string)$a['finished_at'], 0, 10) . '): ' . (int)($sev['error'] ?? 0) . ' errors, ' . (int)($sev['warning'] ?? 0) . ' warnings';
            }
            $n404 = (int)$db->fetchOne('SELECT COUNT(*) n FROM `' . $db->t('404_monitor') . '` WHERE project_id = :p AND last_accessed >= :s', ['p' => $id, 's' => $since])['n'];
            if ($n404 > 0) { $lines[] = 'Missing pages (404) seen this week: ' . $n404; }
            foreach (SiteExpiry::view($id, $now) as $e) {
                if ($e['days_left'] !== null && $e['days_left'] <= 60) { $lines[] = ($e['kind'] === 'cert' ? 'Certificate' : 'Domain') . ($e['days_left'] < 0 ? ' has expired' : ' runs out in ' . $e['days_left'] . ' days'); }
            }
            $v = $db->fetchOne('SELECT data FROM `' . $db->t('site_remote') . "` WHERE site_id = :s AND kind = 'versions' AND state = 'ok'", ['s' => $id]);
            if ($v) {
                $d = (array)json_decode((string)$v['data'], true);
                $upd = array_filter([!empty($d['core_update']) ? 'WordPress' : null, (int)($d['plugin_updates'] ?? 0) > 0 ? (int)$d['plugin_updates'] . ' plugin' : null, (int)($d['theme_updates'] ?? 0) > 0 ? (int)$d['theme_updates'] . ' theme' : null]);
                if ($upd) { $lines[] = 'Updates waiting: ' . implode(', ', $upd); }
            }
            if ((int)$s['sync_enabled'] === 1 && $s['last_status'] === 'error') { $lines[] = 'The last sync with the site failed'; }
            $block = "\n" . $s['name'] . ' (' . preg_replace('#^https?://#', '', rtrim((string)$s['base_url'], '/')) . ")\n" . ($lines ? '  ' . implode("\n  ", $lines) : '  Nothing to report') . "\n";
            if (mb_strlen($out . $block) > self::MAX_CHARS) { $out .= "\n…and " . (count($sites) - $shown) . ' more sites (see the Overview).'; break; }
            $out .= $block; $shown++;
        }
        $c = $db->fetchAll('SELECT outcome, COUNT(*) n FROM `' . $db->t('alert_log') . '` WHERE created_at >= :s GROUP BY outcome', ['s' => $since]);
        if ($c) { $out .= "\nAlerts this week: " . implode(', ', array_map(static fn ($r) => (int)$r['n'] . ' ' . $r['outcome'], $c)) . ' (see Alert history).'; }
        return $out;
    }
}
