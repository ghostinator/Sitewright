<?php
use RankMathWebapp\Core\{Config, Database, DatabaseException, Validator};
use RankMathWebapp\Modules\Monitor\MonitorController as Mon;
use RankMathWebapp\Modules\Redirections\RedirectionsController as Red;
use RankMathWebapp\Modules\Analytics\AnalyticsController as An;

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb();

// ---- Database identifier safety (ARCH-005) ----------------------------------------------------
test('Database: malicious column/table identifiers are rejected, values stay bound', function () use ($db) {
    throws(DatabaseException::class, fn () => $db->insert('redirections', ['source_url`) VALUES (1); DROP TABLE rm_users;--' => 'x']));
    throws(DatabaseException::class, fn () => $db->update('redirections', ['target_url' => 'x'], ['id = 1 OR 1=1' => 1]));
    throws(DatabaseException::class, fn () => $db->update('redirections', ['target_url' => 'x'], []));
    throws(DatabaseException::class, fn () => $db->delete('redirections', []));
    throws(DatabaseException::class, fn () => $db->t('x; DROP TABLE y'));
    $db->insert('redirections', ['source_url' => "/a'; DROP TABLE rm_users;--", 'target_url' => '/b']);
    eq((int)$db->fetchOne('SELECT COUNT(*) c FROM rm_redirections')['c'], 1);
    $db->query('SELECT 1 FROM rm_users');
});
test('Database: LIMIT/OFFSET bind as integers under native prepares (INFO-003)', function () use ($db) {
    $db->insert('404_monitor', ['uri' => '/l1']); $db->insert('404_monitor', ['uri' => '/l2']);
    eq(count($db->fetchAll('SELECT id FROM rm_404_monitor ORDER BY id LIMIT :l OFFSET :o', ['l' => 1, 'o' => 1])), 1);
});

// ---- 404 log -------------------------------------------------------------------------------------
test('404 log: null-safe atomic dedupe and hit counting (FUNC-005, DB-001)', function () use ($db) {
    $db->query('DELETE FROM rm_404_monitor');
    foreach (range(1, 3) as $_) { eq(call(Mon::class, 'log404', ['method' => 'POST', 'body' => ['uri' => '/gone']])['status'], 200); }
    $rows = $db->fetchAll("SELECT hits FROM rm_404_monitor WHERE uri = '/gone'");
    eq(count($rows), 1); eq((int)$rows[0]['hits'], 3);
    eq(call(Mon::class, 'log404', ['method' => 'POST', 'body' => ['uri' => '/gone', 'project_id' => 5]])['status'], 200);
    eq((int)$db->fetchOne("SELECT COUNT(*) c FROM rm_404_monitor WHERE uri = '/gone'")['c'], 2, 'separate project keeps its own row');
    foreach ([['uri' => 'no-slash'], ['uri' => ''], ['uri' => '/x', 'ip_address' => 'not-an-ip'], ['uri' => str_repeat('a', 1001)]] as $bad) {
        eq(call(Mon::class, 'log404', ['method' => 'POST', 'body' => $bad])['status'], 400);
    }
});
test('404 log: clear requires confirmation and honours project_id; DELETE not TRUNCATE (FUNC-006)', function () use ($db) {
    eq(call(Mon::class, 'clearLogs', ['method' => 'POST', 'body' => []])['status'], 400);
    $r = call(Mon::class, 'clearLogs', ['method' => 'POST', 'body' => ['confirm' => true, 'project_id' => 5]]);
    eq($r['json']['data']['deleted'], 1); eq((int)$db->fetchOne('SELECT COUNT(*) c FROM rm_404_monitor')['c'] > 0, true);
    call(Mon::class, 'clearLogs', ['method' => 'POST', 'body' => ['confirm' => true]]);
    eq((int)$db->fetchOne('SELECT COUNT(*) c FROM rm_404_monitor')['c'], 0);
});
test('404 log: CSV export neutralises formula injection (SEC-012)', function () use ($db) {
    foreach (['/=HYPERLINK("http://x")', '/+1', '/-2', '/@SUM(1)'] as $u) { call(Mon::class, 'log404', ['method' => 'POST', 'body' => ['uri' => $u, 'referer' => "=cmd|' /C calc'!A0"]]); }
    $csv = call(Mon::class, 'exportCSV', [])['body'];
    ok(str_contains($csv, "'=cmd|") , 'referer neutralised');
    foreach (explode("\n", trim($csv)) as $i => $line) { if ($i) { $cells = str_getcsv($line, ',', '"', ''); ok(!preg_match('/^[=+\-@\t\r]/', $cells[2] ?? ''), "cell: $line"); } }
    eq(Validator::csvCell('=1+1'), "'=1+1"); eq(Validator::csvCell("\tx"), "'\tx"); eq(Validator::csvCell('safe'), 'safe');
});
test('404 log: retention purge removes old rows only (PRIV-003)', function () use ($db) {
    $db->query('DELETE FROM rm_404_monitor');
    $db->insert('404_monitor', ['uri' => '/old', 'last_accessed' => gmdate('Y-m-d H:i:s', strtotime('-200 days'))]);
    $db->insert('404_monitor', ['uri' => '/new']);
    Config::set('retention_days_404', 90);
    eq((new Mon())->purgeExpired(), 1);
    eq(array_column($db->fetchAll('SELECT uri FROM rm_404_monitor'), 'uri'), ['/new']);
});

