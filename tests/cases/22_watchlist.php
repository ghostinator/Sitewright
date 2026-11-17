<?php
use Sitewright\Core\{Config, Database, Jobs, Watchlist, WatchController};

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb();
$GOOD = fn (string $extra = '') => [200, ['Content-Type' => 'text/html; charset=utf-8'], '<!doctype html><html lang="en"><head><title>A perfectly sized page title for the watch list</title>'
    . '<meta name="description" content="A description that is long enough to count as optimal for search engines, with plenty of useful words in it for the reader to see here.">'
    . '<meta name="viewport" content="width=device-width"><script type="application/ld+json">{"@type":"Thing"}</script></head><body><h1>Heading</h1>'
    . '<img src="/a.png" alt="a"><a href="/x">x</a><a href="/y">y</a><a href="/z">z</a><a href="https://o.example/">o</a><p>' . str_repeat('word ', 700) . '</p>' . $extra . '</body></html>'];
$BAD = [200, ['Content-Type' => 'text/html; charset=utf-8'], '<!doctype html><html><head></head><body><p>tiny</p></body></html>'];
$alertCfg = function () { Config::set('alerts', ['email_to' => [], 'teams_webhook' => 'https://example.webhook.office.com/x', 'telegram_token' => '', 'telegram_chat_id' => '', 'cooldown' => 0, 'login_failures' => 3,
    'events' => array_merge(Config::defaults()['alerts']['events'], ['watch_regression', 'watch_down', 'watch_recovered'])]); Config::set('mail.host', ''); };
$alerts = fn () => array_map(fn ($r) => json_decode($r['payload'], true)['event'], $db->fetchAll("SELECT payload FROM sw_jobs WHERE type = 'alert' ORDER BY id"));
$reset = function () use ($db, $alertCfg) { foreach (['watch_items', 'seo_analysis', 'jobs', 'rate_limits'] as $t) { $db->query("TRUNCATE sw_$t"); } $alertCfg(); };
$add = fn (string $url = 'https://w.example/', int $project = 0) => (int)$db->insert('watch_items', ['url' => $url, 'project_id' => $project, 'label' => '']);
$web = function ($page) { $GLOBALS['__page'] = $page; fakeWeb(['https://w.example/' => fn () => $GLOBALS['__page']]); };

