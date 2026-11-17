<?php
// Runs inside the sw-php container (see tests/docker/wordpress-e2e.sh). Sitewright code against a REAL WordPress with the agent plugin.
declare(strict_types=1);
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str, string $file, int $line): bool { throw new ErrorException($str, 0, $no, $file, $line); });
require __DIR__ . '/../../core/autoload.php';
require __DIR__ . '/../lib.php';
use Sitewright\Core\{Config, Crypto, Database, SafeHttpClient, SiteSync, JobFailedException, JobRetryException};

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
$check('cursor advanced and status ok', (int)$site()['cursor_seq'] > 0 && $site()['last_status'] === 'ok' && $site()['plugin_version'] === '1.0.1');

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

// ---- failure modes ----------------------------------------------------------------------------------------------------------
$db->update('sites', ['secret_enc' => Crypto::encrypt(str_repeat('b', 64), "site:$id")], ['id' => $id]);
try { SiteSync::handleJob(['site_id' => $id]); $check('wrong secret => failed sync', false); } catch (JobFailedException $e) { $check('wrong secret => clear message, status recorded', str_contains($e->getMessage(), 'does not match') && str_contains($e->getMessage(), 'New secret') && $site()['last_status'] === 'error', $e->getMessage()); }
$db->update('sites', ['base_url' => 'http://127.0.0.1', 'secret_enc' => Crypto::encrypt($SECRET, "site:$id")], ['id' => $id]);
Config::set('fetch.dev_allow_cidrs', []); SafeHttpClient::setDefault(null);
try { SiteSync::handleJob(['site_id' => $id]); $check('loopback site URL blocked by the SSRF guard', false); } catch (JobRetryException | JobFailedException $e) { $check('loopback site URL blocked by the SSRF guard', str_contains($e->getMessage(), 'not allowed') || str_contains($e->getMessage(), 'reach'), $e->getMessage()); }

echo $fail === 0 ? "\nWORDPRESS E2E: ALL PASSED\n" : "\nWORDPRESS E2E: $fail FAILED\n";
exit($fail === 0 ? 0 : 1);
