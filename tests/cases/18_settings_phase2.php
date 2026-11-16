<?php
use Sitewright\Core\{Config, Settings};
use Sitewright\Modules\SeoAnalysis\SeoAnalysisController as Seo;

/** A fully "good" analysis result, then tweak pieces. */
$good = fn (array $o = []) => array_replace_recursive([
    'basic' => ['title' => 'T', 'title_optimal' => true, 'word_count' => 1200], 'meta' => ['description' => 'D', 'description_optimal' => true],
    'headings' => ['h1_optimal' => true], 'images' => ['alt_ratio' => 100], 'performance' => ['load_time' => 1.0, 'load_time_optimal' => true],
    'viewport' => ['has_viewport' => true], 'security' => ['uses_https' => true], 'structured_data' => ['has_schema' => true], 'links' => ['internal_links' => 9, 'external_links' => 2],
], $o);

test('SEO settings: defaults reproduce today\'s scores exactly (including the 100 cap on a 110-point total)', function () use ($good) {
    eq(Seo::calculateScore($good()), 100, 'every check passes: 110 points capped at 100');
    eq(Seo::calculateScore($good(['basic' => ['title' => '', 'word_count' => 0], 'meta' => ['description' => ''], 'images' => ['alt_ratio' => 0]])), 65, 'h1 10 + fast 15 + viewport 10 + https 10 + schema 10 + links 5 + 5');
    eq(Seo::calculateScore($good(['images' => ['alt_ratio' => 60], 'performance' => ['load_time' => 4.0, 'load_time_optimal' => false], 'links' => ['internal_links' => 5]])), 95, 'mid alt -5, acceptable speed -5, 5 links is not "more than 5" -5');
    eq(Seo::calculateScore($good(['images' => ['alt_ratio' => 20], 'performance' => ['load_time' => 9.0, 'load_time_optimal' => false], 'basic' => ['word_count' => 400], 'links' => ['internal_links' => 0, 'external_links' => 0], 'security' => ['uses_https' => false]])), 65);
});
test('SEO settings: weights, thresholds and ranges are read from settings; defaults still hold when unset', function () use ($good) {
    foreach (['h1', 'viewport', 'schema', 'https'] as $k) { Config::set("seo.w.$k", 0); }
    eq(Seo::calculateScore($good()), 70, 'four checks worth nothing: 110 - 40');
    foreach (['h1', 'viewport', 'schema', 'https'] as $k) { Config::set("seo.w.$k", 10); }
    Config::set('seo.words_good', 5000); Config::set('seo.words_great', 9000);
    eq(Seo::calculateScore($good(['basic' => ['title' => '', 'word_count' => 1200], 'meta' => ['description' => ''], 'images' => ['alt_ratio' => 0], 'performance' => ['load_time' => 9.0, 'load_time_optimal' => false]])), 55, 'words no longer count at 1200; slow fetch 5');
    Config::set('seo.words_good', 300); Config::set('seo.words_great', 1000);
    Config::set('seo.alt_high', 50); eq(Seo::calculateScore($good(['images' => ['alt_ratio' => 60], 'viewport' => ['has_viewport' => false]])), 100, 'alt 60% now counts as high: 110 - 10 = 100'); Config::set('seo.alt_high', 90);
});

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb();
test('SEO settings: the title range decides "optimal" in a real analysis', function () use ($db) {
    $title = str_repeat('t', 65);
    $page = [200, ['Content-Type' => 'text/html; charset=utf-8'], "<!doctype html><html lang=\"en\"><head><title>$title</title></head><body><h1>x</h1></body></html>"];
    $run = function () use ($page) { fakeWeb(['https://example.com/' => $page]); return call(Seo::class, 'analyze', ['method' => 'POST', 'body' => ['url' => 'https://example.com/']])['json']['data']['results']['basic']['title_optimal']; };
    eq($run(), false, 'a 65-character title is too long with the default 30-60');
    Config::set('seo.title_max', 70); eq($run(), true); Config::set('seo.title_max', 60);
});
test('SEO settings: registry rules (ranges, weights 0-100, cross-field) through Settings::prepare', function () {
    eq(Settings::prepare(['seo.title_min' => 50, 'seo.title_max' => 40], true)['status'], 400, 'min above max');
    eq(Settings::prepare(['seo.w.https' => 101], true)['status'], 400); eq(Settings::prepare(['seo.w.https' => -1], true)['status'], 400);
    eq(Settings::prepare(['seo.load_fast' => 6, 'seo.load_ok' => 5], true)['status'], 400);
    eq(Settings::prepare(['seo.title_min' => 25, 'seo.title_max' => 70, 'seo.load_fast' => 2.5], true)['status'], 200);
    ok(count(array_filter(array_keys(Settings::registry()), fn ($k) => str_starts_with($k, 'seo.w.'))) === 18);
});

