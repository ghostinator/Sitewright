<?php
use Sitewright\Core\{AnalysisException, Config, Crawler, Jobs, SeoAnalyzer};

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb();
$page = fn (string $title, string $links, string $extra = '') => [200, ['Content-Type' => 'text/html; charset=utf-8'], "<!doctype html><html lang=\"en\"><head><title>$title</title></head><body><h1>$title</h1>$extra $links</body></html>"];

test('SeoAnalyzer::run fetches once, scores, stores with project and watch ids, and returns the saved row id', function () use ($db, $page) {
    $db->query('TRUNCATE sw_seo_analysis');
    fakeWeb(['https://a.example/' => $page('Home page title long enough to be fine here', '<a href="/x">x</a>')]);
    $r = SeoAnalyzer::run('https://a.example/', 7, 'site', 42);
    ok($r['id'] > 0 && $r['score'] >= 0 && $r['score'] <= 100 && $r['url'] === 'https://a.example/' && isset($r['results']['basic']));
    $row = $db->fetchOne('SELECT project_id, watch_id, analysis_type, score FROM sw_seo_analysis WHERE id = :i', ['i' => $r['id']]);
    eq([(int)$row['project_id'], (int)$row['watch_id'], $row['analysis_type'], (int)$row['score']], [7, 42, 'site', $r['score']]);
    eq(count($GLOBALS['__calls']), 1, 'one fetch only');
    $r2 = SeoAnalyzer::run('https://a.example/', 0, 'competitor');
    eq($db->fetchOne('SELECT watch_id, analysis_type FROM sw_seo_analysis WHERE id = :i', ['i' => $r2['id']]), ['watch_id' => null, 'analysis_type' => 'competitor']);
});
test('SeoAnalyzer::run reports an HTTP error page, a non-HTML answer and a blocked address as AnalysisException with the right status', function () use ($db) {
    $db->query('TRUNCATE sw_seo_analysis');
    fakeWeb(['https://gone.example/' => [404, [], 'nope'], 'https://img.example/' => [200, ['Content-Type' => 'image/png'], 'x']], ['internal.example' => ['10.0.0.5']]);
    foreach ([['https://gone.example/', 502, 'HTTP 404'], ['https://img.example/', 422, 'HTML'], ['https://internal.example/', 400, '']] as [$u, $status, $needle]) {
        $e = throws(AnalysisException::class, fn () => SeoAnalyzer::run($u));
        eq($e->httpStatus, $status, $u); ok($needle === '' || str_contains($e->getMessage(), $needle), $e->getMessage());
    }
    eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_seo_analysis')['n'], 0, 'a failed analysis stores nothing');
});
test('Crawler: hooks see every internal page, every internal fetch (including failures), the link lists, and a tick per loop; external links are listed but not fetched', function () use ($page) {
    fakeWeb([
        'https://c.example/robots.txt' => [404, [], ''],
        'https://c.example/' => $page('Home', '<a href="/a">a</a> <a href="/missing">m</a> <a href="https://other.example/x">o</a> <a href="/private/z">p</a>'),
        'https://c.example/a' => $page('A', '<a href="/">home</a> <a href="/a#frag">self</a>'),
        'https://c.example/missing' => [404, [], 'nope'],
    ]);
    $pages = []; $fetches = []; $links = []; $ticks = 0;
    $out = Crawler::run('https://c.example/', 50, microtime(true) + 30, [
        'on_page'  => function (string $u, array $res, \DOMDocument $dom) use (&$pages) { $pages[$u] = $dom->getElementsByTagName('title')->item(0)->textContent; },
        'on_fetch' => function (string $u, ?array $res, ?string $err) use (&$fetches) { $fetches[$u] = $res['status'] ?? $err; },
        'on_links' => function (string $u, array $internal, array $external) use (&$links) { $links[$u] = [$internal, $external]; },
        'on_tick'  => function () use (&$ticks) { $ticks++; },
    ]);
    eq($pages, ['https://c.example/' => 'Home', 'https://c.example/a' => 'A']);
    eq($fetches['https://c.example/missing'], 404); eq($fetches['https://c.example/a'], 200);
    ok(in_array('https://c.example/a', $links['https://c.example/'][0], true) && in_array('https://other.example/x', $links['https://c.example/'][1], true));
    ok(!isset($fetches['https://other.example/x']), 'external link not fetched');
    ok($ticks >= 3, 'tick per loop');
    eq($out['pages'], ['https://c.example/', 'https://c.example/a']); eq($out['error'], null);
});
test('Crawler: robots.txt Disallow is honoured and the page cap stops the crawl early', function () use ($page) {
    fakeWeb([
        'https://d.example/robots.txt' => [200, ['Content-Type' => 'text/plain'], "User-agent: *\nDisallow: /private\n"],
        'https://d.example/' => $page('Home', '<a href="/private/z">p</a> <a href="/a">a</a> <a href="/b">b</a>'),
        'https://d.example/a' => $page('A', ''), 'https://d.example/b' => $page('B', ''), 'https://d.example/private/z' => $page('Z', ''),
    ]);
    $seen = [];
    $out = Crawler::run('https://d.example/', 2, microtime(true) + 30, ['on_page' => function (string $u) use (&$seen) { $seen[] = $u; }]);
    eq(count($seen), 2); ok(!in_array('https://d.example/private/z', $seen, true)); ok($out['stopped_early']);
});
test('Jobs::touch refreshes the running job heartbeat so a long job is not declared stuck; outside a job it does nothing', function () use ($db) {
    Jobs::touch();   // no job running: no error
    $db->query('TRUNCATE sw_jobs');
    Jobs::register('t_touch', function (array $p) use ($db): array {
        $db->query("UPDATE sw_jobs SET heartbeat_at = NOW() - INTERVAL 1 HOUR WHERE status = 'running'");
        Jobs::touch();
        $age = (int)$db->fetchOne("SELECT TIMESTAMPDIFF(SECOND, heartbeat_at, NOW()) s FROM sw_jobs WHERE status = 'running'")['s'];
        return ['message' => 'ok', 'data' => ['age' => $age]];
    });
    Jobs::enqueue('t_touch', []); while (Jobs::runNext()) {}
    $r = json_decode((string)$db->fetchOne('SELECT result FROM sw_jobs')['result'], true);
    ok($r['data']['age'] < 5, 'heartbeat refreshed, age ' . $r['data']['age']);
});
