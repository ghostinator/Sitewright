<?php
use Sitewright\Core\{Config, WeeklySummary};

// Phase 4e: the weekly summary: what it says, that it goes out once a week, and that it follows the day and time chosen.
if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb();
Config::set('alerts', ['email_to' => [], 'teams_webhook' => 'https://example.webhook.office.com/x', 'telegram_token' => '', 'telegram_chat_id' => '', 'cooldown' => 0, 'login_failures' => 3, 'events' => ['weekly_summary'], 'quiet_start' => '', 'quiet_end' => '']);
Config::set('display.timezone', 'UTC'); Config::set('digest.weekly_day', 'mon'); Config::set('digest.weekly_time', '08:00');
$reset = function () use ($db) { foreach (['sites', 'watch_items', 'audits', '404_monitor', 'site_expiry', 'site_remote', 'jobs', 'alert_log', 'rate_limits'] as $t) { $db->query("TRUNCATE sw_$t"); } $db->query("DELETE FROM sw_kv WHERE k = 'weekly_summary_week'"); };
$sent = fn () => (int)$db->fetchOne("SELECT COUNT(*) n FROM sw_jobs WHERE type = 'alert'")['n'];
$at = fn (string $s) => strtotime($s . ' UTC');   // 2026-11-16 is a Monday

test('nothing to summarise without sites; with sites, each gets its facts in plain lines', function () use ($reset, $db, $at) {
    $reset(); eq(WeeklySummary::build($at('2026-11-16 09:00:00')), null);
    $a = (int)$db->insert('sites', ['name' => 'Corner', 'base_url' => 'https://corner.test', 'secret_enc' => 'x', 'sync_enabled' => 1, 'last_status' => 'error']);
    $b = (int)$db->insert('sites', ['name' => 'Quiet', 'base_url' => 'https://quiet.test/', 'secret_enc' => 'x']);
    $db->query("INSERT INTO sw_watch_items (project_id, url, last_score, last_status) VALUES ($a, 'https://corner.test/', 80, 'ok'), ($a, 'https://corner.test/x', 60, 'down')");
    $db->query("INSERT INTO sw_audits (project_id, base_url, status, summary, finished_at) VALUES ($a, 'https://corner.test', 'done', '" . json_encode(['by_severity' => ['error' => 3, 'warning' => 7]]) . "', '2026-11-14 10:00:00')");
    $db->query("INSERT INTO sw_404_monitor (project_id, uri, hits, last_accessed) VALUES ($a, '/new', 2, '2026-11-15 10:00:00'), ($a, '/old', 9, '2026-09-01 10:00:00')");
    $db->query("INSERT INTO sw_site_expiry (site_id, kind, state, expires_at, checked_at) VALUES ($a, 'cert', 'ok', '2026-11-26 00:00:00', NOW()), ($a, 'domain', 'ok', '2028-01-01 00:00:00', NOW())");
    $db->query("INSERT INTO sw_site_remote (site_id, kind, state, data, items) VALUES ($a, 'versions', 'ok', '" . json_encode(['core_update' => true, 'plugin_updates' => 2, 'theme_updates' => 0]) . "', 1)");
    $m = WeeklySummary::build($at('2026-11-16 09:00:00'));
    foreach (['week ending 2026-11-16', 'Corner (corner.test)', 'Watched pages: 2, average score 70, 1 not reachable', 'Latest audit (2026-11-14): 3 errors, 7 warnings', 'Missing pages (404) seen this week: 1',
        'Certificate runs out in 9 days', 'Updates waiting: WordPress, 2 plugin', 'The last sync with the site failed', 'Quiet (quiet.test)', 'Nothing to report'] as $needle) { ok(str_contains($m, $needle), "missing: $needle in\n$m"); }
    ok(!str_contains($m, 'Domain runs out'), 'a domain two years away is not mentioned');
});

test('a very long list of sites is cut with a pointer, never longer than a message can be', function () use ($reset, $db, $at) {
    $reset(); for ($i = 0; $i < 100; $i++) { $db->insert('sites', ['name' => 'Site number ' . str_pad((string)$i, 3, '0', STR_PAD_LEFT) . str_repeat('x', 40), 'base_url' => "https://s$i.test", 'secret_enc' => 'x']); }
    $m = WeeklySummary::build($at('2026-11-16 09:00:00')); ok(mb_strlen($m) < 3500, (string)mb_strlen($m)); ok(preg_match('/…and \d+ more sites/', $m) === 1);
});

test('it is sent once in the chosen week, at or after the chosen day and time, and again the next week', function () use ($reset, $db, $sent, $at) {
    $reset(); $db->insert('sites', ['name' => 'Corner', 'base_url' => 'https://corner.test', 'secret_enc' => 'x']);
    eq(WeeklySummary::sendDue($at('2026-11-16 07:59:00')), false, 'Monday before 08:00');
    eq(WeeklySummary::sendDue($at('2026-11-16 08:00:00')), true); eq($sent(), 1);
    eq(WeeklySummary::sendDue($at('2026-11-16 09:00:00')), false, 'not twice'); eq(WeeklySummary::sendDue($at('2026-11-20 09:00:00')), false, 'not later the same week');
    eq(WeeklySummary::sendDue($at('2026-11-23 08:05:00')), true, 'next Monday'); eq($sent(), 2);
    $ev = json_decode($db->fetchOne("SELECT payload FROM sw_jobs WHERE type = 'alert' ORDER BY id LIMIT 1")['payload'], true); eq([$ev['event'], $ev['level']], ['weekly_summary', 'info']);
});

test('a worker that was down on the chosen day still sends it when it comes back; "off" never sends; another day and a time zone are respected', function () use ($reset, $db, $sent, $at) {
    $reset(); $db->insert('sites', ['name' => 'Corner', 'base_url' => 'https://corner.test', 'secret_enc' => 'x']);
    eq(WeeklySummary::sendDue($at('2026-11-18 10:00:00')), true, 'Wednesday, summary was due Monday');
    $reset(); $db->insert('sites', ['name' => 'Corner', 'base_url' => 'https://corner.test', 'secret_enc' => 'x']);
    Config::set('digest.weekly_day', 'off'); eq(WeeklySummary::sendDue($at('2026-11-18 10:00:00')), false); eq($sent(), 0);
    Config::set('digest.weekly_day', 'fri'); eq(WeeklySummary::sendDue($at('2026-11-19 23:00:00')), false, 'Thursday'); eq(WeeklySummary::sendDue($at('2026-11-20 08:00:00')), true, 'Friday');
    $reset(); $db->insert('sites', ['name' => 'Corner', 'base_url' => 'https://corner.test', 'secret_enc' => 'x']);
    Config::set('digest.weekly_day', 'mon'); Config::set('display.timezone', 'America/Denver');   // 08:00 in Denver is 15:00 UTC (MST, UTC-7)
    eq(WeeklySummary::sendDue($at('2026-11-16 14:30:00')), false, '07:30 in Denver'); eq(WeeklySummary::sendDue($at('2026-11-16 15:00:00')), true, '08:00 in Denver');
    Config::set('display.timezone', 'UTC');
});

test('no sites: nothing is sent and the week is not wasted', function () use ($reset, $sent, $db, $at) {
    $reset(); eq(WeeklySummary::sendDue($at('2026-11-16 09:00:00')), false); eq($sent(), 0);
});
