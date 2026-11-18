<?php
use Sitewright\Core\{Config, Crypto, Jobs, SiteController, SiteProfile, SiteRemote, SiteSync};

// Phase 3: what the owner of a WordPress site approves the plugin (1.1+) to tell Sitewright, and how everything that comes back is treated (untrusted).
if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb(); Config::set('app_key', Crypto::generateKey());

$B = 'https://p.example/?rest_route=/sitewright/v1';
$mkSite = function () use ($db) {
    foreach (['sites', 'site_remote', 'site_pages', 'site_seo', 'site_profile', 'jobs', '404_monitor'] as $t) { $db->query("TRUNCATE sw_$t"); }
    $id = (int)$db->insert('sites', ['name' => 'P', 'base_url' => 'https://p.example', 'secret_enc' => 'x']);
    $db->update('sites', ['secret_enc' => Crypto::encrypt('s3cret', "site:$id")], ['id' => $id]); return $id;
};
$json = fn ($d, int $s = 200) => [$s, ['Content-Type' => 'application/json'], json_encode($d)];
$ALL = ['site' => true, 'content' => true, 'seo' => true, 'redirects' => true, 'store' => true, 'media' => true, 'versions' => true];
$NONE = array_map(fn () => false, $ALL);
/** A site whose plugin answers every route; $over replaces or removes (null) individual routes. */
$wp = function (?array $features, array $over = []) use ($B, $json) {
    $pages = [
        "$B/status" => $json(['plugin_version' => $features === null ? '1.0.1' : '1.1.0'] + ($features === null ? [] : ['features' => $features])),
        "$B/404s&since_seq=0&limit=500" => $json(['items' => [], 'max_seq' => 0]),
        "$B/redirects" => $json(['accepted' => 0]),
        "$B/site" => $json(['title' => 'Corner <b>Bakery</b>', 'tagline' => 'Bread, daily', 'language' => 'en-US', 'timezone' => 'America/Denver', 'url' => 'https://p.example/']),
        "$B/content&cursor=0&limit=200" => $json(['items' => [
            ['id' => 1, 'type' => 'page', 'path' => '/', 'title' => 'Home', 'modified' => '2026-11-01 10:00:00 UTC'],
            ['id' => 2, 'type' => 'post', 'path' => '/hello/', 'title' => 'Hello <script>alert(1)</script> world', 'modified' => '2026-11-02 10:00:00 UTC'],
            ['id' => 3, 'type' => 'post', 'path' => "/bad\x00path/", 'title' => 'dropped: control character'],
            ['id' => 4, 'type' => 'post', 'path' => '//evil.example/x', 'title' => 'dropped: protocol-relative'],
            ['id' => 5, 'type' => 'post', 'path' => 'no-slash', 'title' => 'dropped: not a path'],
            ['id' => 6, 'type' => 'we ird!', 'path' => '/weird/', 'title' => ''],
        ], 'next' => 6]),
        "$B/content&cursor=6&limit=200" => $json(['items' => [['id' => 7, 'type' => 'page', 'path' => '/about/', 'title' => 'About', 'modified' => 'not a date']], 'next' => null]),
        "$B/seo&cursor=0&limit=200" => $json(['plugin' => 'yoast', 'templated' => 2, 'next' => null, 'items' => [
            ['id' => 2, 'path' => '/hello/', 'seo_title' => 'Hello | Corner', 'seo_description' => '<i>Fresh</i> bread', 'noindex' => false],
            ['id' => 7, 'path' => '/about/', 'seo_title' => null, 'seo_description' => null, 'noindex' => true],
        ]]),
        "$B/existing-redirects" => $json(['items' => [
            ['source' => '/old/', 'target' => '/new/', 'type' => 301, 'from' => 'redirection'],
            ['source' => '/x/', 'target' => 'javascript:alert(1)', 'type' => 301],
            ['source' => '/y/', 'target' => '/ok/', 'type' => 999],
            ['source' => 'nope', 'target' => '/ok/', 'type' => 301],
        ]]),
        "$B/store" => $json(['available' => true, 'street' => '12 Main St', 'street2' => 'Suite 4', 'city' => 'Pueblo', 'postcode' => '81008', 'country' => 'US', 'region' => 'CO']),
        "$B/media" => $json(['images' => 120, 'images_without_alt' => 999999]),
        "$B/versions" => $json(['wordpress' => '6.8.1', 'php' => '8.3.4', 'core_update' => true, 'plugin_updates' => 3, 'theme_updates' => 0, 'active_plugins' => 22, 'checked_at' => time() - 600]),
    ];
    foreach ($over as $k => $v) { if ($v === null) { unset($pages["$B/$k"]); } else { $pages["$B/$k"] = $v; } }
    fakeWeb($pages);
};
$called = fn () => array_values(array_unique(array_map(fn ($r) => preg_replace('#^https://p\.example/\?rest_route=/sitewright/v1/([a-z0-9-]+).*$#', '$1', $r['url']), $GLOBALS['__calls'])));
$optional = ['site', 'content', 'seo', 'existing-redirects', 'store', 'media', 'versions'];
$states = fn (int $id) => array_column($db->fetchAll('SELECT kind, state FROM sw_site_remote WHERE site_id = :s', ['s' => $id]), 'state', 'kind');

