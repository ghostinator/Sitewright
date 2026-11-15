<?php
use Sitewright\Core\{Config, Database, FetchTimeoutException};
use Sitewright\Modules\SeoAnalysis\SeoAnalysisController as Seo;
use Sitewright\Modules\ImageSeo\ImageSeoController as Img;

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb();
$count = fn (string $t) => (int)$db->fetchOne("SELECT COUNT(*) c FROM sw_$t")['c'];

$page = html(
    '<h1>Hello wörld</h1><p>Un deux trois quatre cinq</p><script>var spam = "one two three four five six seven eight nine ten";</script><style>.a{content:"x y z"}</style>'
    . '<img src="/a.png" alt="A"><img src="/b.png"><a href="/x">in</a><a href="mailto:a@b.c">m</a><a href="https://other.example/">out</a><a href="javascript:void(0)">j</a>',
    '<meta name="description" content="' . str_repeat('d', 130) . '"><meta name="viewport" content="width=device-width"><script type="application/ld+json">{"@type":"Thing"}</script><link rel="canonical" href="https://example.com/">'
);

test('SEO: fetches ONCE, computes metrics from that single response (FUNC-008)', function () use ($page, $db, $count) {
    fakeWeb(['https://example.com/' => $page]);
    $r = call(Seo::class, 'analyze', ['method' => 'POST', 'body' => ['url' => 'https://example.com/']]);
    eq($r['status'], 200); eq(count($GLOBALS['__calls']), 1, 'exactly one outbound request');
    $d = $r['json']['data']['results'];
    eq($d['basic']['word_count'], 11, 'script/style text excluded; unicode counted: Hello wörld (2) + Un deux trois quatre cinq (5) + 4 link labels');
    eq($d['links']['internal_links'], 1); eq($d['links']['external_links'], 1, 'mailto/javascript not counted');
    eq($d['viewport']['has_viewport'], true); eq($d['structured_data']['has_schema'], true); eq($d['security']['uses_https'], true);
    eq($d['images']['alt_ratio'], 50); ok(!array_key_exists('mobile', $d), 'no "mobile friendly" claim');
    eq($count('seo_analysis'), 1);
});
test('SEO: HTTPS is judged from the FINAL url after redirects', function () use ($page) {
    fakeWeb(['http://example.com/' => [301, ['Location' => 'https://example.com/'], ''], 'https://example.com/' => $page]);
    $r = call(Seo::class, 'analyze', ['method' => 'POST', 'body' => ['url' => 'http://example.com/']]);
    eq($r['json']['data']['results']['security']['uses_https'], true); eq($r['json']['data']['results']['fetch']['redirect_count'], 1);
    fakeWeb(['https://example.com/' => [302, ['Location' => 'http://example.com/'], ''], 'http://example.com/' => $page]);
    eq(call(Seo::class, 'analyze', ['method' => 'POST', 'body' => ['url' => 'https://example.com/']])['json']['data']['results']['security']['uses_https'], false);
});
test('SEO: failures return an error, no score and NO database row (SSRF-001, FUNC-008)', function () use ($db, $count) {
    $before = $count('seo_analysis');
    fakeWeb(['https://down.example/' => new FetchTimeoutException('t'), 'https://gone.example/' => [404, [], 'x'], 'https://json.example/' => [200, ['Content-Type' => 'application/json'], '{}']], ['int.example' => ['10.0.0.9']]);
    foreach ([['https://down.example/', 502], ['https://gone.example/', 502], ['https://json.example/', 422], ['http://127.0.0.1/', 400], ['http://int.example/', 400],
              ['file:///etc/passwd', 400], ['gopher://x/', 400], ['http://169.254.169.254/latest/meta-data/', 400]] as [$u, $status]) {
        $r = call(Seo::class, 'analyze', ['method' => 'POST', 'body' => ['url' => $u]]);
        eq($r['status'], $status, $u); ok(!isset($r['json']['data']['score']), "no score for $u");
    }
    eq($count('seo_analysis'), $before);
});
test('SEO: internal-address attempts never reach the transport', function () {
    fakeWeb([]);
    call(Seo::class, 'analyze', ['method' => 'POST', 'body' => ['url' => 'http://10.0.0.1:80/']]);
    eq(count($GLOBALS['__calls']), 0);
});
test('SEO: history clamps limit and validates type', function () {
    eq(call(Seo::class, 'getHistory', ['query' => ['limit' => '999999', 'type' => 'site']])['status'], 200);
    eq(call(Seo::class, 'getHistory', ['query' => ['limit' => 'abc; DROP TABLE x', 'type' => 'site']])['status'], 200);
    eq(call(Seo::class, 'getHistory', ['query' => ['type' => 'x\' OR 1=1']])['status'], 400);
});

