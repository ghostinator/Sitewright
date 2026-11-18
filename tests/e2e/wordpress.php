<?php
// Runs inside the sw-php container (see tests/docker/wordpress-e2e.sh). Sitewright code against a REAL WordPress with the agent plugin.
declare(strict_types=1);
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str, string $file, int $line): bool { throw new ErrorException($str, 0, $no, $file, $line); });
require __DIR__ . '/../../core/autoload.php';
require __DIR__ . '/../lib.php';
use Sitewright\Core\{Config, Crypto, Database, SafeHttpClient, SiteSync, SiteRemote, JobFailedException, JobRetryException};
function SiteProfile_found(int $id): array { return SiteRemote::profileFound($id); }

putenv('SW_BASE_URL'); putenv('SW_ALLOWED_HOSTS');
Config::useFile('/nonexistent/config.php'); Config::set('log.file', '/dev/null');
$WP = rtrim((string)getenv('WP_URL'), '/'); $SECRET = (string)getenv('WP_SECRET');
$db = freshDb();
Config::set('app_key', Crypto::generateKey());
Config::set('fetch.dev_allow_cidrs', ['172.16.0.0/12', '10.0.0.0/8', '192.168.0.0/16']);   // the Docker network; production never sets this
SafeHttpClient::setDefault(null);

$id = (int)$db->insert('sites', ['name' => 'e2e', 'base_url' => $WP, 'secret_enc' => 'x']);
$db->update('sites', ['secret_enc' => Crypto::encrypt($SECRET, "site:$id")], ['id' => $id]);
$site = fn () => $db->fetchOne('SELECT * FROM sw_sites WHERE id = :i', ['i' => $id]);
$http = fn (string $url, array $h = []) => SafeHttpClient::default()->request('GET', $url, ['headers' => $h, 'follow' => false, 'max_bytes' => 1 << 20]);
$fail = 0;
$check = function (string $name, bool $ok, string $detail = '') use (&$fail) { echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "  -> $detail") . "\n"; if (!$ok) { $fail++; } };
$raw = function (string $method, string $route, string $body, string $secret, ?int $t = null, ?string $sig = null, ?string $n = null) use ($WP): int {
    $t ??= time(); $n ??= bin2hex(random_bytes(12)); $sig ??= SiteSync::sign($secret, $method, $route, $t, $n, $body);
    $h = ['X-Sitewright-Signature: t=' . $t . ',n=' . $n . ',s=' . $sig, 'Content-Type: application/json'];
    return (int)SafeHttpClient::default()->request($method, "$WP/?rest_route=$route", ['headers' => $h, 'body' => $body, 'follow' => false])['status'];
};

// ---- signatures --------------------------------------------------------------------------------------------------------------
$route = '/sitewright/v1/status';
$check('valid signature accepted', $raw('GET', $route, '', $SECRET) === 200);
$check('no signature refused', (int)SafeHttpClient::default()->request('GET', "$WP/?rest_route=$route", ['follow' => false])['status'] === 401);
$check('wrong secret refused', $raw('GET', $route, '', str_repeat('a', 64)) === 401);
$check('stale timestamp refused (10 min old)', $raw('GET', $route, '', $SECRET, time() - 600) === 401);
$t = time(); $n = bin2hex(random_bytes(12)); $s = SiteSync::sign($SECRET, 'GET', $route, $t, $n, '');
$check('first use ok', $raw('GET', $route, '', $SECRET, $t, $s, $n) === 200);
$check('REPLAY of the same signature refused', $raw('GET', $route, '', $SECRET, $t, $s, $n) === 401);
$check('two honest identical calls in the same second both succeed (nonce)', $raw('GET', $route, '', $SECRET) === 200 && $raw('GET', $route, '', $SECRET) === 200);
$n2 = bin2hex(random_bytes(12));
$check('signature for another route refused', $raw('GET', '/sitewright/v1/404s', '', $SECRET, null, SiteSync::sign($SECRET, 'GET', $route, time(), $n2, ''), $n2) === 401);
$n3 = bin2hex(random_bytes(12));
$check('tampered body refused', $raw('POST', '/sitewright/v1/redirects', '{"rules":[],"version":"x"}', $SECRET, null, SiteSync::sign($SECRET, 'POST', '/sitewright/v1/redirects', time(), $n3, '{"rules":[],"version":"y"}'), $n3) === 401);
$n4 = bin2hex(random_bytes(12));
$check('nonce swapped after signing refused', $raw('GET', $route, '', $SECRET, null, SiteSync::sign($SECRET, 'GET', $route, time(), $n4, ''), bin2hex(random_bytes(12))) === 401);