test('watch run: first run records a baseline and never alerts; the analysis is stored under the watch id', function () use ($db, $reset, $add, $web, $GOOD, $alerts) {
    $reset(); $id = $add(); $web($GOOD());
    $r = Watchlist::handleJob(['watch_id' => $id]);
    $w = $db->fetchOne('SELECT * FROM sw_watch_items WHERE id = :i', ['i' => $id]);
    eq($w['last_status'], 'ok'); ok((int)$w['last_score'] > 50, 'good page scores well, got ' . $w['last_score']); eq((int)$w['fail_count'], 0);
    eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_seo_analysis WHERE watch_id = :i', ['i' => $id])['n'], 1);
    eq($alerts(), [], 'no alert on the first run'); ok($w['last_run_at'] !== null && strtotime($w['next_run_at']) > time() + 23 * 3600, 'next run in ~24 h');
});
test('watch run: a big score drop alerts once; an unchanged page does not; a small wobble does not', function () use ($db, $reset, $add, $web, $GOOD, $BAD, $alerts) {
    $reset(); $id = $add(); $web($GOOD()); Watchlist::handleJob(['watch_id' => $id]);
    Watchlist::handleJob(['watch_id' => $id]); eq($alerts(), [], 'same page, no alert');
    $web($BAD); Watchlist::handleJob(['watch_id' => $id]);
    eq($alerts(), ['watch_regression']); $m = json_decode($db->fetchOne("SELECT payload FROM sw_jobs WHERE type = 'alert'")['payload'], true);
    ok(str_contains($m['message'], 'https://w.example/') && preg_match('/\d+ \(was \d+/', $m['message']) === 1, $m['message']);
    eq((int)$db->fetchOne('SELECT last_score FROM sw_watch_items')['last_score'] < 50, true);
});
test('watch run: noindex appearing or the title disappearing alerts even when the score barely moves', function () use ($db, $reset, $add, $web, $GOOD, $alerts) {
    $reset(); $id = $add(); $web($GOOD()); Watchlist::handleJob(['watch_id' => $id]);
    $web([200, ['Content-Type' => 'text/html'], str_replace('<meta name="viewport"', '<meta name="robots" content="noindex"><meta name="viewport"', $GOOD()[2])]);
    Watchlist::handleJob(['watch_id' => $id]);
    $m = json_decode($db->fetchOne("SELECT payload FROM sw_jobs WHERE type = 'alert' ORDER BY id DESC LIMIT 1")['payload'], true);
    eq($alerts(), ['watch_regression']); ok(str_contains($m['message'], 'noindex'), $m['message']);
    $db->query("TRUNCATE sw_jobs"); $db->query("UPDATE sw_watch_items SET last_noindex = 0, last_score = 0");
    $web([200, ['Content-Type' => 'text/html'], preg_replace('#<title>.*?</title>#', '<title></title>', $GOOD()[2])]);
    $db->query("UPDATE sw_watch_items SET last_score = 100");   // make the drop irrelevant: only the title rule may fire the alert
    Watchlist::handleJob(['watch_id' => $id]); ok(in_array('watch_regression', $alerts(), true));
});
test('watch run: an unreachable page never fails the job; down is declared after N failures, alerted once, and recovery is alerted once', function () use ($db, $reset, $add, $web, $GOOD, $alerts) {
    $reset(); $id = $add(); $web($GOOD()); Watchlist::handleJob(['watch_id' => $id]);
    $web([503, [], 'down']);
    foreach ([1, 2, 3, 4] as $n) {
        $r = Watchlist::handleJob(['watch_id' => $id]); eq($r['data']['status'], $n >= 2 ? 'down' : 'ok');   // job result is a normal return, never an exception
    }
    eq($alerts(), ['watch_down'], 'one down alert, not one per failure');
    $w = $db->fetchOne('SELECT * FROM sw_watch_items'); eq($w['last_status'], 'down'); eq((int)$w['fail_count'], 4); ok(str_contains((string)$w['last_error'], 'HTTP 503'));
    ok(strtotime($w['next_run_at']) <= time() + 16 * 60, 'a down page is rechecked after the retry delay, not after a day');
    $web($GOOD()); Watchlist::handleJob(['watch_id' => $id]);
    eq($alerts(), ['watch_down', 'watch_recovered']); $row = $db->fetchOne('SELECT last_status, fail_count FROM sw_watch_items'); eq([$row['last_status'], (int)$row['fail_count']], ['ok', 0]);
});
test('a watched page that is down does not turn the app unhealthy or raise job_failed alerts (jobs finish as done)', function () use ($db, $reset, $add, $web, $alerts) {
    $reset(); $web([500, [], 'x']);
    foreach ([$add('https://w.example/'), ] as $id) { Jobs::enqueue('watch_run', ['watch_id' => $id]); }
    while (Jobs::runNext()) {}
    eq($db->fetchOne("SELECT status FROM sw_jobs WHERE type = 'watch_run'")['status'], 'done'); eq((int)$db->fetchOne("SELECT COUNT(*) n FROM sw_jobs WHERE status = 'failed'")['n'], 0);
    ok(!in_array('job_failed', $alerts(), true));
});
test('scheduling: due and enabled only, at most max_per_tick, one per host, no duplicate while a run is open, enqueued without a user', function () use ($db, $reset, $add) {
    $reset(); Config::set('watch.max_per_tick', 3);
    $a = $add('https://a.example/1'); $b = $add('https://a.example/2'); $c = $add('https://b.example/'); $d = $add('https://c.example/'); $e = $add('https://d.example/'); $f = $add('https://e.example/');
    $db->query('UPDATE sw_watch_items SET enabled = 0 WHERE id = :i', ['i' => $e]); $db->query('UPDATE sw_watch_items SET next_run_at = NOW() + INTERVAL 1 HOUR WHERE id = :i', ['i' => $f]);
    eq(Watchlist::scheduleDue(), 3, 'capped'); $ids = array_map(fn ($r) => json_decode($r['payload'], true)['watch_id'], $db->fetchAll("SELECT payload FROM sw_jobs WHERE type = 'watch_run' ORDER BY id"));
    eq($ids, [$a, $c, $d], 'a.example/2 waits (same host), disabled and not-yet-due are skipped');
    ok($db->fetchOne("SELECT COUNT(*) n FROM sw_jobs WHERE type = 'watch_run' AND user_id IS NOT NULL")['n'] == 0, 'no user id: the per-user cap can never answer 429 inside the worker');
    eq(Watchlist::scheduleDue(), 0, 'next tick straight away: nothing new (a.example/2 waits while its host has a run open; the others are open already)');
    fakeWeb([]); while (Jobs::runNext()) {}
    eq(Watchlist::scheduleDue(), 1, 'once the first runs finish, a.example/2 goes');
});
test('retention: old scheduled analyses are purged, manual ones are kept', function () use ($db, $reset) {
    $reset(); Config::set('watch.retention_days', 30);
    foreach ([[5, 60], [5, 5], [null, 60]] as [$watch, $days]) {
        $db->query("INSERT INTO sw_seo_analysis (project_id, url, analysis_type, score, results, watch_id, analyzed_at) VALUES (0, 'https://x/', 'site', 50, '{}', :w, NOW() - INTERVAL $days DAY)", ['w' => $watch]);
    }
    eq(Watchlist::purge(), 1); eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_seo_analysis')['n'], 2);
});
test('a queued run for a deleted or disabled item is skipped quietly', function () use ($db, $reset, $add) {
    $reset(); $id = $add(); $db->query('UPDATE sw_watch_items SET enabled = 0 WHERE id = :i', ['i' => $id]);
    eq(Watchlist::handleJob(['watch_id' => $id])['data'], ['skipped' => true]); eq(Watchlist::handleJob(['watch_id' => 99999])['data'], ['skipped' => true]);
});
test('watch API: add validates url, site, interval, duplicates and the item cap; list shows state and a short score trend; history; patch; run now; delete removes its history', function () use ($db, $reset, $web, $GOOD) {
    $reset(); $web($GOOD()); $sid = (int)$db->insert('sites', ['name' => 'Corner', 'base_url' => 'https://w.example', 'secret_enc' => '', 'sync_enabled' => 0]);
    $post = fn (array $b) => call(WatchController::class, 'create', ['method' => 'POST', 'body' => $b]);
    foreach ([['url' => 'ftp://x'], ['url' => 'https://w.example/', 'project_id' => 999], ['url' => 'https://w.example/', 'interval_hours' => 0], ['url' => 'https://w.example/', 'interval_hours' => 9999]] as $bad) { eq($post($bad)['status'], 400, json_encode($bad)); }
    $r = $post(['url' => 'https://w.example/', 'project_id' => $sid, 'label' => 'Home', 'interval_hours' => 6]); eq($r['status'], 201, $r['body']); $id = (int)$r['json']['data']['id'];
    eq($post(['url' => 'https://w.example/', 'project_id' => $sid])['status'], 409, 'duplicate'); eq($post(['url' => 'https://w.example/', 'project_id' => 0])['status'], 201, 'same URL under another scope is its own item');
    Config::set('watch.max_items', 2); eq($post(['url' => 'https://w.example/other'])['status'], 409, 'cap'); Config::set('watch.max_items', 100);
    for ($i = 0; $i < 3; $i++) { Watchlist::handleJob(['watch_id' => $id]); }
    $l = call(WatchController::class, 'list', ['query' => ['project_id' => (string)$sid]])['json']['data']['items'];
    eq(count($l), 1); eq([$l[0]['label'], $l[0]['interval_hours'], $l[0]['last_status'], $l[0]['site']], ['Home', 6, 'ok', 'Corner']); eq(count($l[0]['trend']), 3);
    $h = call(WatchController::class, 'history', ['query' => []], ['id' => (string)$id])['json']['data']['points']; eq(count($h), 3); ok(isset($h[0]['at'], $h[0]['score'], $h[0]['load_time'], $h[0]['word_count']));
    eq(call(WatchController::class, 'update', ['method' => 'PATCH', 'body' => ['enabled' => false, 'interval_hours' => 72]], ['id' => (string)$id])['status'], 200);
    $row = $db->fetchOne('SELECT enabled, interval_hours FROM sw_watch_items WHERE id = :i', ['i' => $id]); eq([(int)$row['enabled'], (int)$row['interval_hours']], [0, 72]);
    $run = call(WatchController::class, 'run', ['method' => 'POST'], ['id' => (string)$id]); eq($run['status'], 202);
    eq(call(WatchController::class, 'delete', ['method' => 'DELETE'], ['id' => (string)$id])['status'], 200);
    eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_seo_analysis WHERE watch_id = :i', ['i' => $id])['n'], 0); eq(call(WatchController::class, 'delete', ['method' => 'DELETE'], ['id' => (string)$id])['status'], 404);
});
test('new alert events are in the built-in list and offered in Settings', function () {
    foreach (['watch_regression', 'watch_down', 'watch_recovered'] as $e) {
        ok(in_array($e, Config::defaults()['alerts']['events'], true), "$e in defaults");
        ok(in_array($e, \Sitewright\Core\Settings::registry()['alerts.events']['enum'], true), "$e in Settings");
    }
});
