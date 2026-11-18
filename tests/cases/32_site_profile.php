<?php
use Sitewright\Core\{Config, Crypto, Jobs, SiteController, SiteProfile, SiteProfileController};

// Phase 2: what a site says about itself (public discovery), the owner's choice per item (use / type my own / skip), and the prefills built on it.
if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb(); Config::set('app_key', Crypto::generateKey());

$LD = json_encode(['@context' => 'https://schema.org', '@graph' => [
    ['@type' => 'WebSite', 'name' => 'Corner'],
    ['@type' => 'Bakery', 'name' => 'Corner Bakery', 'telephone' => '+1 (505) 555-0100',
     'address' => ['@type' => 'PostalAddress', 'streetAddress' => '12 Main St', 'addressLocality' => 'Santa Fe', 'addressRegion' => 'NM', 'postalCode' => '87501', 'addressCountry' => 'US']],
]]);
$page = fn (string $head, string $lang = 'en-US') => [200, ['Content-Type' => 'text/html; charset=utf-8'], "<!doctype html><html lang=\"$lang\"><head>$head</head><body><h1>Hi</h1></body></html>"];
$GOOD = $page('<title>  Corner Bakery |   Fresh bread </title><meta name="description" content="Bread baked daily."><meta name="generator" content="WordPress 6.5.2"><script type="application/ld+json">' . $LD . '</script>');
$web = function (array $extra = [], ?array $home = null) use ($GOOD) {
    $home ??= $GOOD;
    $pages = ['https://corner.example' => $home, 'https://corner.example/' => $home,
        'https://corner.example/robots.txt' => [200, ['Content-Type' => 'text/plain'], "User-agent: *\nDisallow: /wp-admin/\nSitemap: https://corner.example/wp-sitemap.xml\n"]];
    fakeWeb($extra + $pages);
};
$mkSite = function (string $url = 'https://corner.example', bool $wp = false) use ($db) {
    $db->query('TRUNCATE sw_site_profile'); $db->query('TRUNCATE sw_sites'); $db->query('TRUNCATE sw_jobs');
    $r = call(SiteController::class, 'create', ['method' => 'POST', 'body' => ['name' => 'Corner', 'base_url' => $url, 'wordpress' => $wp]]);
    eq($r['status'], 201, json_encode($r['json'])); return (int)$r['json']['data']['id'];
};
$state = fn (int $id) => array_column(array_map(fn ($f) => ['k' => $f['field'], 's' => $f['state'], 'found' => $f['found'], 'value' => $f['value']],
    array_filter(SiteProfile::view($id)['fields'], fn ($f) => $f['state'] !== 'empty')), null, 'k');   // items with nothing found and nothing chosen are not listed here

test('discovery reads the public site: title, description, language, platform, HTTPS, robots, sitemap, business name, phone and address', function () use ($mkSite, $web, $state) {
    $id = $mkSite(); $web(); SiteProfile::refresh($id); $s = $state($id);
    eq($s['site_title']['found'], 'Corner Bakery | Fresh bread', 'whitespace collapsed');
    eq($s['description']['found'], 'Bread baked daily.'); eq($s['language']['found'], 'en-US'); eq($s['platform']['found'], 'WordPress');
    eq($s['https']['found'], 'Yes'); eq($s['robots']['found'], 'Allowed'); eq($s['sitemap_url']['found'], 'https://corner.example/wp-sitemap.xml');
    eq([$s['business_name']['found'], $s['phone']['found']], ['Corner Bakery', '+1 (505) 555-0100']);
    eq([$s['street']['found'], $s['city']['found'], $s['region']['found'], $s['postal_code']['found'], $s['country']['found']], ['12 Main St', 'Santa Fe', 'NM', '87501', 'US']);
    foreach ($s as $k => $f) { eq($f['s'], in_array($k, ['https', 'robots'], true) ? 'info' : 'pending', 'nothing is used until the owner says so'); eq($f['value'], null); }
});