// ---- Redirect rule manager -----------------------------------------------------------------------
test('Redirections: validation rejects bad types, injection characters and open-redirect style targets (DB-004)', function () {
    $good = ['source_url' => '/old', 'target_url' => '/new', 'redirect_type' => '301'];
    foreach ([['redirect_type' => '999'], ['redirect_type' => "301\n"], ['source_url' => 'old'], ['source_url' => '//evil.example/x'], ['source_url' => "/a\nRedirect 301 / http://evil"], ['source_url' => '/a b'], ['source_url' => '/a"b'],
              ['source_url' => '/a?x=1'], ['target_url' => '//evil.example'], ['target_url' => '\\\\evil'], ['target_url' => 'javascript:alert(1)'], ['target_url' => "/x\r\nSet-Cookie: a=b"], ['target_url' => 'https://ok.example/$1;'],
              ['status' => 'maybe'], ['target_url' => '']] as $over) {
        eq(call(Red::class, 'add', ['method' => 'POST', 'body' => $over + $good])['status'], 400, json_encode($over));
    }
});
test('Redirections: add/duplicate(409)/update/delete + 410 needs no target', function () use ($db) {
    $db->query('DELETE FROM rm_redirections');
    $r = call(Red::class, 'add', ['method' => 'POST', 'body' => ['source_url' => '/old', 'target_url' => '/new']]);
    eq($r['status'], 201);
    eq(call(Red::class, 'add', ['method' => 'POST', 'body' => ['source_url' => '/old', 'target_url' => '/other']])['status'], 409);
    eq(call(Red::class, 'add', ['method' => 'POST', 'body' => ['source_url' => '/removed', 'redirect_type' => '410']])['status'], 201);
    eq(call(Red::class, 'update', ['method' => 'PUT', 'body' => ['target_url' => 'https://example.com/x']], ['id' => (string)$r['json']['data']['id']])['status'], 200);
    eq(call(Red::class, 'checkRedirect', ['query' => ['url' => '/old']])['json']['data']['target_url'], 'https://example.com/x');
    eq(call(Red::class, 'delete', ['method' => 'DELETE'], ['id' => (string)$r['json']['data']['id']])['status'], 200);
    eq(call(Red::class, 'delete', ['method' => 'DELETE'], ['id' => '999999'])['status'], 404);
});
test('Redirections: Apache / nginx / CSV export are well-formed and safe (FUNC-004)', function () use ($db) {
    $db->query('DELETE FROM rm_redirections');
    foreach ([['/a.html', '/b', '301'], ['/c', 'https://example.com/d?x=1', '302'], ['/gone', '', '410'], ['/legal', '', '451']] as [$s, $t, $c]) {
        call(Red::class, 'add', ['method' => 'POST', 'body' => ['source_url' => $s, 'target_url' => $t, 'redirect_type' => $c]]);
    }
    $ap = call(Red::class, 'export', ['query' => ['format' => 'apache']])['body'];
    ok(str_contains($ap, 'RedirectMatch 301 "^/a\.html$" "/b"') && str_contains($ap, 'RedirectMatch gone "^/gone$"') && str_contains($ap, '# 451'), $ap);
    $ng = call(Red::class, 'export', ['query' => ['format' => 'nginx']])['body'];
    ok(str_contains($ng, 'location = "/a.html" { return 301 "/b"; }') && str_contains($ng, 'location = "/gone" { return 410; }'), $ng);
    $csv = call(Red::class, 'export', ['query' => ['format' => 'csv']])['body'];
    ok(str_starts_with($csv, 'source_url,target_url,redirect_type'));
    eq(call(Red::class, 'export', ['query' => ['format' => 'evil']])['status'], 400);
});
test('Redirections: CSV import validates rows, caps size, is transactional and idempotent (DOS-001)', function () use ($db) {
    $db->query('DELETE FROM rm_redirections');
    $tmp = tempnam(sys_get_temp_dir(), 'csv'); file_put_contents($tmp, "source,target,type\n/a,/b,301\n/a,/c,302\nbad,/x,301\n/z,javascript:x,301\n/ok,https://e.example/,308\n");
    $f = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => filesize($tmp)];
    $r = call(Red::class, 'importCSV', ['method' => 'POST', 'files' => ['file' => $f]]);
    eq($r['json']['data']['imported'], 3); eq(count($r['json']['data']['errors']), 2);
    eq((int)$db->fetchOne('SELECT COUNT(*) c FROM rm_redirections')['c'], 2, '/a upserted once + /ok');
    Config::set('limits.import_rows', 2);
    eq(call(Red::class, 'importCSV', ['method' => 'POST', 'files' => ['file' => $f]])['status'], 413);
    Config::set('limits.import_rows', 5000); unlink($tmp);
    eq(call(Red::class, 'importCSV', ['method' => 'POST'])['status'], 400);
});

