<?php
use Sitewright\Core\{AlertPolicy, Alerts, Config, Health, Jobs, Settings};

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb();
$cfg = function (array $o = []) {
    Config::set('alerts', array_replace(Config::defaults()['alerts'], ['teams_webhook' => 'https://example.webhook.office.com/x', 'telegram_token' => '123456:ABCDEFGHIJ', 'telegram_chat_id' => '-1', 'email_to' => [], 'cooldown' => 300] + $o));
    Config::set('mail.host', ''); Config::set('display', ['timezone' => 'America/Denver']); Config::set('health', ['bad_checks' => 3]);
};
$reset = function () use ($db, $cfg) { foreach (['alert_log', 'alert_batch', 'alert_mutes', 'jobs', 'rate_limits', 'kv', 'sites'] as $t) { $db->query("TRUNCATE sw_$t"); } $cfg(); };
$jobs = fn () => array_map(fn ($r) => json_decode($r['payload'], true), $db->fetchAll("SELECT payload FROM sw_jobs WHERE type = 'alert' ORDER BY id"));
$log = fn () => $db->fetchAll('SELECT event, outcome, channels, reason FROM sw_alert_log ORDER BY id');
$T = fn (string $utc) => strtotime($utc . ' UTC');

test('policy: a listed event is sent to the channels its level is routed to; an unlisted event is held back with the reason', function () use ($reset, $cfg) {
    $reset(); $d = AlertPolicy::decide('job_failed', 'warning'); eq([$d['action'], $d['channels']], ['send', ['teams', 'telegram']]);
    $d = AlertPolicy::decide('not_an_event', 'warning'); eq($d['action'], 'suppress'); ok(str_contains($d['reason'], 'not enabled'), $d['reason']);
    $cfg(['channels_info' => ['teams'], 'channels_warning' => [], 'channels_error' => ['telegram', 'teams']]);
    eq(AlertPolicy::decide('user_created', 'info')['channels'], ['teams']); eq(AlertPolicy::decide('job_failed', 'warning')['action'], 'suppress'); eq(AlertPolicy::decide('server_error', 'error')['channels'], ['teams', 'telegram'] === [] ? [] : ['teams', 'telegram'], 'config order does not matter');
});
test('policy: an event can be sent now, batched, put in the daily digest, or off', function () use ($reset, $cfg) {
    $reset(); $cfg(['events' => ['server_error'], 'events_batch' => ['job_failed'], 'events_digest' => ['user_created']]);
    eq(AlertPolicy::decide('server_error', 'error')['action'], 'send'); eq(AlertPolicy::decide('job_failed', 'warning')['action'], 'batch'); eq(AlertPolicy::decide('user_created', 'info')['action'], 'digest'); eq(AlertPolicy::decide('watch_down', 'error')['action'], 'suppress');
});
test('policy: the cooldown holds a repeat of the SAME event for the same item; per-event cooldowns override; health and test events are never cooled down', function () use ($reset, $cfg) {
    $reset(); eq(AlertPolicy::decide('job_failed', 'warning', 0, 'a')['action'], 'send'); $d = AlertPolicy::decide('job_failed', 'warning', 0, 'a'); eq($d['action'], 'suppress'); ok(str_contains($d['reason'], 'cooldown') && str_contains($d['reason'], '5 min'), $d['reason']);
    eq(AlertPolicy::decide('job_failed', 'warning', 0, 'b')['action'], 'send', 'another item is a different alert');
    $cfg(['cooldowns' => ['watch_down=0', 'job_failed=3600']]); $reset2 = $GLOBALS['db']->query('TRUNCATE sw_rate_limits');
    foreach ([1, 2, 3] as $n) { eq(AlertPolicy::decide('watch_down', 'error', 0, 'w')['action'], 'send', 'cooldown 0 for this event'); }
    AlertPolicy::decide('job_failed', 'warning', 0, 'z'); ok(str_contains(AlertPolicy::decide('job_failed', 'warning', 0, 'z')['reason'], '60 min'), 'one hour override');
    foreach ([1, 2, 3] as $n) { eq(AlertPolicy::decide('health_down', 'error')['action'], 'send', 'state-change events are not cooled down'); }
});
$GLOBALS['db'] = $db;
test('policy: quiet hours (in the display time zone) hold everything except errors, unless break-through is off', function () use ($reset, $cfg, $T) {
    $cfg(['quiet_start' => '22:00', 'quiet_end' => '07:00', 'quiet_breakthrough' => true]);
    $night = $T('2026-11-18 06:30:00'); $day = $T('2026-11-18 20:00:00');   // 23:30 and 13:00 in Denver (UTC-7)
    $reset(); $cfg(['quiet_start' => '22:00', 'quiet_end' => '07:00', 'quiet_breakthrough' => true]);
    $d = AlertPolicy::decide('job_failed', 'warning', 0, 'q1', $night); eq($d['action'], 'batch'); ok(str_contains($d['reason'], 'quiet'), $d['reason']);
    eq(AlertPolicy::decide('server_error', 'error', 0, 'q2', $night)['action'], 'send', 'errors break through'); eq(AlertPolicy::decide('job_failed', 'warning', 0, 'q3', $day)['action'], 'send', 'daytime');
    $cfg(['quiet_start' => '22:00', 'quiet_end' => '07:00', 'quiet_breakthrough' => false]); eq(AlertPolicy::decide('server_error', 'error', 0, 'q4', $night)['action'], 'batch');
    $cfg(['quiet_start' => '09:00', 'quiet_end' => '17:00']); eq(AlertPolicy::decide('job_failed', 'warning', 0, 'q5', $day)['action'], 'batch', 'a same-day window works too');
    $cfg(['quiet_start' => '', 'quiet_end' => '']); eq(AlertPolicy::decide('job_failed', 'warning', 0, 'q6', $night)['action'], 'send', 'no window, no quiet time');
});
test('policy: the hourly cap per channel holds the overflow in the batch instead of dropping it; each channel counts separately', function () use ($reset, $cfg) {
    $reset(); $cfg(['max_per_hour' => 2]); $evs = ['job_failed', 'watch_down', 'server_error', 'site_sync_failed'];
    $acts = array_map(fn ($e) => AlertPolicy::decide($e, 'warning', 0, 'cap')['action'], $evs); eq($acts, ['send', 'send', 'batch', 'batch']);
    $d = AlertPolicy::decide('login_failures', 'warning', 0, 'cap'); ok(str_contains($d['reason'], 'hourly'), $d['reason']);
    $reset(); $cfg(['max_per_hour' => 0]); foreach (range(1, 40) as $n) { $last = AlertPolicy::decide('job_failed', 'warning', 0, "x$n")['action']; } eq($last, 'send', '0 = no cap');
});
test('policy: a mute window silences one site or everything, and ends by itself', function () use ($db, $reset) {
    $reset(); $now = time(); $db->insert('alert_mutes', ['project_id' => 7, 'until' => gmdate('Y-m-d H:i:s', $now + 3600), 'reason' => 'deploying']);
    $d = AlertPolicy::decide('watch_down', 'error', 7, 'm1'); eq($d['action'], 'suppress'); ok(str_contains($d['reason'], 'muted') && str_contains($d['reason'], 'deploying'), $d['reason']);
    eq(AlertPolicy::decide('watch_down', 'error', 8, 'm2')['action'], 'send', 'another site is not muted'); eq(AlertPolicy::decide('server_error', 'error', 0, 'm3')['action'], 'send', 'a site mute does not silence the whole app');
    $db->insert('alert_mutes', ['project_id' => 0, 'until' => gmdate('Y-m-d H:i:s', $now + 3600), 'reason' => 'maintenance']); eq(AlertPolicy::decide('server_error', 'error', 0, 'm4')['action'], 'suppress', 'a global mute silences everything');
    $db->query('UPDATE sw_alert_mutes SET until = NOW() - INTERVAL 1 MINUTE'); eq(AlertPolicy::decide('server_error', 'error', 0, 'm5')['action'], 'send', 'expired');
});
test('notify: sends one job per allowed channel and records every decision, including why something was held back', function () use ($reset, $cfg, $jobs, $log) {
    $reset(); Alerts::notify('job_failed', 'Job #1 failed', 'warning', 'a'); eq(count($jobs()), 2); eq(array_unique(array_column($jobs(), 'event')), ['job_failed']);
    Alerts::notify('job_failed', 'Job #1 failed', 'warning', 'a'); eq(count($jobs()), 2, 'repeat held'); Alerts::notify('nope', 'x', 'info');
    $l = $log(); eq(array_column($l, 'outcome'), ['sent', 'suppressed', 'suppressed']); eq($l[0]['channels'], 'teams,telegram'); ok(str_contains($l[1]['reason'], 'cooldown') && str_contains($l[2]['reason'], 'not enabled'));
    $cfg(['events' => [], 'events_batch' => ['job_failed']]); Alerts::notify('job_failed', 'Job #2 failed', 'warning', 'b'); eq(count($jobs()), 2, 'batched, not sent'); eq(array_column($log(), 'outcome')[3], 'batched');
    ok((int)$GLOBALS['db']->fetchOne("SELECT COUNT(*) n FROM sw_alert_batch WHERE mode = 'batch'")['n'] === 1);
});
test('flush: batched alerts leave as ONE message per channel once the batch window has passed; not before, and not during quiet hours', function () use ($db, $reset, $cfg, $jobs, $log, $T) {
    $reset(); $cfg(['events' => [], 'events_batch' => ['job_failed', 'watch_regression'], 'batch_minutes' => 15]);
    Alerts::notify('job_failed', 'Job #1 failed: x', 'warning', 'a'); Alerts::notify('watch_regression', 'Home dropped to 50', 'warning', 'w1'); Alerts::notify('job_failed', 'Job #2 failed: y', 'warning', 'b');
    eq(Alerts::flushDue(), 0, 'too early'); eq(count($jobs()), 0);
    $db->query('UPDATE sw_alert_batch SET created_at = NOW() - INTERVAL 20 MINUTE'); eq(Alerts::flushDue(), 1); $j = $jobs(); eq(count($j), 2, 'one per channel'); eq($j[0]['event'], 'alert_batch');
    foreach (['Job #1 failed: x', 'Home dropped to 50', 'Job #2 failed: y', '3 alerts'] as $n) { ok(str_contains($j[0]['message'] . ($j[0]['title'] ?? ''), $n), "batch message mentions $n: " . $j[0]['message']); }
    eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_alert_batch')['n'], 0); ok(in_array('sent', array_column($log(), 'outcome'), true)); eq(Alerts::flushDue(), 0, 'nothing left');
    $reset(); $cfg(['events' => [], 'events_batch' => ['job_failed'], 'batch_minutes' => 15, 'quiet_start' => '22:00', 'quiet_end' => '07:00']);
    Alerts::notify('job_failed', 'late', 'warning', 'a', 0, $T('2026-11-18 06:30:00'));
    eq(Alerts::flushDue($T('2026-11-18 06:45:00')), 0, 'still quiet: held'); eq(Alerts::flushDue($T('2026-11-18 14:05:00')), 1, 'after 07:00 local it goes');
});
test('flush: the daily digest goes once a day at the chosen local time, only when there is something to say', function () use ($db, $reset, $cfg, $jobs, $T) {
    $reset(); $cfg(['events' => [], 'events_digest' => ['user_created'], 'digest_time' => '08:00']);
    Alerts::notify('user_created', 'Account bob created', 'info', 'bob'); Alerts::notify('user_created', 'Account cy created', 'info', 'cy');
    eq(Alerts::flushDue($T('2026-11-18 14:00:00')), 0, '07:00 local: before the digest time'); eq(Alerts::flushDue($T('2026-11-18 15:05:00')), 1, '08:05 local'); $j = $jobs(); eq(count($j), 2); eq($j[0]['event'], 'alert_digest'); ok(str_contains($j[0]['message'], 'bob') && str_contains($j[0]['message'], 'cy'));
    Alerts::notify('user_created', 'Account di created', 'info', 'di'); eq(Alerts::flushDue($T('2026-11-18 16:00:00')), 0, 'already sent today'); eq(Alerts::flushDue($T('2026-11-19 15:30:00')), 1, 'next day');
    $db->query('TRUNCATE sw_jobs'); eq(Alerts::flushDue($T('2026-11-20 15:30:00')), 0, 'nothing queued: no empty digest');
});
test('health state is kept in the database (not a file the service user may be unable to write): bad only after N checks in a row, one down, one recovered', function () use ($reset, $cfg) {
    $reset(); eq([Health::record(false)['transition'], Health::record(false)['transition']], [null, null], 'two bad checks are not an outage yet'); eq(Health::record(false)['transition'], 'down'); eq(Health::record(false)['transition'], null, 'still down, no repeat');
    eq(Health::record(true)['transition'], 'recovered'); eq(Health::record(true)['transition'], null);
    Health::record(false); Health::record(true); Health::record(false); eq(Health::record(false)['transition'], null, 'flapping never reaches 3 in a row');
    $cfg(); Config::set('health', ['bad_checks' => 1]); $reset2 = $GLOBALS['db']->query('TRUNCATE sw_kv'); eq(Health::record(false)['transition'], 'down', 'a setting of 1 is immediate');
});
test('healthcheck CLI: an unhealthy minute alerts once the streak is reached and never again while it lasts', function () use ($db, $reset, $log) {
    $reset(); $c = testDbConfig(); $env = sprintf('SW_DB_HOST=%s SW_DB_NAME=%s SW_DB_USER=%s SW_DB_PASS=%s SW_LOG_FILE=/dev/null', escapeshellarg($c['host']), 'sw_test', escapeshellarg($c['user']), escapeshellarg($c['pass']));
    $run = fn () => (string)shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 2)) . " && $env php bin/healthcheck.php 2>&1; echo \"exit=\$?\"");
    $db->query("INSERT INTO sw_jobs (type, payload, status, finished_at, error) " . implode(' UNION ALL ', array_fill(0, 6, "SELECT 'crawl', '{}', 'failed', NOW(), 'x'")));
    foreach ([1, 2] as $n) { $o = $run(); ok(str_contains($o, 'exit=1') && !str_contains($o, 'alert:'), "check $n: unhealthy but quiet: $o"); }
    $o = $run(); ok(str_contains($o, 'alert:'), "third bad check alerts: $o"); $o = $run(); ok(!str_contains($o, 'alert:'), "no repeat: $o");
    $db->query('TRUNCATE sw_jobs'); $o = $run(); ok(str_contains($o, 'exit=0') && str_contains($o, 'alert:'), "recovery alerts once: $o");
});
test('settings: the new alert controls exist with sane defaults and reject bad values', function () {
    $r = Settings::registry();
    foreach (['alerts.events_batch', 'alerts.events_digest', 'alerts.channels_error', 'alerts.channels_warning', 'alerts.channels_info', 'alerts.max_per_hour', 'alerts.batch_minutes', 'alerts.digest_time', 'alerts.quiet_start', 'alerts.quiet_end', 'alerts.quiet_breakthrough', 'alerts.cooldowns', 'display.timezone', 'health.bad_checks'] as $k) { ok(isset($r[$k]), $k); }
    eq([$r['alerts.max_per_hour']['default'], $r['alerts.batch_minutes']['default'], $r['alerts.digest_time']['default'], $r['health.bad_checks']['default'], $r['display.timezone']['default']], [30, 15, '08:00', 3, 'UTC']);
    $v = fn (string $k, $val) => (function () use ($k, $val) { try { Settings::coerce($k, $val); return true; } catch (\Throwable) { return false; } })();
    ok($v('alerts.digest_time', '07:30') && !$v('alerts.digest_time', '25:00') && !$v('alerts.digest_time', 'noon')); ok($v('alerts.quiet_start', '') && $v('alerts.quiet_start', '22:15') && !$v('alerts.quiet_start', '10pm'));
    ok($v('display.timezone', 'America/Denver') && !$v('display.timezone', 'Mars/Phobos')); ok($v('alerts.cooldowns', ['job_failed=3600']) && !$v('alerts.cooldowns', ['job_failed']) && !$v('alerts.cooldowns', ['job failed=5']));
    foreach (['health_down', 'health_recovered'] as $e) { ok(in_array($e, Config::defaults()['alerts']['events'], true), "$e is in the default events"); }
});
test('housekeeping keeps the alert history for 30 days and stale batch rows for 7', function () use ($db, $reset) {
    $reset(); $db->query("INSERT INTO sw_alert_log (event, outcome, created_at) VALUES ('a','sent', NOW() - INTERVAL 40 DAY), ('b','sent', NOW())"); $db->query("INSERT INTO sw_alert_batch (mode, event, created_at) VALUES ('batch','a', NOW() - INTERVAL 9 DAY), ('batch','b', NOW())");
    $o = Jobs::maintenance(); eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_alert_log')['n'], 1); eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_alert_batch')['n'], 1); ok(isset($o['alert_history']));
});