test('everything a site says is untrusted: tags and control characters stripped, lengths capped, bad language/phone/sitemap values dropped', function () use ($mkSite, $web, $page, $state) {
    $id = $mkSite();
    $bad = json_encode(['@type' => 'LocalBusiness', 'name' => str_repeat('N', 900), 'telephone' => "1; DROP TABLE sites;--", 'address' => 'Just a string, 5 Elm']);
    $web(['https://corner.example/robots.txt' => [200, [], "User-agent: *\nDisallow: /\nSitemap: javascript:alert(1)\nSitemap: https://evil.example/sitemap.xml\nSitemap: https://corner.example/\x00bad.xml\n"]],
        $page("<title>" . str_repeat('T', 600) . "\x07\x1b[31m</title><meta name=\"description\" content=\"&lt;script&gt;alert(1)&lt;/script&gt; <b>x</b>\"><script type=\"application/ld+json\">$bad</script>", 'en&quot;&gt;&lt;script&gt;'));
    SiteProfile::refresh($id); $s = $state($id);
    eq(mb_strlen($s['site_title']['found']), 200); ok(!preg_match('/[\x00-\x1F\x7F]/', (string)$s['site_title']['found']));
    ok(!str_contains((string)$s['description']['found'], '<'), 'no markup survives: ' . $s['description']['found']);
    eq(mb_strlen($s['business_name']['found']), 150); ok(!isset($s['phone']), 'a phone number that is not one is not offered');
    ok(!isset($s['language']), 'a language code that is not one is not offered'); ok(!isset($s['sitemap_url']), 'only same-site http(s) addresses are offered as a sitemap');
    eq($s['robots']['found'], 'Blocked: robots.txt tells every search engine to stay away (Disallow: /)');
    eq($s['street']['found'], 'Just a string, 5 Elm', 'a free-text address goes into the street line, not guessed apart');
});

test('a sitemap that robots.txt does not mention is found at the usual places; nesting in structured data cannot hang discovery', function () use ($mkSite, $web, $page, $state) {
    $id = $mkSite(); $deep = str_repeat('[', 400) . str_repeat(']', 400);
    $web(['https://corner.example/robots.txt' => [404, [], 'no'], 'https://corner.example/sitemap.xml' => [200, ['Content-Type' => 'application/xml'], '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>']],
        $page('<title>x</title><script type="application/ld+json">' . $deep . '</script><script type="application/ld+json">{not json</script>'));
    SiteProfile::refresh($id); $s = $state($id);
    eq($s['sitemap_url']['found'], 'https://corner.example/sitemap.xml'); eq($s['robots']['found'], 'Allowed'); ok(!isset($s['business_name']));
});

test('the business is the top-level node with an address or phone, not a place nested inside an event or an offer', function () use ($mkSite, $web, $page, $state) {
    $id = $mkSite();
    $graph = json_encode(['@graph' => [
        ['@type' => 'Event', 'name' => 'Bread fair', 'location' => ['@type' => 'Place', 'name' => 'Fairgrounds', 'telephone' => '+1 505 000 0000', 'address' => ['streetAddress' => '1 Fair Rd']]],
        ['@type' => 'Person', 'name' => 'Pat', 'telephone' => '+1 505 111 1111'],
        ['@type' => 'Bakery', 'name' => 'Corner Bakery', 'telephone' => '+1 505 555 0100', 'address' => ['streetAddress' => '12 Main St']]]]);
    $web([], $page('<title>x</title><script type="application/ld+json">' . $graph . '</script>')); SiteProfile::refresh($id); $s = $state($id);
    eq([$s['business_name']['found'], $s['phone']['found'], $s['street']['found']], ['Corner Bakery', '+1 505 555 0100', '12 Main St']);
});

test('choices: use follows the site, my own text is never overwritten, skip stays skipped, reset starts over; unfound items can be typed', function () use ($db, $mkSite, $web, $page, $state) {
    $id = $mkSite(); $web(); SiteProfile::refresh($id);
    SiteProfile::choose($id, 'business_name', 'use'); SiteProfile::choose($id, 'phone', 'manual', '  +1 505 555 0199 '); SiteProfile::choose($id, 'description', 'skip');
    $s = $state($id);
    eq([$s['business_name']['s'], $s['business_name']['value']], ['used', 'Corner Bakery']); eq([$s['phone']['s'], $s['phone']['value']], ['manual', '+1 505 555 0199']); eq($s['description']['s'], 'skipped');
    // the site changes: used follows, manual keeps my text but remembers what the site now says, skipped stays skipped
    $changed = json_encode(['@type' => 'Bakery', 'name' => 'Corner Bakery & Café', 'telephone' => '+1 505 555 0111']);
    $web([], $page('<title>New title</title><meta name="description" content="New text"><script type="application/ld+json">' . $changed . '</script>'));
    SiteProfile::refresh($id); $s = $state($id);
    eq($s['business_name']['value'], 'Corner Bakery & Café', 'used follows the site'); eq($s['phone']['value'], '+1 505 555 0199', 'typed text is never overwritten'); eq($s['phone']['found'], '+1 505 555 0111');
    eq($s['description']['s'], 'skipped'); eq($s['site_title']['s'], 'pending'); eq($s['site_title']['found'], 'New title');
    // a field the site no longer shows keeps what I chose
    ok(!isset($s['street']), 'a pending item the site no longer shows disappears');
    SiteProfile::choose($id, 'phone', 'reset'); $s = $state($id); eq([$s['phone']['s'], $s['phone']['value']], ['pending', null]);
    SiteProfile::choose($id, 'country', 'manual', 'United States'); eq($state($id)['country']['value'], 'United States');   // not found on the site, typed by me
    eq(SiteProfile::values($id), ['business_name' => 'Corner Bakery & Café', 'country' => 'United States'], 'only used and typed values feed the rest of the app');
    // the discovery of one site never touches another
    $other = (int)$db->insert('sites', ['name' => 'Other', 'base_url' => 'https://other.example', 'secret_enc' => '', 'sync_enabled' => 0]); eq(SiteProfile::values($other), []);
});

