<?php
use Sitewright\Core\{AnalysisException, Config, Jobs, Logger, PageSpeed, PageSpeedController, Watchlist};

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb();
$API = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
$psi = fn (array $o = []) => json_encode(array_replace_recursive(['lighthouseResult' => ['categories' => ['performance' => ['score' => 0.87]], 'audits' => [
    'largest-contentful-paint' => ['numericValue' => 2412.6], 'cumulative-layout-shift' => ['numericValue' => 0.0453], 'total-blocking-time' => ['numericValue' => 130.2], 'first-contentful-paint' => ['numericValue' => 1210.4], 'server-response-time' => ['numericValue' => 310.9]]],
    'loadingExperience' => ['overall_category' => 'AVERAGE', 'metrics' => ['LARGEST_CONTENTFUL_PAINT_MS' => ['percentile' => 2800], 'CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['percentile' => 12], 'INTERACTION_TO_NEXT_PAINT' => ['percentile' => 190]]]], $o));
$reset = function () use ($db) { foreach (['pagespeed_runs', 'watch_items', 'jobs', 'seo_analysis'] as $t) { $db->query("TRUNCATE sw_$t"); } Config::set('pagespeed', ['enabled' => true, 'api_key' => '', 'strategy' => 'mobile', 'watch_days' => 7]); };
$last = fn () => $GLOBALS['__calls'][count($GLOBALS['__calls']) - 1];

test('parse: score, lab metrics and field data come out as whole numbers (ms), 0-100 score and a layout shift score', function () use ($psi) {
    $r = PageSpeed::parse(json_decode($psi(), true));
    eq([$r['perf_score'], $r['lcp_ms'], $r['cls'], $r['tbt_ms'], $r['fcp_ms'], $r['ttfb_ms']], [87, 2413, 0.045, 130, 1210, 311]);
    eq([$r['field_category'], $r['field_lcp_ms'], $r['field_cls'], $r['field_inp_ms']], ['AVERAGE', 2800, 0.12, 190]);
    $e = PageSpeed::parse(['lighthouseResult' => ['categories' => []]]); eq([$e['perf_score'], $e['lcp_ms'], $e['field_category']], [null, null, null]);
});
test('run: off by default and refuses; private addresses are never sent to Google', function () use ($db, $reset) {
    $reset(); Config::set('pagespeed.enabled', false); fakeWeb([]);
    $e = throws(AnalysisException::class, fn () => PageSpeed::run('https://a.example/')); eq($e->httpStatus, 409); eq(count($GLOBALS['__calls']), 0);
    Config::set('pagespeed.enabled', true); fakeWeb([], ['internal.example' => ['10.0.0.5']]);
    $e = throws(AnalysisException::class, fn () => PageSpeed::run('https://internal.example/')); eq($e->httpStatus, 400); eq(count($GLOBALS['__calls']), 0, 'nothing leaves the server');
});
test('run: asks Google for the performance category with the chosen strategy, keeps the API key in a header (never the URL), stores the result', function () use ($db, $reset, $psi, $API, $last) {
    $reset(); Config::set('pagespeed.api_key', 'KEY-SECRET-123'); Config::set('pagespeed.strategy', 'desktop');
    fakeWeb([$API . '?url=' . rawurlencode('https://a.example/') . '&strategy=desktop&category=performance' => [200, [], $psi()]]);
    $logFile = tempnam(sys_get_temp_dir(), 'swlog'); Config::set('log.file', $logFile); Config::set('log.level', 'debug');
    $r = PageSpeed::run('https://a.example/', 3, 9);
    eq([$r['status'], $r['perf_score'], $r['strategy']], ['ok', 87, 'desktop']);
    $c = $last(); ok(in_array('X-Goog-Api-Key: KEY-SECRET-123', $c['headers'], true), 'key in a header'); ok(!str_contains($c['url'], 'KEY-SECRET-123'), 'key not in the URL');
    $row = $db->fetchOne('SELECT * FROM sw_pagespeed_runs'); eq([(int)$row['project_id'], (int)$row['watch_id'], (int)$row['perf_score']], [3, 9, 87]); ok(!str_contains(json_encode($row), 'KEY-SECRET'), 'key not stored');
    ok(!str_contains((string)file_get_contents($logFile), 'KEY-SECRET-123'), 'key not logged'); Config::set('log.file', '/dev/null'); @unlink($logFile); Config::set('pagespeed.api_key', '');
});
test('run: Google rate limits, errors and garbage are RECORDED on the row and never thrown (so they cannot fail jobs or the health check)', function () use ($db, $reset, $API) {
    $reset(); $u = 'https://a.example/';
    foreach ([[429, 'rate_limited', 'quota'], [500, 'error', 'HTTP 500'], [400, 'error', 'HTTP 400'], [200, 'error', 'unexpected']] as [$status, $want, $needle]) {
        fakeWeb([$API . '?url=' . rawurlencode($u) . '&strategy=mobile&category=performance' => [$status, [], $status === 200 ? 'not json' : '{"error":{"message":"x"}}']]);
        $r = PageSpeed::run($u); eq($r['status'], $want); ok(stripos((string)$r['error'], $needle) !== false, (string)$r['error']);
    }
    fakeWeb([$API . '?url=' . rawurlencode($u) . '&strategy=mobile&category=performance' => new \Sitewright\Core\FetchTimeoutException('timed out')]);
    eq(PageSpeed::run($u)['status'], 'error'); eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_pagespeed_runs')['n'], 5);
});
test('job: a measurement job finishes as done even when Google refuses', function () use ($db, $reset, $API) {
    $reset(); fakeWeb([$API . '?url=' . rawurlencode('https://a.example/') . '&strategy=mobile&category=performance' => [429, [], '{}']]);
    Jobs::enqueue('pagespeed', ['url' => 'https://a.example/', 'project_id' => 0]); while (Jobs::runNext()) {}
    eq($db->fetchOne("SELECT status FROM sw_jobs WHERE type = 'pagespeed'")['status'], 'done'); eq($db->fetchOne('SELECT status FROM sw_pagespeed_runs')['status'], 'rate_limited');
});
test('watchlist: an item with Web Vitals on queues one weekly measurement (no user id) after a good check; not when off, disabled in Settings, or measured recently', function () use ($db, $reset, $psi, $API) {
    $reset(); $id = (int)$db->insert('watch_items', ['url' => 'https://w.example/', 'project_id' => 0, 'pagespeed' => 1]);
    fakeWeb(['https://w.example/' => [200, ['Content-Type' => 'text/html'], '<html><head><title>T</title></head><body><h1>x</h1></body></html>']]);
    $count = fn () => (int)$db->fetchOne("SELECT COUNT(*) n FROM sw_jobs WHERE type = 'pagespeed'")['n'];
    Watchlist::handleJob(['watch_id' => $id]); eq($count(), 1); $p = json_decode($db->fetchOne("SELECT payload FROM sw_jobs WHERE type = 'pagespeed'")['payload'], true); eq([$p['url'], $p['watch_id']], ['https://w.example/', $id]);
    eq($db->fetchOne("SELECT user_id FROM sw_jobs WHERE type = 'pagespeed'")['user_id'], null);
    $db->query('TRUNCATE sw_jobs'); $db->insert('pagespeed_runs', ['watch_id' => $id, 'url' => 'https://w.example/', 'perf_score' => 80]); Watchlist::handleJob(['watch_id' => $id]); eq($count(), 0, 'measured recently');
    $db->query('UPDATE sw_pagespeed_runs SET fetched_at = NOW() - INTERVAL 8 DAY'); Watchlist::handleJob(['watch_id' => $id]); eq($count(), 1, 'a week has passed');
    $db->query('TRUNCATE sw_jobs'); $db->query('TRUNCATE sw_pagespeed_runs'); Config::set('pagespeed.enabled', false); Watchlist::handleJob(['watch_id' => $id]); eq($count(), 0, 'off in Settings'); Config::set('pagespeed.enabled', true);
    $db->query('UPDATE sw_watch_items SET pagespeed = 0'); Watchlist::handleJob(['watch_id' => $id]); eq($count(), 0, 'not switched on for this page');
});
test('PageSpeed API: status tells the UI whether to offer it; run queues a job; history lists runs newest first; off means 409', function () use ($db, $reset, $psi, $API) {
    $reset(); $c = fn (string $m, array $req = []) => call(PageSpeedController::class, $m, $req);
    $s = $c('status')['json']['data']; eq([$s['enabled'], $s['strategy'], $s['has_key']], [true, 'mobile', false]); ok(!str_contains(json_encode($s), 'KEY'));
    eq($c('run', ['method' => 'POST', 'body' => ['url' => 'ftp://x']])['status'], 400);
    $r = $c('run', ['method' => 'POST', 'body' => ['url' => 'https://a.example/', 'project_id' => 0]]); eq($r['status'], 202); ok($r['json']['data']['job_id'] > 0);
    fakeWeb([$API . '?url=' . rawurlencode('https://a.example/') . '&strategy=mobile&category=performance' => [200, [], $psi()]]); while (Jobs::runNext()) {}
    $h = $c('history', ['query' => ['url' => 'https://a.example/']])['json']['data']['runs']; eq(count($h), 1); eq([$h[0]['status'], $h[0]['perf_score']], ['ok', 87]);
    Config::set('pagespeed.enabled', false); eq($c('run', ['method' => 'POST', 'body' => ['url' => 'https://a.example/']])['status'], 409);
    eq($c('status')['json']['data']['enabled'], false);
});
test('watch API: Web Vitals can be switched on when adding or later, and the list carries the newest measurement', function () use ($db, $reset) {
    $reset(); $w = fn (string $m, array $req, array $p = []) => call(\Sitewright\Core\WatchController::class, $m, $req + ['method' => 'POST'], $p);
    $r = $w('create', ['body' => ['url' => 'https://w.example/', 'pagespeed' => true]]); eq($r['status'], 201); $id = (int)$r['json']['data']['id'];
    $item = fn () => call(\Sitewright\Core\WatchController::class, 'list')['json']['data']['items'][0];
    eq([$item()['pagespeed'], $item()['psi']], [true, null]);
    $db->insert('pagespeed_runs', ['watch_id' => $id, 'url' => 'https://w.example/', 'perf_score' => 70, 'lcp_ms' => 3000, 'cls' => 0.05, 'fetched_at' => gmdate('Y-m-d H:i:s', strtotime('-2 days'))]);
    $db->insert('pagespeed_runs', ['watch_id' => $id, 'url' => 'https://w.example/', 'perf_score' => 82, 'lcp_ms' => 2100, 'cls' => 0.04, 'status' => 'ok']);
    $db->insert('pagespeed_runs', ['watch_id' => $id, 'url' => 'https://w.example/', 'status' => 'rate_limited', 'error' => 'quota', 'fetched_at' => gmdate('Y-m-d H:i:s', strtotime('+1 minute'))]);
    $p = $item()['psi']; eq([$p['perf_score'], $p['lcp_ms']], [82, 2100]);   // the newest SUCCESSFUL measurement; a refused one does not blank it
    eq($w('update', ['method' => 'PATCH', 'body' => ['pagespeed' => false]], ['id' => (string)$id])['status'], 200); eq($item()['pagespeed'], false);
});