// ---- Content AI, images, sitemaps, redirects, 404 ignore patterns -----------------------------------------------------------
use Sitewright\Modules\ContentAi\ContentAiController as Ai;
use Sitewright\Modules\ImageSeo\ImageSeoController as Img;
use Sitewright\Modules\Sitemap\SitemapController as Sm;
use Sitewright\Modules\Redirections\RedirectionsController as Rd;
use Sitewright\Modules\Monitor\MonitorController as Mon;
use Sitewright\Core\{Crypto, SiteSync};

test('content AI settings: status lists what is offered; disabled tones and types are refused with 400; default tone applies', function () {
    $st = call(Ai::class, 'status', [])['json']['data']; eq($st['tones'], ['professional', 'casual', 'friendly', 'formal']); eq($st['default_tone'], 'professional'); eq(count($st['types']), 5);
    Config::set('content_ai.tones_enabled', ['casual', 'friendly']); Config::set('content_ai.default_tone', 'casual'); Config::set('content_ai.types_enabled', ['title', 'heading']); Config::set('gemini.api_key', '');
    $st = call(Ai::class, 'status', [])['json']['data']; eq($st['tones'], ['casual', 'friendly']); eq($st['types'], ['title', 'heading']); eq($st['default_tone'], 'casual');
    eq(call(Ai::class, 'generateContent', ['method' => 'POST', 'body' => ['keyword' => 'tea', 'tone' => 'formal']])['status'], 400);
    eq(call(Ai::class, 'generateContent', ['method' => 'POST', 'body' => ['keyword' => 'tea', 'content_type' => 'paragraph']])['status'], 400);
    $ok = call(Ai::class, 'generateContent', ['method' => 'POST', 'body' => ['keyword' => 'tea']]); eq($ok['status'], 200, $ok['body']); eq($ok['json']['data']['content_type'], 'title', 'first enabled type when paragraph is off');
    Config::set('content_ai.tones_enabled', ['professional', 'casual', 'friendly', 'formal']); Config::set('content_ai.default_tone', 'professional'); Config::set('content_ai.types_enabled', ['paragraph', 'title', 'meta_description', 'heading', 'conclusion']);
});
test('content AI settings: temperature and token limit reach the model request', function () {
    Config::set('gemini.api_key', 'TEST_KEY_123'); Config::set('content_ai.temperature', 1.2); Config::set('content_ai.max_tokens', 900);
    fakeWeb(['https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent' => [200, [], json_encode(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => 'ok']]]]]])]]);
    call(Ai::class, 'generateContent', ['method' => 'POST', 'body' => ['keyword' => 'tea']]);
    $cfg = json_decode($GLOBALS['__calls'][0]['body'], true)['generationConfig']; eq($cfg['temperature'], 1.2); eq($cfg['maxOutputTokens'], 900);
    Config::set('gemini.api_key', ''); Config::set('content_ai.temperature', 0.7); Config::set('content_ai.max_tokens', 2048);
});
test('Settings rules: default tone must be enabled; lists must name known values and keep at least one', function () {
    eq(Settings::prepare(['content_ai.tones_enabled' => ['casual']], true)['status'], 400, 'default tone professional would be disabled');
    eq(Settings::prepare(['content_ai.tones_enabled' => ['casual'], 'content_ai.default_tone' => 'casual'], true)['status'], 200);
    eq(Settings::prepare(['content_ai.tones_enabled' => []], true)['status'], 400); eq(Settings::prepare(['content_ai.tones_enabled' => ['shouty']], true)['status'], 400);
    eq(Settings::prepare(['monitor.ignore_patterns' => ['no-slash']], true)['status'], 400); eq(Settings::prepare(['monitor.ignore_patterns' => ['/wp-json/*', '/feed']], true)['status'], 200);
    eq(Settings::prepare(['content_ai.temperature' => 3], true)['status'], 400); eq(Settings::prepare(['sitemap.default_priority' => 1.5], true)['status'], 400);
});
test('image settings: the optimized size cut-off and alt-length tip follow the settings', function () {
    $m = new ReflectionMethod(Img::class, 'optimized'); $img = new Img();
    eq($m->invoke($img, 'alt', 150000), true); Config::set('image.optimal_bytes', 100000); eq($m->invoke($img, 'alt', 150000), false); Config::set('image.optimal_bytes', 200000);
    Config::set('image.alt_max_length', 90); $tips = call(Img::class, 'suggestAltText', ['query' => ['image_url' => 'https://x.example/red-shoes.jpg']])['json']['data']['tips'];
    ok(in_array('Keep it under 90 characters', $tips, true), json_encode($tips)); Config::set('image.alt_max_length', 125);
});
test('sitemap and redirect defaults apply when the request leaves the field out', function () use ($db) {
    Config::set('sitemap.default_priority', 0.8); Config::set('sitemap.default_changefreq', 'daily'); Config::set('redirects.default_type', '302');
    $r = call(Sm::class, 'add', ['method' => 'POST', 'body' => ['url' => 'https://d.example/a']]); eq($r['status'], 201, $r['body']);
    $row = $db->fetchOne("SELECT priority, changefreq FROM sw_sitemaps WHERE url = 'https://d.example/a'"); eq((float)$row['priority'], 0.8); eq($row['changefreq'], 'daily');
    eq(call(Rd::class, 'add', ['method' => 'POST', 'body' => ['source_url' => '/dflt', 'target_url' => '/x']])['status'], 201);
    eq($db->fetchOne("SELECT redirect_type FROM sw_redirections WHERE source_url = '/dflt'")['redirect_type'], '302');
    Config::set('sitemap.default_max_pages', 42);
    $j = call(Sitewright\Core\JobController::class, 'crawl', ['method' => 'POST', 'body' => ['base_url' => 'https://d.example/']]); eq($j['status'], 202);
    eq(json_decode($db->fetchOne('SELECT payload FROM sw_jobs ORDER BY id DESC LIMIT 1')['payload'], true)['max_pages'], 42);
    Config::set('sitemap.default_priority', 0.5); Config::set('sitemap.default_changefreq', 'weekly'); Config::set('redirects.default_type', '301'); Config::set('sitemap.default_max_pages', 100);
});
test('404 ignore patterns drop matching paths: manual add and pulls from a connected site', function () use ($db) {
    Config::set('monitor.ignore_patterns', ['/wp-json/*', '/favicon.ico']);
    ok(Mon::ignored('/wp-json/wp/v2/posts')); ok(Mon::ignored('/favicon.ico')); ok(!Mon::ignored('/favicon.icon')); ok(!Mon::ignored('/blog/wp-json/x')); ok(!Mon::ignored('/real-page'));
    $r = call(Mon::class, 'log404', ['method' => 'POST', 'body' => ['uri' => '/wp-json/x']]); eq($r['json']['data']['ignored'], true);
    eq((int)$db->fetchOne("SELECT COUNT(*) n FROM sw_404_monitor WHERE uri = '/wp-json/x'")['n'], 0);
    eq(call(Mon::class, 'log404', ['method' => 'POST', 'body' => ['uri' => '/real-page']])['json']['data']['ignored'] ?? false, false);
    // pulled from a connected site
    Config::set('app_key', Crypto::generateKey()); $db->query('TRUNCATE sw_sites');
    $id = (int)$db->insert('sites', ['name' => 'S', 'base_url' => 'https://s.example', 'secret_enc' => 'x']); $db->update('sites', ['secret_enc' => Crypto::encrypt('a-secret', "site:$id")], ['id' => $id]);
    fakeWeb(['https://s.example/?rest_route=/sitewright/v1/status' => [200, [], '{"plugin_version":"1.0.0"}'],
             'https://s.example/?rest_route=/sitewright/v1/404s&since_seq=0&limit=500' => [200, [], json_encode(['items' => [['seq' => 1, 'uri' => '/wp-json/a', 'hits' => 3, 'last_seen' => '2026-01-01 00:00:00 UTC'], ['seq' => 2, 'uri' => '/lost-page', 'hits' => 2, 'last_seen' => '2026-01-01 00:00:00 UTC']], 'max_seq' => 2])],
             'https://s.example/?rest_route=/sitewright/v1/redirects' => [200, [], '{"accepted":0}']]);
    SiteSync::handleJob(['site_id' => $id]);
    eq(array_column($db->fetchAll("SELECT uri FROM sw_404_monitor WHERE project_id = $id"), 'uri'), ['/lost-page']);
    Config::set('monitor.ignore_patterns', []);
});