// ---- Image SEO -------------------------------------------------------------
$png = base64_decode(PNG_1X1);
test('Image: bytes fetched through the client, measured with getimagesizefromstring', function () use ($png) {
    fakeWeb(['https://img.example/a.png' => [200, ['Content-Type' => 'image/png'], $png]]);
    $r = call(Img::class, 'analyzeImage', ['method' => 'POST', 'body' => ['image_url' => 'https://img.example/a.png']]);
    eq($r['status'], 200); eq($r['json']['data']['dimensions'], '1x1'); eq($r['json']['data']['file_size'], strlen($png));
});
test('Image: non-image bytes and internal URLs are refused (SSRF-002)', function () {
    fakeWeb(['https://img.example/x.png' => [200, [], '<html>not an image</html>']]);
    eq(call(Img::class, 'analyzeImage', ['method' => 'POST', 'body' => ['image_url' => 'https://img.example/x.png']])['status'], 502);
    fakeWeb([]);
    foreach (['file:///etc/passwd', 'phar:///tmp/a.phar/x', 'php://filter/resource=/etc/passwd', 'http://127.0.0.1/a.png', 'http://169.254.169.254/x.png', '/etc/passwd'] as $u) {
        $r = call(Img::class, 'analyzeImage', ['method' => 'POST', 'body' => ['image_url' => $u]]);
        ok(in_array($r['status'], [400, 502], true), "$u => {$r['status']}");
    }
    eq(count($GLOBALS['__calls']), 0, 'no transport call for blocked URLs');
});
test('Image bulk: URL resolution, internal targets skipped, dedupe, cap, unknown != optimized', function () use ($png, $db) {
    $pageHtml = html('<img src="img/a.png" alt="ok"><img src="../up/b.png"><img src="//cdn.example/c.png" alt="c"><img src="/abs.png"><img src="http://10.0.0.5/secret.png" alt="x">'
        . '<img src="http://127.0.0.1:8080/x.png"><img src="data:image/gif;base64,AAAA"><img data-src="lazy.png" alt="l"><img src="img/a.png#dup">');
    $ok = [200, ['Content-Type' => 'image/png'], $png];
    fakeWeb([
        'https://site.example:8443/blog/post/' => $pageHtml,
        'https://site.example:8443/blog/post/img/a.png' => $ok, 'https://site.example:8443/blog/up/b.png' => $ok,
        'https://cdn.example/c.png' => $ok, 'https://site.example:8443/abs.png' => $ok, 'https://site.example:8443/blog/post/lazy.png' => $ok,
    ], [], ['allow_ports' => [80, 443, 8443]]);
    Config::set('limits.bulk_images', 50);
    $r = callJob('bulkImages', ['method' => 'POST', 'body' => ['url' => 'https://site.example:8443/blog/post/']]);
    eq($r['status'], 200);
    $srcs = array_column($r['json']['data']['images'], 'src');
    sort($srcs);
    eq($srcs, ['https://cdn.example/c.png', 'https://site.example:8443/abs.png', 'https://site.example:8443/blog/post/img/a.png', 'https://site.example:8443/blog/post/lazy.png', 'https://site.example:8443/blog/up/b.png']);
    eq($r['json']['data']['skipped'], 2, 'the two internal image URLs are skipped');
    foreach ($GLOBALS['__calls'] as $c) { ok(!in_array($c['host'], ['10.0.0.5', '127.0.0.1'], true), 'internal host contacted'); }
    eq((int)$db->fetchOne("SELECT optimized o FROM sw_image_seo WHERE image_url = 'https://site.example:8443/blog/up/b.png'")['o'], 0, 'no alt => not optimized');
    eq((int)$db->fetchOne("SELECT optimized o FROM sw_image_seo WHERE image_url = 'https://site.example:8443/blog/post/img/a.png'")['o'], 1);
    // cap
    Config::set('limits.bulk_images', 2);
    $r = callJob('bulkImages', ['method' => 'POST', 'body' => ['url' => 'https://site.example:8443/blog/post/']]);
    eq(count($r['json']['data']['images']), 2); eq($r['json']['data']['truncated'], true);
    Config::set('limits.bulk_images', 50);
});
test('Image: unknown size is NULL optimized, never true (FUNC-012)', function () use ($db) {
    $db->insert('image_seo', ['image_url' => 'https://u.example/u.png']);
    $r = $db->fetchOne("SELECT optimized FROM sw_image_seo WHERE image_url = 'https://u.example/u.png'");
    eq($r['optimized'], null);
});
test('Image: alt-text suggestion is labelled as a filename heuristic, not AI (FUNC-010)', function () {
    $r = call(Img::class, 'suggestAltText', ['query' => ['image_url' => 'https://x.example/img/red-shoes_2.jpg']]);
    eq($r['json']['data']['suggestion'], 'Red Shoes 2'); eq($r['json']['data']['source'], 'filename_heuristic');
});
