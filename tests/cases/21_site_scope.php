<?php
use Sitewright\Core\{Config, Crypto, Gsc, GscController, OverviewController, SiteController, SiteSync, Validator};
use Sitewright\Modules\Analytics\AnalyticsController;

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb(); Config::set('app_key', Crypto::generateKey());
$mk = fn (string $name, string $url, ?bool $wp = null) => call(SiteController::class, 'create', ['method' => 'POST', 'body' => ['name' => $name, 'base_url' => $url] + ($wp === null ? [] : ['wordpress' => $wp])]);

test('a site without the WordPress plugin needs no app_key and no secret, is never synced, and sync/rotate are refused for it', function () use ($db, $mk) {
    $db->query('TRUNCATE sw_sites');
    $key = Config::get('app_key'); Config::set('app_key', '');
    $r = $mk('Shop', 'https://shop.example', false);
    eq($r['status'], 201, $r['body']); ok(!isset($r['json']['data']['secret']), 'no secret is made for a site without the plugin');
    eq($mk('Blog', 'https://blog.example', true)['status'], 409, 'the plugin kind still needs app_key');
    Config::set('app_key', $key);
    $shop = (int)$r['json']['data']['id']; $blog = (int)$mk('Blog', 'https://blog.example')['json']['data']['id'];   // default = WordPress, as before
    $row = $db->fetchOne('SELECT sync_enabled, secret_enc FROM sw_sites WHERE id = :i', ['i' => $shop]); eq([(int)$row['sync_enabled'], $row['secret_enc']], [0, '']);
    eq((int)$db->fetchOne('SELECT sync_enabled FROM sw_sites WHERE id = :i', ['i' => $blog])['sync_enabled'], 1);
    eq(SiteSync::scheduleDue(), 1, 'only the WordPress site is queued');
    SiteSync::markDirty(); eq((int)$db->fetchOne('SELECT sync_requested FROM sw_sites WHERE id = :i', ['i' => $shop])['sync_requested'], 0);
    eq(call(SiteController::class, 'sync', ['method' => 'POST'], ['id' => (string)$shop])['status'], 409);
    eq(call(SiteController::class, 'rotate', ['method' => 'POST'], ['id' => (string)$shop])['status'], 409);
    $list = call(SiteController::class, 'list')['json']['data']['sites']; $flags = array_column($list, 'sync_enabled', 'name'); ksort($flags); eq(array_map('intval', $flags), ['Blog' => 1, 'Shop' => 0]);
    $opt = call(SiteController::class, 'options')['json']['data']; $urls = array_column($opt, 'base_url', 'name'); ksort($urls); eq($urls, ['Blog' => 'https://blog.example', 'Shop' => 'https://shop.example']);
});
test('Validator::siteId accepts 0 and an existing site, refuses an unknown one', function () use ($db) {
    $id = (int)$db->fetchOne("SELECT id FROM sw_sites WHERE name = 'Shop'")['id'];
    eq(Validator::siteId([]), 0); eq(Validator::siteId(['project_id' => 0]), 0); eq(Validator::siteId(['project_id' => (string)$id]), $id);
    eq(resp(fn () => Validator::siteId(['project_id' => 99999]))['status'], 400);
    eq(resp(fn () => Validator::siteId(['project_id' => 'abc']))['status'], 400);
});
test('dashboard stats can be limited to one site: score, keywords and recent analyses', function () use ($db) {
    $db->query('TRUNCATE sw_seo_analysis'); $db->query('TRUNCATE sw_analytics_keywords');
    $d = gmdate('Y-m-d');
    foreach ([[1, 90, 'https://a.example/'], [2, 40, 'https://b.example/']] as [$p, $s, $u]) {
        $db->insert('seo_analysis', ['project_id' => $p, 'url' => $u, 'analysis_type' => 'site', 'score' => $s, 'results' => '{}']);
        $db->insert('analytics_keywords', ['project_id' => $p, 'keyword' => "kw$p", 'impressions' => $p * 100, 'clicks' => $p, 'position' => 3, 'ctr' => 1, 'date' => $d]);
    }
    $all = call(AnalyticsController::class, 'getDashboardStats')['json']['data'];
    eq([$all['seo_score'], $all['impressions'], $all['total_keywords']], [65, 300, 2]);
    $one = call(AnalyticsController::class, 'getDashboardStats', ['query' => ['project_id' => '2']])['json']['data'];
    eq([$one['seo_score'], $one['impressions'], $one['clicks'], $one['total_keywords']], [40, 200, 2, 1]);
    eq(array_column($one['recent_activity'], 'url'), ['https://b.example/']);
});
test('a Search Console connection is tied to a site (or to none); its rows are stored under that site', function () use ($db) {
    $db->query('TRUNCATE sw_gsc_connections');
    $c = (int)$db->insert('gsc_connections', ['user_id' => 1, 'refresh_token_enc' => 'x', 'property_url' => 'sc-domain:shop.example']);
    $shop = (int)$db->fetchOne("SELECT id FROM sw_sites WHERE name = 'Shop'")['id'];
    eq(call(GscController::class, 'setSite', ['method' => 'POST', 'body' => ['site_id' => $shop]], ['id' => (string)$c])['status'], 200);
    eq((int)$db->fetchOne('SELECT site_id FROM sw_gsc_connections WHERE id = :i', ['i' => $c])['site_id'], $shop);
    eq(call(GscController::class, 'setSite', ['method' => 'POST', 'body' => ['site_id' => 12345]], ['id' => (string)$c])['status'], 400, 'unknown site');
    eq(call(GscController::class, 'setSite', ['method' => 'POST', 'body' => ['site_id' => 0]], ['id' => (string)$c])['status'], 200, '0 = not tied to a site');
    eq(call(GscController::class, 'setSite', ['method' => 'POST', 'body' => ['site_id' => 1]], ['id' => '999'])['status'], 404);
    $rows = call(GscController::class, 'status')['json']['data']['connections']; eq((int)$rows[0]['site_id'], 0);
});
test('overview: one row per site with its latest score, 28-day search numbers and recent 404s; sites with no data show nulls, not zeros', function () use ($db) {
    $db->query('TRUNCATE sw_seo_analysis'); $db->query('TRUNCATE sw_analytics_keywords'); $db->query('TRUNCATE sw_404_monitor');
    $ids = array_column($db->fetchAll('SELECT id, name FROM sw_sites'), 'id', 'name'); $blog = (int)$ids['Blog']; $shop = (int)$ids['Shop'];
    $db->insert('seo_analysis', ['project_id' => $blog, 'url' => 'https://blog.example/', 'analysis_type' => 'site', 'score' => 70, 'results' => '{}']);
    $db->insert('seo_analysis', ['project_id' => $blog, 'url' => 'https://blog.example/', 'analysis_type' => 'competitor', 'score' => 10, 'results' => '{}']);
    $db->insert('analytics_keywords', ['project_id' => $blog, 'keyword' => 'k', 'impressions' => 500, 'clicks' => 20, 'position' => 4, 'ctr' => 4, 'date' => gmdate('Y-m-d', strtotime('-3 days'))]);
    $db->insert('analytics_keywords', ['project_id' => $blog, 'keyword' => 'old', 'impressions' => 900, 'clicks' => 90, 'position' => 4, 'ctr' => 10, 'date' => gmdate('Y-m-d', strtotime('-60 days'))]);
    $db->insert('404_monitor', ['project_id' => $blog, 'uri' => '/gone', 'hits' => 3]);
    $db->query('TRUNCATE sw_watch_items');
    foreach ([['/a', 'ok', 1], ['/b', 'down', 1], ['/c', 'down', 0]] as [$p, $st, $en]) { $db->insert('watch_items', ['project_id' => $blog, 'url' => "https://blog.example$p", 'last_status' => $st, 'enabled' => $en]); }
    $o = call(OverviewController::class, 'sites')['json']['data']['sites']; $by = array_column($o, null, 'name');
    eq(array_column($o, 'name'), ['Blog', 'Shop'], 'sorted by name');
    eq([$by['Blog']['last_score'], $by['Blog']['impressions'], $by['Blog']['clicks'], $by['Blog']['not_found']], [70, 500, 20, 1], 'competitor analysis and rows older than 28 days are ignored');
    eq([$by['Shop']['last_score'], $by['Shop']['impressions'], $by['Shop']['clicks'], $by['Shop']['not_found']], [null, null, null, 0]);
    eq($by['Shop']['plugin'], false); eq($by['Blog']['plugin'], true);
    eq([$by['Blog']['watched'], $by['Blog']['down']], [2, 1], 'paused items are not counted'); eq([$by['Shop']['watched'], $by['Shop']['down']], [0, 0]);
});
test('overview and the site list are readable by every signed-in role (a viewer needs them to choose a site)', function () use ($db) {
    seedAdmin('vi', 'viewer passphrase long here', 'viewer');
    $srv = startServer(); $h = new TestHttp($srv['base']); eq($h->login('vi', 'viewer passphrase long here')['status'], 200);
    eq($h->api('GET', '/api/overview')['status'], 200); eq($h->api('GET', '/api/sites/options')['status'], 200);
    eq($h->api('GET', '/api/sites')['status'], 403, 'the full site list stays admin-only');
    stopServer($srv);
});
test('gsc sync stores rows under the connection\'s site, so two sites can have the same keyword on the same day', function () use ($db) {
    Config::set('google', ['client_id' => 'cid-123456789.apps.googleusercontent.com', 'client_secret' => 'SECRET-abcdefghij', 'redirect_uri' => 'https://sitewright.example/api/gsc/callback']);
    $db->query('TRUNCATE sw_gsc_connections'); $db->query('TRUNCATE sw_analytics_keywords');
    $shop = (int)$db->fetchOne("SELECT id FROM sw_sites WHERE name = 'Shop'")['id'];
    $d = gmdate('Y-m-d', strtotime('-5 days'));
    $QUERY = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode('sc-domain:shop.example') . '/searchAnalytics/query';
    $ids = [];
    foreach ([0, $shop] as $site) {
        $c = (int)$db->insert('gsc_connections', ['user_id' => 1, 'refresh_token_enc' => 'x', 'property_url' => 'sc-domain:shop.example', 'site_id' => $site]);
        $db->update('gsc_connections', ['refresh_token_enc' => Crypto::encrypt('1//R', "gsc:$c")], ['id' => $c]); $ids[$site] = $c;
    }
    fakeWeb(['https://oauth2.googleapis.com/token' => fn () => [200, [], json_encode(['access_token' => 'ya29.A', 'expires_in' => 3599, 'scope' => Gsc::SCOPE, 'token_type' => 'Bearer'])],
        $QUERY => fn () => [200, [], json_encode(['rows' => [['keys' => [$d, 'blue widgets'], 'clicks' => 5, 'impressions' => 100, 'ctr' => 0.05, 'position' => 3.1]]])]]);
    foreach ($ids as $c) { Gsc::handleJob(['connection_id' => $c]); }
    $rows = $db->fetchAll('SELECT project_id, impressions FROM sw_analytics_keywords ORDER BY project_id');
    eq(array_map(fn ($r) => [(int)$r['project_id'], (int)$r['impressions']], $rows), [[0, 100], [$shop, 100]]);
});