// ---- 404 capture and pull ----------------------------------------------------------------------------------------------------
$http("$WP/does-not-exist-1/?utm=secret-tracking-param", ['Referer: https://referrer.example/some/page?token=abc']);
$http("$WP/does-not-exist-1/");
$http("$WP/wp-content/uploads/missing.jpg");
$res = SiteSync::handleJob(['site_id' => $id]);
$row = $db->fetchOne("SELECT uri, referer, hits, project_id FROM sw_404_monitor WHERE uri = '/does-not-exist-1/'");
$check('404 pulled with hit count and project = site', $row && (int)$row['hits'] === 2 && (int)$row['project_id'] === $id, json_encode($row));
$check('query string never stored', !$db->fetchOne("SELECT 1 x FROM sw_404_monitor WHERE uri LIKE '%utm%' OR uri LIKE '%secret%'"));
$check('referer reduced to origin+path (no query)', $row && $row['referer'] === 'https://referrer.example/some/page', (string)($row['referer'] ?? ''));
$check('static-file 404 also captured', (bool)$db->fetchOne("SELECT 1 x FROM sw_404_monitor WHERE uri = '/wp-content/uploads/missing.jpg'"));
$http("$WP/does-not-exist-1/");
SiteSync::handleJob(['site_id' => $id]);
$check('later hits update the same row (absolute count)', (int)$db->fetchOne("SELECT hits FROM sw_404_monitor WHERE uri = '/does-not-exist-1/'")['hits'] === 3);
$check('cursor advanced and status ok', (int)$site()['cursor_seq'] > 0 && $site()['last_status'] === 'ok' && $site()['plugin_version'] === '1.2.1');

// ---- redirects pushed and enforced -------------------------------------------------------------------------------------------
$ins = fn (string $s, string $t, string $ty) => $db->query("INSERT INTO sw_redirections (project_id, source_url, target_url, redirect_type, status) VALUES (:p, :s, :t, :ty, 'active')", ['p' => $id, 's' => $s, 't' => $t, 'ty' => $ty]);
$ins('/old-page/', '/new-page/', '301'); $ins('/gone-page/', '', '410'); $ins('/ext/', 'https://example.org/landing', '302'); $ins('/loop/', '/loop/', '301');
$ins('/old-page-two', '/new-page/', '308');
$r1 = SiteSync::handleJob(['site_id' => $id]);
$check('rules pushed', $r1['data']['pushed'] === true);
$r = $http("$WP/old-page/?a=1");
$check('301 enforced locally by the plugin, query kept', $r['status'] === 301 && str_starts_with((string)($r['headers']['location'] ?? ''), "$WP/new-page/") && str_contains((string)$r['headers']['location'], 'a=1'), json_encode([$r['status'], $r['headers']['location'] ?? null]));
$check('trailing-slash tolerant (rule without slash matches with slash)', $http("$WP/old-page-two/")['status'] === 308 && $http("$WP/old-page-two")['status'] === 308);
$check('410 enforced', $http("$WP/gone-page/")['status'] === 410);
$r = $http("$WP/ext/"); $check('external 302 target', $r['status'] === 302 && ($r['headers']['location'] ?? '') === 'https://example.org/landing');
$check('rule redirecting to itself is ignored (no loop)', $http("$WP/loop/")['status'] === 404);
$check('wp-admin/login are never redirected', $http("$WP/wp-login.php")['status'] === 200);
$r2 = SiteSync::handleJob(['site_id' => $id]);
$check('no change => no push', $r2['data']['pushed'] === false);
$db->query("DELETE FROM sw_redirections WHERE project_id = $id AND source_url = '/old-page/'");
SiteSync::handleJob(['site_id' => $id]);
$check('removed rule is no longer enforced after the next sync', $http("$WP/old-page/")['status'] === 404);

// ---- hostile rule data is refused by the plugin -------------------------------------------------------------------------------
$bad = fn (array $rules) => (function () use ($WP, $SECRET, $id, $db, $rules) { $site = $db->fetchOne('SELECT * FROM sw_sites WHERE id = :i', ['i' => $id]); return SiteSync::call($site, $SECRET, 'POST', '/sitewright/v1/redirects', ['rules' => $rules, 'version' => 'v-bad']); })();
$res = $bad([['source' => '/ok-a', 'target' => 'javascript:alert(1)', 'type' => 301], ['source' => '/ok-b', 'target' => '//evil.example/x', 'type' => 301], ['source' => 'no-slash', 'target' => '/x', 'type' => 301], ['source' => '/ok-c', 'target' => '/x', 'type' => 999], ['source' => "/ok-d\r\nSet-Cookie: x=1", 'target' => '/x', 'type' => 301], ['source' => '/fine', 'target' => '/ok', 'type' => 301]]);
$check('plugin keeps only valid rules (javascript:, //host, no leading slash, bad type, CRLF all dropped)', $res['accepted'] === 1, json_encode($res));
$check('...and the one valid rule is enforced', $http("$WP/fine")['status'] === 301);
SiteSync::handleJob(['site_id' => $id, 'force' => true]);

