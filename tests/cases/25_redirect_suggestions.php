<?php
use Sitewright\Core\{RedirectSuggester, SuggestionController};
use Sitewright\Modules\Redirections\RedirectionsController;

$cand = fn (array $paths) => array_map(fn ($p) => is_array($p) ? $p : ['path' => $p, 'title' => null], $paths);
$top = fn (string $p, array $c, int $n = 3) => RedirectSuggester::suggest($p, $cand($c), $n);

test('suggest: same address apart from case, trailing slash, file extension or query is a perfect match', function () use ($top) {
    foreach (['/About-Us.html', '/about-us/', '/ABOUT-US?utm=x', '/about-us.php'] as $p) { $s = $top($p, ['/contact', '/about-us']); eq([$s[0]['target'], $s[0]['score']], ['/about-us', 100], $p); }
    eq($top('/about-us', ['/about-us']), [], 'a candidate identical to the 404 itself is never offered');
});
test('suggest: same page name in another folder beats a merely similar one; unrelated pages get nothing', function () use ($top) {
    $s = $top('/old/products/blue-widget', ['/products/red-widget', '/products/blue-widget', '/about']);
    eq($s[0]['target'], '/products/blue-widget'); eq($s[0]['score'], 85); ok(str_contains($s[0]['reason'], 'different folder'));
    eq($top('/team/jane-doe', ['/about/team', '/contact', '/pricing']), []);
});
test('suggest: similar page names match even with extra words, years and filler words', function () use ($top) {
    foreach ([['/blog/summer-sale-2023', '/summer-sale'], ['/the-best-widgets-2021', '/best-widgets'], ['/our-services-and-pricing', '/services-pricing']] as [$p, $want]) {
        $s = $top($p, ['/unrelated', $want, '/another-page']); eq($s[0]['target'], $want, $p); ok($s[0]['score'] >= 55 && $s[0]['score'] <= 90, "{$s[0]['score']}");
    }
});
test('suggest: a page title can help a short slug match, and the best three come back sorted, ties broken by shorter path', function () use ($top, $cand) {
    $s = RedirectSuggester::suggest('/pricing-plans', $cand([['path' => '/p/17', 'title' => 'Pricing plans for teams'], ['path' => '/p/18', 'title' => 'About us']]), 3);
    eq($s[0]['target'], '/p/17'); eq(count($s), 1);
    $s = $top('/summer-sale-offer', ['/blog/summer-sale-offer-details', '/summer-sale', '/summer-sale-offer-old', '/summer'], 3); ok($s[0]['score'] >= $s[1]['score'] && $s[1]['score'] >= $s[2]['score']); eq(count($s), 3);
});
test('suggest: with no good match the closest section page is offered as a low-confidence fallback; the home page never is', function () use ($top) {
    $s = $top('/blog/old-post-about-nothing', ['/blog', '/shop', '/']); eq([$s[0]['target'], $s[0]['score']], ['/blog', 40]); ok(str_contains($s[0]['reason'], 'section'));
    eq($top('/zzz/qqq', ['/', '/blog']), []);
});
test('suggest: hostile and odd input is handled (empty, query only, percent-encoding, very long, unicode)', function () use ($top) {
    foreach (['/', '/?x=1', '/%E2%9C%93-check', '/' . str_repeat('a-', 400), "/caf\u{e9}-menu", '/<script>alert(1)</script>'] as $p) { $s = $top($p, ['/menu', '/check', '/cafe-menu']); ok(is_array($s)); foreach ($s as $x) { ok(str_starts_with($x['target'], '/')); } }
    eq($top('/%E2%9C%93-check', ['/check'])[0]['target'], '/check');
});

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb();
$reset = function () use ($db) { foreach (['404_monitor', 'redirections', 'audits', 'audit_pages', 'sitemaps'] as $t) { $db->query("TRUNCATE sw_$t"); } };
$list = fn (array $q = []) => call(SuggestionController::class, 'redirects', ['query' => $q])['json']['data'];

test('suggestions API: no inventory yet says so (run an audit or crawl) and offers nothing', function () use ($db, $reset, $list) {
    $reset(); $db->insert('404_monitor', ['project_id' => 1, 'uri' => '/old', 'hits' => 5]);
    $d = $list(['project_id' => '1']); eq([$d['has_inventory'], $d['items']], [false, []]);
});
test('suggestions API: uses the newest finished audit of THAT site plus its sitemap list; skips 404s that already have a rule; most-hit first; other sites are separate', function () use ($db, $reset, $list) {
    $reset();
    $a = (int)$db->insert('audits', ['project_id' => 1, 'base_url' => 'https://a.example', 'status' => 'done']);
    $old = (int)$db->insert('audits', ['project_id' => 1, 'base_url' => 'https://a.example', 'status' => 'failed']);
    foreach ([['https://a.example/products/blue-widget', 'Blue widget', 0], ['https://a.example/about-us', 'About us', 0], ['https://a.example/hidden', 'Hidden', 1]] as [$u, $t, $ni]) { $db->insert('audit_pages', ['audit_id' => $a, 'url' => $u, 'title' => $t, 'noindex' => $ni]); }
    $db->insert('sitemaps', ['project_id' => 1, 'type' => 'general', 'url' => 'https://a.example/services']); $db->insert('sitemaps', ['project_id' => 2, 'type' => 'general', 'url' => 'https://b.example/old-stuff-b']);
    foreach ([['/old/products/blue-widget', 9], ['/About-Us.html', 3], ['/our-services', 7], ['/already-handled', 50], ['/hidden', 4], ['/nothing-like-it', 2]] as [$u, $h]) { $db->insert('404_monitor', ['project_id' => 1, 'uri' => $u, 'hits' => $h]); }
    $db->insert('404_monitor', ['project_id' => 2, 'uri' => '/old-stuff', 'hits' => 100]);
    $db->insert('redirections', ['project_id' => 1, 'source_url' => '/already-handled', 'target_url' => '/about-us']);
    $d = $list(['project_id' => '1']);
    eq($d['has_inventory'], true); eq($d['source'], 'audit');
    eq(array_column($d['items'], 'uri'), ['/old/products/blue-widget', '/our-services', '/About-Us.html'], 'most hits first; handled, noindex-only and unmatched ones are left out');
    eq($d['items'][0]['suggestions'][0]['target'], '/products/blue-widget'); eq($d['items'][2]['suggestions'][0]['score'], 100); eq($d['items'][1]['suggestions'][0]['target'], '/services');
    eq($d['unmatched'], 2, '/hidden (only a noindex page matches) and /nothing-like-it');
    $all = $list(); ok(in_array('/old-stuff', array_column($all['items'], 'uri'), true), 'All sites: site 2 is matched against its own sitemap');
    foreach ($all['items'] as $i) { foreach ($i['suggestions'] as $s) { ok($i['project_id'] === 2 ? str_contains($s['target'], 'old-stuff') : !str_contains($s['target'], 'old-stuff'), 'no cross-site suggestion'); } }
});
test('an accepted suggestion becomes a normal 301 rule through the existing redirect API', function () use ($db, $reset) {
    $reset(); $r = call(RedirectionsController::class, 'add', ['method' => 'POST', 'body' => ['source_url' => '/About-Us.html', 'target_url' => '/about-us', 'redirect_type' => '301', 'project_id' => 1]]);
    eq($r['status'], 201, $r['body']); eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_redirections')['n'], 1);
});
