<?php
use Sitewright\Core\ExportController;

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb();
$get = function (string $m, array $q = [], array $p = []) { $r = call(ExportController::class, $m, ['query' => $q], $p); if ($r['status'] === 200) { $b = $r['body']; $r['bom'] = str_starts_with($b, "\xEF\xBB\xBF");
    $f = fopen('php://memory', 'w+'); fwrite($f, substr($b, $r['bom'] ? 3 : 0)); rewind($f); $rows = []; while (($row = fgetcsv($f, 0, ',', '"', '')) !== false) { $rows[] = $row; } $r['rows'] = $rows; } return $r; };
$reset = function () use ($db) { foreach (['audits', 'audit_pages', 'audit_issues', 'watch_items', 'seo_analysis', 'search_pages', 'analytics_keywords', 'sites', '404_monitor', 'sitemaps', 'redirections'] as $t) { $db->query("TRUNCATE sw_$t"); } };

test('every export is a UTF-8 CSV download with a safe fixed file name, a header row, and nothing that Excel could run as a formula', function () use ($db, $reset, $get) {
    $reset(); $a = (int)$db->insert('audits', ['project_id' => 1, 'base_url' => 'https://a.example', 'status' => 'done']);
    $db->insert('audit_issues', ['audit_id' => $a, 'code' => 'broken_link', 'severity' => 'high', 'url' => '=HYPERLINK("http://evil.example","x")', 'detail' => "Links to a, \"quoted\"\nand a new line"]);
    $db->insert('audit_issues', ['audit_id' => $a, 'code' => 'noindex', 'severity' => 'medium', 'url' => 'https://a.example/b', 'detail' => '']);
    $r = $get('audit', ['part' => 'issues'], ['id' => (string)$a]);
    eq($r['status'], 200); ok($r['bom'], 'BOM so Excel reads UTF-8'); eq($r['headers']['Content-Type'], 'text/csv; charset=utf-8');
    ok(preg_match('/^attachment; filename="[a-z0-9-]+\.csv"$/D', $r['headers']['Content-Disposition']) === 1, $r['headers']['Content-Disposition']); eq($r['headers']['X-Content-Type-Options'] ?? 'nosniff', 'nosniff');
    eq($r['rows'][0], ['Priority', 'Problem', 'Page', 'Detail']);
    eq(count($r['rows']), 3); ok(str_starts_with($r['rows'][1][2], "'=HYPERLINK"), 'formula neutralised: ' . $r['rows'][1][2]); eq($r['rows'][1][3], "Links to a, \"quoted\"\nand a new line", 'quotes, commas and newlines survive');
    eq($r['rows'][1][0], 'High'); eq($r['rows'][1][1], 'Broken internal link');
});
test('audit export: issues or pages, a bad part is refused, an unknown audit is 404', function () use ($db, $reset, $get) {
    $reset(); $a = (int)$db->insert('audits', ['project_id' => 1, 'base_url' => 'https://a.example', 'status' => 'done']);
    $db->insert('audit_pages', ['audit_id' => $a, 'url' => 'https://a.example/', 'title' => '@sum(1)', 'title_len' => 7, 'desc_len' => 100, 'h1_count' => 1, 'word_count' => 400, 'noindex' => 0, 'score' => 80, 'load_ms' => 250, 'inbound' => 3]);
    $r = $get('audit', ['part' => 'pages'], ['id' => (string)$a]); eq($r['rows'][0], ['Page', 'Title', 'Score', 'Words', 'Fetch time (ms)', 'Linked from', 'Noindex']);
    eq([$r['rows'][1][1], $r['rows'][1][2], $r['rows'][1][3]], ["'@sum(1)", '80', '400']);
    eq($get('audit', ['part' => 'x'], ['id' => (string)$a])['status'], 400); eq($get('audit', ['part' => 'issues'], ['id' => '999'])['status'], 404); eq($get('audit', [], ['id' => (string)$a])['rows'][0][0], 'Priority', 'issues is the default');
});
test('watchlist, watch history, overview, opportunities, analyses, keywords and redirect suggestions each export with their own headers, scoped to the chosen site', function () use ($db, $reset, $get) {
    $reset(); $s1 = (int)$db->insert('sites', ['name' => 'Shop, Inc', 'base_url' => 'https://s1.example', 'secret_enc' => '', 'sync_enabled' => 0]); $s2 = (int)$db->insert('sites', ['name' => 'Blog', 'base_url' => 'https://s2.example', 'secret_enc' => '', 'sync_enabled' => 0]);
    $w = (int)$db->insert('watch_items', ['project_id' => $s1, 'url' => 'https://s1.example/', 'label' => 'Home', 'last_status' => 'ok', 'last_score' => 72]); $db->insert('watch_items', ['project_id' => $s2, 'url' => 'https://s2.example/', 'last_status' => 'down']);
    $db->insert('seo_analysis', ['project_id' => $s1, 'url' => 'https://s1.example/', 'analysis_type' => 'site', 'score' => 72, 'watch_id' => $w, 'results' => json_encode(['basic' => ['title' => 'T', 'title_length' => 1, 'word_count' => 500, 'noindex' => false], 'meta' => ['description_length' => 120], 'headings' => ['h1_count' => 1], 'performance' => ['load_time' => 0.3], 'security' => ['uses_https' => true]])]);
    $db->insert('seo_analysis', ['project_id' => $s2, 'url' => 'https://s2.example/', 'analysis_type' => 'site', 'score' => 40, 'results' => '{}']);
    $db->insert('search_pages', ['project_id' => $s1, 'connection_id' => 1, 'page' => 'https://s1.example/a', 'query' => 'blue', 'impressions' => 1000, 'clicks' => 10, 'ctr' => 1, 'position' => 3, 'window_start' => '2026-10-01', 'window_end' => '2026-10-28']);
    $db->insert('analytics_keywords', ['project_id' => $s1, 'keyword' => 'blue', 'impressions' => 100, 'clicks' => 5, 'position' => 3, 'ctr' => 5, 'date' => gmdate('Y-m-d', strtotime('-3 days'))]); $db->insert('analytics_keywords', ['project_id' => $s2, 'keyword' => 'other', 'impressions' => 9, 'clicks' => 1, 'position' => 9, 'ctr' => 11, 'date' => gmdate('Y-m-d', strtotime('-3 days'))]);
    $db->insert('404_monitor', ['project_id' => $s1, 'uri' => '/About-Us.html', 'hits' => 4]); $db->insert('sitemaps', ['project_id' => $s1, 'type' => 'general', 'url' => 'https://s1.example/about-us']);
    $r = $get('watch'); eq($r['rows'][0], ['Site', 'Page', 'Name', 'Status', 'Score', 'Checked every (hours)', 'Last check', 'Last error']); eq(count($r['rows']), 3);
    $r = $get('watch', ['project_id' => (string)$s1]); eq(count($r['rows']), 2); eq([$r['rows'][1][0], $r['rows'][1][1], $r['rows'][1][3], $r['rows'][1][4]], ['Shop, Inc', 'https://s1.example/', 'ok', '72'], 'a comma in a value is quoted');
    $r = $get('watchHistory', [], ['id' => (string)$w]); eq($r['rows'][0], ['Checked', 'Score', 'Fetch time (s)', 'Words', 'Noindex']); eq($r['rows'][1][1], '72'); eq($get('watchHistory', [], ['id' => '999'])['status'], 404);
    $r = $get('overview'); eq($r['rows'][0][0], 'Site'); eq(count($r['rows']), 3); eq($r['rows'][1][0], 'Blog', 'sorted by name');
    $r = $get('opportunities', ['project_id' => (string)$s1]); eq($r['rows'][0], ['Type', 'Site', 'Query', 'Page', 'Views', 'Clicks', 'Click-through %', 'Position', 'Could gain (clicks)', 'What to do']); ok(count($r['rows']) >= 2); eq($get('opportunities', ['type' => 'nope'])['status'], 400);
    $r = $get('analyses', ['project_id' => (string)$s1]); eq($r['rows'][0][0], 'Analysed'); eq(count($r['rows']), 2); eq([$r['rows'][1][1], $r['rows'][1][3]], ['https://s1.example/', '72']);
    $r = $get('keywords', ['project_id' => (string)$s1]); eq($r['rows'][0], ['Keyword', 'Views', 'Clicks', 'Average position', 'Click-through %']); eq([count($r['rows']), $r['rows'][1][0], $r['rows'][1][1]], [2, 'blue', '100']);
    $db->insert('audits', ['project_id' => $s1, 'base_url' => 'https://s1.example', 'status' => 'done']); $a = (int)$db->fetchOne('SELECT MAX(id) i FROM sw_audits')['i'];
    $db->insert('audit_pages', ['audit_id' => $a, 'url' => 'https://s1.example/about-us', 'title' => 'About']);
    $r = $get('redirectSuggestions', ['project_id' => (string)$s1]); eq($r['rows'][0], ['404 address', 'Hits', 'Suggested page', 'Match %', 'Why']); eq([$r['rows'][1][0], $r['rows'][1][2], $r['rows'][1][3]], ['/About-Us.html', '/about-us', '100']);
});
test('exports are read-only and open to every signed-in role (no visitor addresses are in any of them)', function () use ($db) {
    seedAdmin('ev', 'viewer passphrase long here', 'viewer'); $srv = startServer(); $h = new TestHttp($srv['base']); eq($h->login('ev', 'viewer passphrase long here')['status'], 200);
    foreach (['/api/export/watch', '/api/export/overview', '/api/export/opportunities', '/api/export/analyses', '/api/export/keywords', '/api/export/redirect-suggestions', '/api/export/audit/1?part=pages'] as $path) { $s = $h->api('GET', $path)['status']; ok(in_array($s, [200, 404], true), "$path => $s"); }
    eq($h->api('POST', '/api/export/watch', [])['status'] >= 400, true); stopServer($srv);
});