// ---- Analytics --------------------------------------------------------------------------------------
test('Analytics: validated, idempotent upsert; import capped and atomic (FUNC-013, DOS-001)', function () use ($db) {
    $ok = ['keyword' => 'seo tips', 'date' => '2026-01-05', 'impressions' => 100, 'clicks' => 7, 'position' => 4.5, 'ctr' => 7.0];
    eq(call(An::class, 'addKeyword', ['method' => 'POST', 'body' => $ok])['status'], 201);
    eq(call(An::class, 'addKeyword', ['method' => 'POST', 'body' => ['impressions' => 250] + $ok])['status'], 201);
    $rows = $db->fetchAll("SELECT impressions FROM rm_analytics_keywords WHERE keyword = 'seo tips'");
    eq(count($rows), 1); eq((int)$rows[0]['impressions'], 250);
    foreach ([['date' => '2026-13-45'], ['date' => 'yesterday'], ['keyword' => ''], ['impressions' => -1], ['clicks' => 'many'], ['position' => 5000], ['ctr' => 101]] as $bad) {
        eq(call(An::class, 'addKeyword', ['method' => 'POST', 'body' => $bad + $ok])['status'], 400, json_encode($bad));
    }
    Config::set('limits.import_rows', 3);
    $items = array_map(fn ($i) => ['keyword' => "k$i", 'date' => '2026-01-05'], range(1, 4));
    eq(call(An::class, 'importKeywords', ['method' => 'POST', 'body' => ['keywords' => $items]])['status'], 413);
    Config::set('limits.import_rows', 5000);
    $before = (int)$db->fetchOne('SELECT COUNT(*) c FROM rm_analytics_keywords')['c'];
    eq(call(An::class, 'importKeywords', ['method' => 'POST', 'body' => ['keywords' => [['keyword' => 'good', 'date' => '2026-01-05'], ['keyword' => 'bad', 'date' => 'nope']]]])['status'], 400);
    eq((int)$db->fetchOne('SELECT COUNT(*) c FROM rm_analytics_keywords')['c'], $before, 'invalid item rejects the whole import');
    eq(call(An::class, 'importKeywords', ['method' => 'POST', 'body' => ['keywords' => $items]])['status'], 200);
});
test('Dashboard: SEO score uses site analyses only; competitor and sample data are distinguishable (FUNC-017)', function () use ($db) {
    $db->query('DELETE FROM rm_seo_analysis');
    $db->insert('seo_analysis', ['url' => 'https://me.example/', 'analysis_type' => 'site', 'score' => 80, 'results' => '{}']);
    $db->insert('seo_analysis', ['url' => 'https://rival.example/', 'analysis_type' => 'competitor', 'score' => 20, 'results' => '{}']);
    $d = call(An::class, 'getDashboardStats', [])['json']['data'];
    eq($d['seo_score'], 80); eq($d['recent_activity'][0]['type'] !== '', true);
    $db->query('DELETE FROM rm_seo_analysis');
    eq(call(An::class, 'getDashboardStats', [])['json']['data']['seo_score'], null);
});
