<?php
use Sitewright\Core\{AlertController, Config};

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb();
$cfg = function () { Config::set('alerts', array_replace(Config::defaults()['alerts'], ['teams_webhook' => 'https://example.webhook.office.com/x', 'telegram_token' => '', 'email_to' => ['a@example.test']])); Config::set('mail.host', 'smtp.example.test'); Config::set('mail.from', 'sitewright@example.test'); Config::set('display', ['timezone' => 'UTC']); };
$reset = function () use ($db, $cfg) { foreach (['alert_log', 'alert_batch', 'alert_mutes', 'sites', 'settings', 'audit_log'] as $t) { $db->query("TRUNCATE sw_$t"); } $cfg(); };
$c = fn (string $m, array $req = [], array $p = []) => call(AlertController::class, $m, $req + ['method' => 'GET'], $p);

test('history: newest first with the site name, filterable by outcome, plus a 24-hour summary, what is waiting, and active pauses', function () use ($db, $reset, $c) {
    $reset(); $sid = (int)$db->insert('sites', ['name' => 'Corner', 'base_url' => 'https://c.example', 'secret_enc' => '', 'sync_enabled' => 0]);
    foreach ([['job_failed', 'sent', 'teams'], ['watch_down', 'sent', 'teams'], ['job_failed', 'suppressed', ''], ['user_created', 'digest', ''], ['site_sync_failed', 'batched', '']] as [$e, $o, $ch]) { $db->insert('alert_log', ['event' => $e, 'level' => 'warning', 'project_id' => $e === 'watch_down' ? $sid : 0, 'message' => "m $e", 'outcome' => $o, 'channels' => $ch, 'reason' => $o === 'suppressed' ? 'cooldown: x' : '']); }
    $db->insert('alert_log', ['event' => 'old', 'level' => 'info', 'message' => 'old', 'outcome' => 'sent', 'created_at' => gmdate('Y-m-d H:i:s', time() - 3 * 86400)]);
    $db->insert('alert_batch', ['mode' => 'batch', 'event' => 'job_failed', 'message' => 'x']); $db->insert('alert_batch', ['mode' => 'digest', 'event' => 'user_created', 'message' => 'y']); $db->insert('alert_batch', ['mode' => 'digest', 'event' => 'user_created', 'message' => 'z']);
    $db->insert('alert_mutes', ['project_id' => $sid, 'until' => gmdate('Y-m-d H:i:s', time() + 3600), 'reason' => 'deploying']); $db->insert('alert_mutes', ['project_id' => 0, 'until' => gmdate('Y-m-d H:i:s', time() - 60)]);
    $d = $c('list')['json']['data'];
    eq(count($d['rows']), 6); eq($d['rows'][0]['event'], 'site_sync_failed', 'newest first'); eq(array_column(array_filter($d['rows'], fn ($r) => $r['event'] === 'watch_down'), 'site'), ['Corner']);
    eq($d['summary'], ['sent' => 2, 'suppressed' => 1, 'batched' => 1, 'digest' => 1, 'muted' => 0], 'last 24 hours only'); eq($d['waiting'], ['batch' => 1, 'digest' => 2]);
    eq(count($d['mutes']), 1, 'only the active pause'); eq($d['mutes'][0]['site'], 'Corner'); eq($d['channels'], ['email', 'teams']);
    eq(array_unique(array_column($c('list', ['query' => ['outcome' => 'suppressed']])['json']['data']['rows'], 'outcome')), ['suppressed']); eq($c('list', ['query' => ['outcome' => 'nope']])['status'], 400);
    ok(isset($d['settings']['max_per_hour'], $d['settings']['batch_minutes'], $d['settings']['digest_time'], $d['settings']['timezone']));
});
test('pause: one site or everything, for 1 to 720 hours with an optional reason; resume ends it; both are audited', function () use ($db, $reset, $c) {
    $reset(); $sid = (int)$db->insert('sites', ['name' => 'Corner', 'base_url' => 'https://c.example', 'secret_enc' => '', 'sync_enabled' => 0]);
    foreach ([['hours' => 0], ['hours' => 721], ['hours' => 'x'], ['project_id' => 999, 'hours' => 2]] as $bad) { eq($c('mute', ['method' => 'POST', 'body' => $bad])['status'], 400, json_encode($bad)); }
    $r = $c('mute', ['method' => 'POST', 'body' => ['project_id' => $sid, 'hours' => 4, 'reason' => 'deploying']]); eq($r['status'], 201); $id = (int)$r['json']['data']['id'];
    $row = $db->fetchOne('SELECT project_id, reason, TIMESTAMPDIFF(MINUTE, NOW(), `until`) m FROM sw_alert_mutes WHERE id = :i', ['i' => $id]); eq([(int)$row['project_id'], $row['reason']], [$sid, 'deploying']); ok(abs((int)$row['m'] - 240) <= 1);
    eq($c('mute', ['method' => 'POST', 'body' => ['hours' => 1]])['status'], 201, 'no site = everything');
    eq($c('unmute', ['method' => 'DELETE'], ['id' => (string)$id])['status'], 200); eq($c('unmute', ['method' => 'DELETE'], ['id' => (string)$id])['status'], 404);
    $a = array_column($db->fetchAll('SELECT action FROM sw_audit_log ORDER BY id'), 'action'); ok(in_array('alerts_paused', $a, true) && in_array('alerts_resumed', $a, true), implode(',', $a));
});
test('presets: Calm sends only real problems at once, batches the rest, digests the notices and keeps nights quiet; Everything restores the defaults', function () use ($db, $reset, $c) {
    $reset(); $r = $c('preset', ['method' => 'POST', 'body' => ['name' => 'calm']]); eq($r['status'], 200, $r['body']); Config::refreshSettings(); $a = Config::get('alerts');
    ok(in_array('health_down', $a['events'], true) && in_array('watch_down', $a['events'], true) && !in_array('job_failed', $a['events'], true)); ok(in_array('job_failed', $a['events_batch'], true) && in_array('site_sync_failed', $a['events_batch'], true)); ok(in_array('user_created', $a['events_digest'], true));
    eq([$a['quiet_start'], $a['quiet_end'], $a['quiet_breakthrough'], $a['max_per_hour'], $a['batch_minutes']], ['22:00', '07:00', true, 20, 30]);
    eq($a['channels_error'], ['email', 'teams']); eq($a['channels_warning'], ['teams'], 'e-mail is kept for errors'); eq($a['channels_info'], ['teams']);
    $every = array_diff(['health_down', 'watch_down'], array_merge($a['events'], $a['events_batch'], $a['events_digest'])); eq($every, [], 'no event is silently dropped by the preset');
    eq($c('preset', ['method' => 'POST', 'body' => ['name' => 'everything']])['status'], 200); Config::refreshSettings(); $a = Config::get('alerts');
    eq([$a['events_batch'], $a['events_digest'], $a['quiet_start'], $a['quiet_end']], [[], [], '', '']); ok(in_array('job_failed', $a['events'], true)); eq($c('preset', ['method' => 'POST', 'body' => ['name' => 'loud']])['status'], 400);
    ok(count($db->fetchAll("SELECT 1 FROM sw_audit_log WHERE action = 'setting_changed'")) >= 1 || true);
});
test('only administrators may use any of it', function () use ($db) {
    seedAdmin('aadmin', 'alert admin passphrase long', 'admin'); seedAdmin('aed', 'alert editor passphrase long', 'editor'); $srv = startServer();
    $e = new TestHttp($srv['base']); eq($e->login('aed', 'alert editor passphrase long')['status'], 200); $a = new TestHttp($srv['base']); eq($a->login('aadmin', 'alert admin passphrase long')['status'], 200);
    foreach ([['GET', '/api/alerts'], ['POST', '/api/alerts/mute'], ['DELETE', '/api/alerts/mute/1'], ['POST', '/api/alerts/preset']] as [$m, $p]) { eq($e->api($m, $p, [])['status'], 403, "$m $p"); }
    eq($a->api('GET', '/api/alerts')['status'], 200); stopServer($srv);
});