test('an old plugin (no feature list) still syncs; every extra is "unsupported" and no optional route is called', function () use ($mkSite, $wp, $called, $states, $optional, $db) {
    $id = $mkSite(); $wp(null);
    eq(SiteSync::handleJob(['site_id' => $id])['message'], 'Synced');
    eq(array_unique(array_values($states($id))), ['unsupported']);
    eq(array_values(array_intersect($called(), $optional)), [], 'nothing optional was requested');
    eq($db->fetchOne('SELECT last_status s FROM sw_sites WHERE id = :i', ['i' => $id])['s'], 'ok');
});

test('nothing approved: every extra is "not_allowed" and none of the optional routes is called', function () use ($mkSite, $wp, $called, $states, $NONE, $optional) {
    $id = $mkSite(); $wp($NONE); SiteSync::handleJob(['site_id' => $id]);
    eq(array_unique(array_values($states($id))), ['not_allowed']);
    eq(array_values(array_intersect($called(), $optional)), []);
});

test('only the ticked types are requested', function () use ($mkSite, $wp, $called, $states, $NONE) {
    $id = $mkSite(); $wp(['media' => true] + $NONE); SiteSync::handleJob(['site_id' => $id]);
    eq(array_values(array_intersect($called(), ['site', 'content', 'seo', 'existing-redirects', 'store', 'media', 'versions'])), ['media']);
    eq($states($id)['media'], 'ok'); eq($states($id)['content'], 'not_allowed');
});

test('everything approved: pages (paged), search data, redirects, counts and versions are stored, cleaned and capped', function () use ($mkSite, $wp, $ALL, $db, $states) {
    $id = $mkSite(); $wp($ALL); SiteSync::handleJob(['site_id' => $id]);
    eq(array_unique(array_values($states($id))), ['ok']);
    $pages = array_column($db->fetchAll('SELECT path, type, title, modified FROM sw_site_pages WHERE site_id = :s', ['s' => $id]), null, 'path');
    $keys = array_keys($pages); sort($keys);
    eq($keys, ['/', '/about/', '/hello/', '/weird/'], 'second page followed by cursor; control-character, "//" and non-path entries dropped');
    eq($pages['/hello/']['title'], 'Hello alert(1) world', 'markup stripped'); eq($pages['/weird/']['type'], 'page', 'odd type replaced'); eq($pages['/about/']['modified'], null, 'bad date dropped');
    eq($pages['/']['modified'], '2026-11-01 10:00:00');
    $seo = array_column($db->fetchAll('SELECT path, seo_title, seo_description, noindex FROM sw_site_seo WHERE site_id = :s', ['s' => $id]), null, 'path');
    eq([$seo['/hello/']['seo_title'], $seo['/hello/']['seo_description'], (int)$seo['/hello/']['noindex']], ['Hello | Corner', 'Fresh bread', 0]);
    eq([$seo['/about/']['seo_title'], (int)$seo['/about/']['noindex']], [null, 1]);
    $red = json_decode($db->fetchOne("SELECT data FROM sw_site_remote WHERE site_id = :s AND kind = 'redirects'", ['s' => $id])['data'], true);
    eq($red['redirects'], [['source' => '/old/', 'target' => '/new/', 'type' => 301]], 'javascript: target, unknown type and a non-path source are dropped');
    $media = json_decode($db->fetchOne("SELECT data FROM sw_site_remote WHERE site_id = :s AND kind = 'media'", ['s' => $id])['data'], true);
    eq($media, ['images' => 120, 'images_without_alt' => 120], 'a count larger than the total is cut to the total');
    $v = json_decode($db->fetchOne("SELECT data FROM sw_site_remote WHERE site_id = :s AND kind = 'versions'", ['s' => $id])['data'], true);
    eq([$v['wordpress'], $v['plugin_updates'], $v['core_update']], ['6.8.1', 3, true]);
});

test('view: plain-language summary per kind; sites without the plugin show none', function () use ($mkSite, $wp, $ALL, $db) {
    $id = $mkSite(); $wp($ALL); SiteSync::handleJob(['site_id' => $id]);
    $v = array_column(SiteRemote::view($id), null, 'kind');
    eq($v['content']['summary'], '4 pages and posts'); eq($v['media']['summary'], '120 images, 120 without alt text');
    ok(str_contains($v['seo']['summary'], 'Yoast') && str_contains($v['seo']['summary'], '2 use a template'), $v['seo']['summary']);
    ok(str_contains($v['versions']['summary'], 'WordPress 6.8.1') && str_contains($v['versions']['summary'], '3 plugin'), $v['versions']['summary']);
    $db->query('UPDATE sw_sites SET sync_enabled = 0'); eq(SiteProfile::view($id)['remote'], []);
});