// ---- dashboard, users, alert events ---------------------------------------------------------------------------------------
use Sitewright\Modules\Analytics\AnalyticsController as An;
use Sitewright\Core\{Accounts, Alerts, Jobs};
test('dashboard settings: the window decides what counts, and the response carries window and cards', function () use ($db) {
    $db->query('TRUNCATE sw_analytics_keywords');
    $db->query("INSERT INTO sw_analytics_keywords (project_id, keyword, impressions, clicks, position, ctr, `date`) VALUES (0,'recent',100,10,3,1,CURDATE() - INTERVAL 3 DAY),(0,'older',1000,100,3,1,CURDATE() - INTERVAL 20 DAY)");
    $d = call(An::class, 'getDashboardStats', [])['json']['data']; eq($d['impressions'], 1100); eq($d['window_days'], 30); eq($d['cards'], ['seo', 'keywords', 'impressions', 'clicks']);
    Config::set('dashboard.window_days', 7); Config::set('dashboard.cards', ['clicks', 'seo']);
    $d = call(An::class, 'getDashboardStats', [])['json']['data']; eq($d['impressions'], 100, 'the 20-day-old row is outside a 7-day window'); eq($d['window_days'], 7); eq($d['cards'], ['clicks', 'seo'], 'the configured order, known cards only');
    Config::set('dashboard.window_days', 30); Config::set('dashboard.cards', ['seo', 'keywords', 'impressions', 'clicks']);
});
test('users settings: invite/reset lifetimes and their wording follow the settings', function () use ($db) {
    $db->query('TRUNCATE sw_password_resets');
    Config::set('users.invite_days', 3); Config::set('users.reset_minutes', 15);
    eq(Accounts::ttlMinutes('invite'), 3 * 1440); eq(Accounts::ttlMinutes('reset'), 15);
    Accounts::createToken(1, 'reset'); $m = (int)$db->fetchOne('SELECT TIMESTAMPDIFF(MINUTE, NOW(), expires_at) m FROM sw_password_resets ORDER BY id DESC LIMIT 1')['m']; ok($m >= 14 && $m <= 15, "minutes $m");
    Accounts::createToken(1, 'invite'); $m = (int)$db->fetchOne('SELECT TIMESTAMPDIFF(MINUTE, NOW(), expires_at) m FROM sw_password_resets ORDER BY id DESC LIMIT 1')['m']; ok($m >= 4318 && $m <= 4320, "minutes $m");
    Config::set('users.invite_days', 7); Config::set('users.reset_minutes', 60);
});
test('alert events: an event that is not listed is never queued; listed ones are', function () use ($db) {
    $db->query('TRUNCATE sw_jobs'); $db->query('TRUNCATE sw_rate_limits');
    Config::set('alerts.teams_webhook', 'https://example.webhook.office.com/x');
    Config::set('alerts.events', ['job_failed']);
    Alerts::notify('user_created', 'x'); eq((int)$db->fetchOne("SELECT COUNT(*) n FROM sw_jobs WHERE type='alert'")['n'], 0);
    Alerts::notify('job_failed', 'x'); eq((int)$db->fetchOne("SELECT COUNT(*) n FROM sw_jobs WHERE type='alert'")['n'], 1);
    Config::set('alerts.events', Config::defaults()['alerts']['events']); Config::set('alerts.teams_webhook', '');
    ok(in_array('server_error', Config::defaults()['alerts']['events'], true));
});