// ---- owner consent (plugin 1.1): everything optional is off until the owner turns it on --------------------------------------------
$get = fn (string $route, array $q = []) => (function () use ($WP, $SECRET, $route, $q): array {
    $t = time(); $n = bin2hex(random_bytes(12)); $qs = $q ? '&' . http_build_query($q) : '';
    $r = SafeHttpClient::default()->request('GET', "$WP/?rest_route=$route$qs", ['headers' => ['X-Sitewright-Signature: t=' . $t . ',n=' . $n . ',s=' . SiteSync::sign($SECRET, 'GET', $route, $t, $n, '')], 'follow' => false]);
    return ['status' => (int)$r['status'], 'json' => json_decode((string)$r['body'], true) ?: []];
})();
$wpdb = new PDO('mysql:host=' . getenv('WP_DB_HOST') . ';dbname=wp;charset=utf8mb4', 'wp', 'wp', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$setConsent = function (array $on) use ($wpdb): void {
    $types = ['site', 'content', 'seo', 'redirects', 'store', 'media', 'versions', 'media_write'];
    $val = serialize(array_combine($types, array_map(fn ($t) => in_array($t, $on, true), $types)));
    $wpdb->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('sitewright_consent', ?, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)")->execute([$val]);
};
$st = $get('/sitewright/v1/status');
$check('fresh install: the status lists every data type and all are off', $st['status'] === 200 && array_keys($st['json']['features'] ?? []) === ['site', 'content', 'seo', 'redirects', 'store', 'media', 'versions', 'media_write'] && !in_array(true, $st['json']['features'], true), json_encode($st['json']['features'] ?? null));
$check('the base status answer carries no WordPress or PHP version (that is the owner\'s "versions" choice)', !array_key_exists('wp_version', $st['json']) && !array_key_exists('php_version', $st['json']), json_encode(array_keys($st['json'])));
$denied = [];
foreach (['site', 'content', 'seo', 'existing-redirects', 'store', 'media', 'versions'] as $r) { $x = $get("/sitewright/v1/$r"); if ($x['status'] !== 403 || ($x['json']['code'] ?? '') !== 'sitewright_not_approved') { $denied[] = $r . ':' . $x['status']; } }
$check('every optional route refuses with "not approved" until the owner ticks it', $denied === [], implode(',', $denied));
$check('an unsigned call to an optional route is refused as unsigned', (int)SafeHttpClient::default()->request('GET', "$WP/?rest_route=/sitewright/v1/content", ['follow' => false])['status'] === 401);
SiteSync::handleJob(['site_id' => $id, 'force' => true]);
$states = fn () => array_column($db->fetchAll('SELECT kind, state FROM sw_site_remote WHERE site_id = :s', ['s' => $id]), 'state', 'kind');
$check('Sitewright sees nothing approved and stores nothing', array_unique(array_values($states())) === ['not_allowed'] && (int)$db->fetchOne('SELECT COUNT(*) n FROM sw_site_pages')['n'] === 0);

$setConsent(['site', 'content', 'seo', 'redirects', 'store', 'media', 'versions']);
$res = SiteSync::handleJob(['site_id' => $id, 'force' => true]);
$check('approved: sync still succeeds and every kind is ok', $res['message'] === 'Synced' && array_unique(array_values($states())) === ['ok'], json_encode($states()));
$pages = array_column($db->fetchAll('SELECT path, type, title FROM sw_site_pages WHERE site_id = :s', ['s' => $id]), null, 'path');
$check('page list: published pages and posts only (no draft, no password-protected page, no attachment)', isset($pages['/hello/'], $pages['/new-page/']) && !isset($pages['/draft-only/'], $pages['/locked/']), implode(',', array_keys($pages)));
$check('page titles arrive as plain text', ($pages['/hello/']['title'] ?? '') === 'Hello world', (string)($pages['/hello/']['title'] ?? ''));
$seo = array_column($db->fetchAll('SELECT path, seo_title, seo_description, noindex FROM sw_site_seo WHERE site_id = :s', ['s' => $id]), null, 'path');
$check('search data: title and description read; a template ("%%title%%") is left out; noindex found', ($seo['/hello/']['seo_title'] ?? '') === 'Hello | E2E' && ($seo['/hello/']['seo_description'] ?? '') === 'Say hello' && !isset($seo['/templated/']) && (int)($seo['/hidden/']['noindex'] ?? 0) === 1, json_encode($seo));
$red = json_decode($db->fetchOne("SELECT data FROM sw_site_remote WHERE site_id = :s AND kind = 'redirects'", ['s' => $id])['data'], true)['redirects'] ?? [];
$check('existing redirects: only exact, enabled ones', in_array(['source' => '/old-r/', 'target' => '/new-r/', 'type' => 301], $red, true) && count($red) === 1, json_encode($red));
$prof = SiteProfile_found($id);
$check('site details and shop address reach the setup guide', ($prof['site_title'] ?? '') === 'E2E' && ($prof['city'] ?? '') === 'Pueblo' && ($prof['region'] ?? '') === 'CO' && ($prof['postal_code'] ?? '') === '81008' && ($prof['street'] ?? '') === '1 Test St', json_encode($prof));
$ver = json_decode($db->fetchOne("SELECT data FROM sw_site_remote WHERE site_id = :s AND kind = 'versions'", ['s' => $id])['data'], true);
$check('version counts: WordPress and PHP versions, no names', preg_match('/^6\./', (string)$ver['wordpress']) === 1 && is_int($ver['plugin_updates']) && !isset($ver['plugins']), json_encode($ver));
$media = json_decode($db->fetchOne("SELECT data FROM sw_site_remote WHERE site_id = :s AND kind = 'media'", ['s' => $id])['data'], true);
$check('media counts are numbers only', is_int($media['images']) && is_int($media['images_without_alt']));
$leak = json_encode([$get('/sitewright/v1/content')['json'], $get('/sitewright/v1/site')['json'], $get('/sitewright/v1/store')['json'], $get('/sitewright/v1/versions')['json']]);
$check('nothing about users, e-mail addresses or passwords is in any answer', !preg_match('/admin|a@example\.test|e2e-admin-pass|user_login|post_author/i', $leak), '');

$setConsent(['site', 'media']);
SiteSync::handleJob(['site_id' => $id]);
$check('the owner withdraws page list and search data: Sitewright deletes what it stored', (int)$db->fetchOne('SELECT COUNT(*) n FROM sw_site_pages')['n'] === 0 && (int)$db->fetchOne('SELECT COUNT(*) n FROM sw_site_seo')['n'] === 0 && $states()['content'] === 'not_allowed' && $states()['site'] === 'ok', json_encode($states()));
$x = $get('/sitewright/v1/content'); $check('...and the plugin itself refuses the route again', $x['status'] === 403);

// ---- writing image text back: a separate approval, only alt text and title, undoable ---------------------------------------------------
$post = fn (array $body) => (function () use ($WP, $SECRET, $body): array {
    $route = '/sitewright/v1/media-text'; $json = json_encode($body); $t = time(); $n = bin2hex(random_bytes(12));
    $r = SafeHttpClient::default()->request('POST', "$WP/?rest_route=$route", ['headers' => ['Content-Type: application/json', 'X-Sitewright-Signature: t=' . $t . ',n=' . $n . ',s=' . SiteSync::sign($SECRET, 'POST', $route, $t, $n, $json)], 'body' => $json, 'follow' => false]);
    return ['status' => (int)$r['status'], 'json' => json_decode((string)$r['body'], true) ?: []];
})();
$img = (string)getenv('WP_IMAGE_URL'); $check('the e2e image exists in the media library', str_starts_with($img, 'http') && str_contains($img, 'e2e-picture'), $img);
$meta = fn (int $id, string $k) => (string)$wpdb->query("SELECT meta_value FROM wp_postmeta WHERE post_id = $id AND meta_key = '$k'")->fetchColumn();
$x = $post(['items' => [['url' => $img, 'alt' => 'new alt']]]); $check('changing image text is refused until the owner allows exactly that (the read-only choices do not allow it)', $x['status'] === 403 && ($x['json']['code'] ?? '') === 'sitewright_not_approved', json_encode($x));
$setConsent(['site', 'content', 'seo', 'redirects', 'store', 'media', 'versions']);
$x = $post(['items' => [['url' => $img, 'alt' => 'new alt']]]); $check('ticking every read-only choice still does not allow changes', $x['status'] === 403, json_encode($x));
$setConsent(['media_write']);
$x = $post(['items' => [['url' => $img, 'alt' => 'A <b>fresh</b>   alt text', 'title' => 'New title']]]); $r0 = $x['json']['results'][0] ?? [];
$check('allowed: the alt text and title are changed, tags and extra spaces removed, and the previous values come back', $x['status'] === 200 && ($r0['ok'] ?? false) === true && ($r0['previous'] ?? null) === ['alt' => 'old alt', 'title' => 'Old title'], json_encode($x));
$attId = (int)($r0['id'] ?? 0); $check('WordPress now holds the new text', $meta($attId, '_wp_attachment_image_alt') === 'A fresh alt text' && (string)$wpdb->query("SELECT post_title FROM wp_posts WHERE ID = $attId")->fetchColumn() === 'New title');
$x = $post(['items' => [['url' => $img, 'alt' => $r0['previous']['alt'], 'title' => $r0['previous']['title']]]]);
$check('undo: sending the previous values puts them back', $x['status'] === 200 && $meta($attId, '_wp_attachment_image_alt') === 'old alt' && (string)$wpdb->query("SELECT post_title FROM wp_posts WHERE ID = $attId")->fetchColumn() === 'Old title', json_encode($x));
$x = $post(['items' => [['url' => $img, 'alt' => '']]]); $check('an empty alt text is allowed (a decorative image)', ($x['json']['results'][0]['ok'] ?? false) === true && $meta($attId, '_wp_attachment_image_alt') === '');
$x = $post(['items' => [['url' => $img, 'title' => '']]]); $check('an empty title and no alt means nothing to change', ($x['json']['results'][0]['ok'] ?? true) === false && ($x['json']['results'][0]['error'] ?? '') === 'nothing to change');
$other = $WP . '/wp-content/uploads/not-in-library.png'; $x = $post(['items' => [['url' => $other, 'alt' => 'x'], ['url' => 'javascript:alert(1)', 'alt' => 'x'], ['url' => $WP . '/', 'alt' => 'x'], ['url' => $img . '?x=1', 'alt' => ['array']]]]);
$check('anything that is not an image in the media library is refused item by item (a file that is not there, a script address, a page)', count(array_filter($x['json']['results'] ?? [], fn ($r) => ($r['ok'] ?? true) === false)) === 4, json_encode($x['json']['results'] ?? $x));
$check('the request is bounded: no items, too many items and a non-list are refused', $post(['items' => []])['status'] === 400 && $post(['items' => array_fill(0, 51, ['url' => $img, 'alt' => 'x'])])['status'] === 400 && $post(['items' => 'nope'])['status'] === 400);
$x = $get('/sitewright/v1/media-text'); $check('the route cannot be read, only posted to', $x['status'] === 404 || $x['status'] === 405, (string)$x['status']);
$postCount = (int)$wpdb->query("SELECT COUNT(*) FROM wp_posts WHERE post_type = 'attachment'")->fetchColumn(); $check('nothing was uploaded or created along the way', $postCount === 1, (string)$postCount);
$setConsent(['site', 'media']);

// ---- failure modes ----------------------------------------------------------------------------------------------------------
$db->update('sites', ['secret_enc' => Crypto::encrypt(str_repeat('b', 64), "site:$id")], ['id' => $id]);
try { SiteSync::handleJob(['site_id' => $id]); $check('wrong secret => failed sync', false); } catch (JobFailedException $e) { $check('wrong secret => clear message, status recorded', str_contains($e->getMessage(), 'does not match') && str_contains($e->getMessage(), 'New secret') && $site()['last_status'] === 'error', $e->getMessage()); }
$db->update('sites', ['base_url' => 'http://127.0.0.1', 'secret_enc' => Crypto::encrypt($SECRET, "site:$id")], ['id' => $id]);
Config::set('fetch.dev_allow_cidrs', []); SafeHttpClient::setDefault(null);
try { SiteSync::handleJob(['site_id' => $id]); $check('loopback site URL blocked by the SSRF guard', false); } catch (JobRetryException | JobFailedException $e) { $check('loopback site URL blocked by the SSRF guard', str_contains($e->getMessage(), 'not allowed') || str_contains($e->getMessage(), 'reach'), $e->getMessage()); }

echo $fail === 0 ? "\nWORDPRESS E2E: ALL PASSED\n" : "\nWORDPRESS E2E: $fail FAILED\n";
exit($fail === 0 ? 0 : 1);