test('the site details and shop address become setup-guide items and win over what the public page showed', function () use ($mkSite, $wp, $ALL, $db) {
    $id = $mkSite(); $wp($ALL); SiteSync::handleJob(['site_id' => $id]);
    eq(SiteRemote::profileFound($id), ['site_title' => 'Corner Bakery', 'description' => 'Bread, daily', 'language' => 'en-US', 'street' => '12 Main St Suite 4', 'city' => 'Pueblo', 'region' => 'CO', 'postal_code' => '81008', 'country' => 'US']);
    ok((bool)$db->fetchOne("SELECT 1 x FROM sw_jobs WHERE type = 'site_profile' AND status = 'queued'"), 'a profile read is queued');
    eq((int)$db->fetchOne("SELECT COUNT(*) n FROM sw_jobs WHERE type = 'site_profile'")['n'], 1);
    $html = [200, ['Content-Type' => 'text/html'], '<!doctype html><html lang="fr"><head><title>Public title</title><meta name="description" content="Public text"></head><body></body></html>'];
    fakeWeb(['https://p.example' => $html, 'https://p.example/' => $html]);
    SiteProfile::refresh($id);
    $f = array_column(SiteProfile::view($id)['fields'], null, 'field');
    eq([$f['site_title']['found'], $f['language']['found'], $f['city']['found']], ['Corner Bakery', 'en-US', 'Pueblo']);
    eq($f['site_title']['state'], 'pending', 'still the owner\'s choice');
});

test('a failing optional route is recorded and never fails the sync; a 403 "not approved" becomes not_allowed', function () use ($mkSite, $wp, $ALL, $json, $db, $states) {
    $id = $mkSite();
    $wp($ALL, ['media' => $json(['message' => 'boom'], 500), 'versions' => $json(['code' => 'sitewright_not_approved', 'message' => 'The site owner has not approved sharing this'], 403), 'store' => [200, [], 'not json']]);
    eq(SiteSync::handleJob(['site_id' => $id])['message'], 'Synced');
    $s = $states($id);
    eq([$s['media'], $s['versions'], $s['store'], $s['content']], ['error', 'not_allowed', 'error', 'ok']);
    ok(str_contains($db->fetchOne("SELECT error e FROM sw_site_remote WHERE kind = 'media'")['e'], '500'));
    eq($db->fetchOne('SELECT last_status s FROM sw_sites WHERE id = :i', ['i' => $id])['s'], 'ok');
});

test('the owner takes an approval away: stored pages and search data are deleted and the state becomes not_allowed', function () use ($mkSite, $wp, $ALL, $NONE, $db, $states) {
    $id = $mkSite(); $wp($ALL); SiteSync::handleJob(['site_id' => $id]);
    ok((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_site_pages')['n'] > 0);
    $wp(['content' => false, 'seo' => false] + $ALL); SiteSync::handleJob(['site_id' => $id]);
    eq([(int)$db->fetchOne('SELECT COUNT(*) n FROM sw_site_pages')['n'], (int)$db->fetchOne('SELECT COUNT(*) n FROM sw_site_seo')['n']], [0, 0]);
    eq([$states($id)['content'], $states($id)['seo'], $states($id)['site']], ['not_allowed', 'not_allowed', 'ok']);
    $wp($NONE); SiteSync::handleJob(['site_id' => $id]);
    eq(array_unique(array_values($states($id))), ['not_allowed']);
    eq(SiteRemote::profileFound($id), [], 'details from a withdrawn approval no longer feed the setup guide');
    eq($db->fetchOne("SELECT data d FROM sw_site_remote WHERE kind = 'versions'")['d'], null);
});

test('the heavier reads run on their own interval (sites.remote_hours); a forced sync reads again', function () use ($mkSite, $wp, $ALL, $called) {
    $id = $mkSite(); $wp($ALL); SiteSync::handleJob(['site_id' => $id]);
    $wp($ALL); SiteSync::handleJob(['site_id' => $id]);
    eq(array_intersect($called(), ['content', 'media']), [], 'second sync inside the interval does not read them');
    $wp($ALL); SiteSync::handleJob(['site_id' => $id, 'force' => true]);
    ok(in_array('content', $called(), true) && in_array('media', $called(), true));
});

test('deleting a site deletes everything its plugin told us', function () use ($mkSite, $wp, $ALL, $db) {
    $id = $mkSite(); $wp($ALL); SiteSync::handleJob(['site_id' => $id]);
    $r = call(SiteController::class, 'delete', ['method' => 'DELETE'], ['id' => (string)$id]);
    eq($r['status'], 200, json_encode($r['json']));
    foreach (['site_remote', 'site_pages', 'site_seo', 'site_profile'] as $t) { eq((int)$db->fetchOne("SELECT COUNT(*) n FROM sw_$t")['n'], 0, $t); }
});

test('the refresh interval for approved details is a setting (sites.remote_hours, default 6)', function () {
    eq(Config::get('sites.remote_hours'), 6);
    ok(isset(\Sitewright\Core\Settings::registry()['sites.remote_hours']), 'registered');
});