test('choose validates: unknown item, unknown action, empty or oversized or control-character text, and a missing site', function () use ($mkSite, $web, $state) {
    $id = $mkSite(); $web(); SiteProfile::refresh($id);
    foreach ([['nope', 'use', null], ['phone', 'frobnicate', null], ['phone', 'manual', '   '], ['phone', 'manual', null], ['phone', 'use', null, 99999]] as $c) {
        $r = resp(fn () => SiteProfile::choose($c[3] ?? $id, $c[0], $c[1], $c[2])); ok(in_array($r['status'], [400, 404], true), json_encode($c) . ' => ' . $r['status']);
    }
    SiteProfile::choose($id, 'description', 'manual', "line one\r\nline <b>two</b>\x00"); $v = $state($id)['description']['value'];
    ok(!str_contains($v, "\n") && !str_contains($v, '<') && !str_contains($v, "\0"), $v);
    $r = resp(fn () => SiteProfile::choose($id, 'site_title', 'manual', str_repeat('x', 5000))); eq($r['status'], 400, 'over the limit is refused, not silently cut');
    SiteProfile::choose($id, 'phone', 'use'); eq($state($id)['phone']['s'], 'used');
});

test('an unreachable, refusing or private site is recorded on the site, never thrown; earlier choices survive; the job always finishes', function () use ($db, $mkSite, $web) {
    $id = $mkSite(); $web(); SiteProfile::refresh($id); SiteProfile::choose($id, 'business_name', 'use');
    fakeWeb(['https://corner.example' => new \Sitewright\Core\FetchException('connection refused'), 'https://corner.example/' => new \Sitewright\Core\FetchException('connection refused')]);
    $r = SiteProfile::refresh($id); ok($r['error'] !== null);
    $row = $db->fetchOne('SELECT profile_error, profile_at FROM sw_sites WHERE id = :i', ['i' => $id]); ok($row['profile_error'] !== null && str_contains($row['profile_error'], 'refused')); ok($row['profile_at'] !== null);
    eq(SiteProfile::values($id), ['business_name' => 'Corner Bakery'], 'the choice survives a failed read');
    fakeWeb(['https://corner.example' => [503, [], 'down'], 'https://corner.example/' => [503, [], 'down']]); $r = SiteProfile::refresh($id); ok(str_contains((string)$r['error'], '503'), (string)$r['error']);
    // the guard: a site address that points inside the network is refused like everywhere else
    $inner = (int)$db->insert('sites', ['name' => 'Inner', 'base_url' => 'https://inner.example', 'secret_enc' => '', 'sync_enabled' => 0]);
    fakeWeb(['https://inner.example' => [200, [], 'x']], ['inner.example' => ['10.0.0.5']]); $r = SiteProfile::refresh($inner); ok($r['error'] !== null && !isset($GLOBALS['__calls'][0]), 'blocked before any request: ' . json_encode($r));
    $db->query('TRUNCATE sw_jobs'); $id2 = $id; Jobs::enqueue('site_profile', ['site_id' => $id2], null, 0, 1); Jobs::enqueue('site_profile', ['site_id' => 99999], null, 0, 1);
    while (Jobs::runNext()) {} eq(array_column($db->fetchAll('SELECT status FROM sw_jobs ORDER BY id'), 'status'), ['done', 'done'], 'a failing or vanished site never fails the job (the app health check counts failed jobs)');
});