seedAdmin('root', 'root passphrase long here', 'admin');
$srvP2 = startServer(['SW_APP_KEY' => Crypto::generateKey()]);
test('users settings over HTTP: a user created without a role gets the saved default role', function () use ($srvP2) {
    $a = new TestHttp($srvP2['base']); $a->login('root', 'root passphrase long here');
    eq($a->api('PUT', '/api/settings', ['changes' => ['users.default_role' => 'editor']])['status'], 200);
    $r = $a->api('POST', '/api/users', ['username' => 'norole', 'email' => 'norole@example.test', 'password' => 'a long enough passphrase']); eq($r['status'], 201, $r['body']);
    $u = array_values(array_filter($a->api('GET', '/api/users')['json']['data'], fn ($x) => $x['username'] === 'norole'))[0]; eq($u['role'], 'editor');
    eq($a->api('PUT', '/api/settings', ['changes' => ['dashboard.cards' => []]])['status'], 400, 'at least one card');
});
test('contextual notes: admins see the scoring/ignore notes with the real values; viewers do not', function () use ($srvP2, $db) {
    $a = new TestHttp($srvP2['base']); $a->login('root', 'root passphrase long here');
    $page = $a->req('GET', '/seo-analysis')['body']; ok(str_contains($page, 'judged against 30–60 characters') && str_contains($page, '/settings#seo'));
    $db->query("INSERT INTO sw_settings (skey, svalue) VALUES ('monitor.ignore_patterns', '[\"/a\",\"/b*\"]') ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)");
    ok(str_contains($a->req('GET', '/404-monitor')['body'], '2 ignore pattern(s) active'));
    seedAdmin('look', 'viewer passphrase long here', 'viewer'); $v = new TestHttp($srvP2['base']); $v->login('look', 'viewer passphrase long here');
    ok(!str_contains($v->req('GET', '/seo-analysis')['body'], 'Change in Settings'));
});