test('adding a site queues the first read; the worker re-reads on schedule (setting sites.profile_days, 0 = off) and never queues twice', function () use ($db, $mkSite) {
    $id = $mkSite(); eq((int)$db->fetchOne("SELECT COUNT(*) c FROM sw_jobs WHERE type = 'site_profile'")['c'], 1, 'queued when the site is added');
    $db->query('TRUNCATE sw_jobs'); Config::set('sites', ['sync_minutes' => 10, 'profile_days' => 7]);
    eq(SiteProfile::scheduleDue(), 1, 'never read'); eq(SiteProfile::scheduleDue(), 0, 'already queued');
    $db->query('TRUNCATE sw_jobs'); $db->update('sites', ['profile_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]); eq(SiteProfile::scheduleDue(), 0, 'fresh');
    $db->update('sites', ['profile_at' => gmdate('Y-m-d H:i:s', time() - 8 * 86400)], ['id' => $id]); eq(SiteProfile::scheduleDue(), 1, 'a week old');
    $db->query('TRUNCATE sw_jobs'); Config::set('sites', ['sync_minutes' => 10, 'profile_days' => 0]); eq(SiteProfile::scheduleDue(), 0, 'off');
    Config::set('sites', ['sync_minutes' => 10, 'profile_days' => 7]);
});

$srv = startServer(['SW_APP_KEY' => Config::get('app_key')]); $base = $srv['base'];
seedAdmin('root', 'root passphrase long here', 'admin'); seedAdmin('ed', 'editor passphrase long here', 'editor'); seedAdmin('vi', 'viewer passphrase long here', 'viewer');
$as = function (string $u, string $p) use ($base) { $h = new TestHttp($base); eq($h->login($u, $p)['status'], 200); return $h; };

test('profile API: viewers read, only administrators change; options carry only the chosen values; refresh is queued', function () use ($db, $as, $mkSite, $web) {
    $id = $mkSite(); $web(); SiteProfile::refresh($id);
    $root = $as('root', 'root passphrase long here'); $vi = $as('vi', 'viewer passphrase long here'); $ed = $as('ed', 'editor passphrase long here');
    $r = $vi->api('GET', "/api/sites/$id/profile"); eq($r['status'], 200); eq($r['json']['data']['site']['name'], 'Corner');
    $keys = array_column($r['json']['data']['fields'], 'field'); ok(in_array('business_name', $keys, true) && in_array('phone', $keys, true) && in_array('sitemap_url', $keys, true));
    foreach ($r['json']['data']['fields'] as $f) { ok(isset($f['label'], $f['group'], $f['state'], $f['max']), 'every item carries what the UI needs'); }
    eq($vi->api('POST', "/api/sites/$id/profile", ['field' => 'phone', 'action' => 'use'])['status'], 403);
    eq($ed->api('POST', "/api/sites/$id/profile", ['field' => 'phone', 'action' => 'use'])['status'], 403, 'editors read; administrators decide');
    eq($vi->api('POST', "/api/sites/$id/profile/refresh")['status'], 403);
    eq($root->api('POST', "/api/sites/$id/profile", ['field' => 'phone', 'action' => 'manual', 'value' => '+1 505 555 0000'])['status'], 200);
    eq($root->api('POST', "/api/sites/$id/profile", ['field' => 'phone', 'action' => 'manual'])['status'], 400);
    eq($root->api('POST', "/api/sites/$id/profile", ['field' => 'nope', 'action' => 'use'])['status'], 400);
    eq($root->api('GET', '/api/sites/99999/profile')['status'], 404);
    $opt = $vi->api('GET', '/api/sites/options')['json']['data']; eq($opt[0]['profile'], ['phone' => '+1 505 555 0000'], 'pending items are not shared with the rest of the app');
    $db->query('TRUNCATE sw_jobs'); $q = $root->api('POST', "/api/sites/$id/profile/refresh"); eq($q['status'], 202); eq($db->fetchOne("SELECT type FROM sw_jobs WHERE id = :i", ['i' => $q['json']['data']['job_id']])['type'], 'site_profile');
    $audit = $db->fetchOne("SELECT COUNT(*) c FROM sw_audit_log WHERE action = 'site_profile_changed'")['c']; eq((int)$audit, 1, 'a change by an administrator is in the audit log');
});
stopServer($srv);
