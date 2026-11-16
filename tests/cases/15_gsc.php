<?php
use Sitewright\Core\{Config, Crypto, Gsc, GscException, JobFailedException};

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb();
Config::set('app_key', Crypto::generateKey());
$googleCfg = ['client_id' => 'cid-123456789.apps.googleusercontent.com', 'client_secret' => 'SECRET-abcdefghij', 'redirect_uri' => 'https://sitewright.example/api/gsc/callback'];
Config::set('google', $googleCfg);
$TOKEN = 'https://oauth2.googleapis.com/token';
$QUERY = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode('sc-domain:example.com') . '/searchAnalytics/query';
$tokenOk = fn (array $extra = []) => [200, [], json_encode(array_replace(['access_token' => 'ya29.ACCESS', 'expires_in' => 3599, 'scope' => Gsc::SCOPE, 'token_type' => 'Bearer'], $extra))];
$conn = function (string $refresh = '1//REFRESH-TOKEN-xyz', string $prop = 'sc-domain:example.com') use ($db) {
    $db->query('TRUNCATE sw_gsc_connections');
    $id = (int)$db->insert('gsc_connections', ['user_id' => 1, 'refresh_token_enc' => 'x', 'property_url' => $prop]);
    $db->update('gsc_connections', ['refresh_token_enc' => Crypto::encrypt($refresh, "gsc:$id")], ['id' => $id]);
    return $id;
};
$b64u = fn (string $b) => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');

test('gsc: not configured => refuses to start (needs client id/secret, redirect URI and app_key)', function () use ($googleCfg) {
    Config::set('google', ['client_id' => '', 'client_secret' => '', 'redirect_uri' => '']); $s = [];
    ok(!Gsc::configured()); throws(GscException::class, function () use (&$s) { Gsc::authUrl($s); });
    Config::set('google', $googleCfg); ok(Gsc::configured());
});
test('gsc: auth URL is read-only scope, offline, PKCE S256, with a session-bound random state', function () use ($b64u) {
    $s = []; $url = Gsc::authUrl($s); parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
    eq(parse_url($url, PHP_URL_HOST), 'accounts.google.com'); eq($q['scope'], 'https://www.googleapis.com/auth/webmasters.readonly');
    eq($q['response_type'], 'code'); eq($q['access_type'], 'offline'); eq($q['code_challenge_method'], 'S256'); eq($q['redirect_uri'], 'https://sitewright.example/api/gsc/callback');
    eq($q['state'], $s['gsc_oauth']['state']); eq($q['code_challenge'], $b64u(hash('sha256', $s['gsc_oauth']['verifier'], true)));
    ok(strlen($q['state']) >= 30 && strlen($s['gsc_oauth']['verifier']) >= 43);
    $s2 = []; Gsc::authUrl($s2); ok($s2['gsc_oauth']['state'] !== $s['gsc_oauth']['state']);
    ok(!str_contains($url, 'SECRET-abcdefghij'), 'client secret is never in the browser URL');
});
test('gsc callback: wrong/missing/expired/replayed state and denied consent are refused before any request to Google', function () {
    fakeWeb([]);
    foreach ([['bad', fn (&$s) => null], ['', fn (&$s) => null], ['ok-expired', function (&$s) { $s['gsc_oauth']['until'] = time() - 1; }]] as [$state, $mut]) {
        $s = []; Gsc::authUrl($s); $mut($s);
        $q = ['code' => 'c', 'state' => $state === 'ok-expired' ? $s['gsc_oauth']['state'] : $state];
        throws(GscException::class, function () use (&$s, $q) { Gsc::handleCallback($s, $q, 1); });
        ok(!isset($s['gsc_oauth']), 'pending state is consumed even on failure');
    }
    $s = []; Gsc::authUrl($s); $st = $s['gsc_oauth']['state'];
    throws(GscException::class, function () use (&$s, $st) { Gsc::handleCallback($s, ['error' => 'access_denied', 'state' => $st], 1); });
    throws(GscException::class, function () use (&$s) { Gsc::handleCallback($s, ['code' => 'c', 'state' => 'whatever'], 1); }, 'nothing pending any more');
    eq(count($GLOBALS['__calls']), 0, 'no network call was made');
});
test('gsc callback: success exchanges the code with the PKCE verifier, stores the refresh token encrypted, and the state cannot be replayed', function () use ($db, $TOKEN, $tokenOk) {
    $db->query('TRUNCATE sw_gsc_connections');
    fakeWeb([$TOKEN => fn () => $tokenOk(['refresh_token' => '1//REFRESH-SECRET'])]);
    $s = []; Gsc::authUrl($s); $st = $s['gsc_oauth']['state']; $verifier = $s['gsc_oauth']['verifier'];
    $id = Gsc::handleCallback($s, ['code' => 'AUTHCODE', 'state' => $st], 1);
    $req = $GLOBALS['__calls'][0]; parse_str($req['body'], $form);
    eq($req['method'], 'POST'); eq($form['grant_type'], 'authorization_code'); eq($form['code'], 'AUTHCODE'); eq($form['code_verifier'], $verifier);
    eq($form['redirect_uri'], 'https://sitewright.example/api/gsc/callback'); eq($form['client_id'], 'cid-123456789.apps.googleusercontent.com');
    $row = $db->fetchOne('SELECT * FROM sw_gsc_connections WHERE id = :i', ['i' => $id]);
    ok(!str_contains(json_encode($row), 'REFRESH-SECRET'), 'plaintext refresh token is not in the database');
    eq(Crypto::decrypt($row['refresh_token_enc'], "gsc:$id"), '1//REFRESH-SECRET');
    throws(GscException::class, function () use (&$s, $st) { Gsc::handleCallback($s, ['code' => 'AUTHCODE', 'state' => $st], 1); }, 'replay');
});
test('gsc callback: no refresh token / wrong scope / Google error => clear failure, nothing stored', function () use ($db, $TOKEN, $tokenOk) {
    $db->query('TRUNCATE sw_gsc_connections');
    foreach ([fn () => $tokenOk(), fn () => $tokenOk(['refresh_token' => 'r', 'scope' => 'openid email']), fn () => [400, [], '{"error":"invalid_grant","error_description":"Bad Request"}'], fn () => [500, [], 'oops']] as $resp) {
        fakeWeb([$TOKEN => $resp]); $s = []; Gsc::authUrl($s);
        throws(GscException::class, function () use (&$s) { Gsc::handleCallback($s, ['code' => 'c', 'state' => $s['gsc_oauth']['state']], 1); });
    }
    eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_gsc_connections')['n'], 0);
});
test('gsc properties: unverified ones hidden; choosing one that is not offered is refused', function () use ($conn, $TOKEN, $tokenOk) {
    $id = $conn(); $SITES = 'https://www.googleapis.com/webmasters/v3/sites';
    fakeWeb([$TOKEN => fn () => $tokenOk(), $SITES => [200, [], json_encode(['siteEntry' => [['siteUrl' => 'sc-domain:example.com', 'permissionLevel' => 'siteOwner'], ['siteUrl' => 'https://other.example/', 'permissionLevel' => 'siteUnverifiedUser'], ['siteUrl' => 'https://ok.example/', 'permissionLevel' => 'siteFullUser']]])]]);
    eq(array_column(Gsc::properties($id), 'site_url'), ['sc-domain:example.com', 'https://ok.example/']);
    Gsc::setProperty($id, 'https://ok.example/');
    throws(GscException::class, fn () => Gsc::setProperty($id, 'https://evil.example/'));
    $auth = array_values(array_filter($GLOBALS['__calls'], fn ($c) => $c['url'] === $SITES))[0]['headers'];
    ok(in_array('Authorization: Bearer ya29.ACCESS', $auth, true));
});
test('gsc sync: imports rows (ctr as %, dates, keywords cleaned), is idempotent, and skips junk', function () use ($conn, $db, $TOKEN, $tokenOk, $QUERY) {
    $id = $conn(); $db->query('TRUNCATE sw_analytics_keywords'); $d = gmdate('Y-m-d', strtotime('-5 days'));
    $rows = [['keys' => [$d, 'blue widgets'], 'clicks' => 12, 'impressions' => 340, 'ctr' => 0.0353, 'position' => 4.2], ['keys' => [$d, "evil\n<script>x"], 'clicks' => 1, 'impressions' => 2, 'ctr' => 0.5, 'position' => 9],
             ['keys' => ['not-a-date', 'junk'], 'clicks' => 1, 'impressions' => 1, 'ctr' => 1, 'position' => 1], ['keys' => [$d, '   '], 'clicks' => 1, 'impressions' => 1, 'ctr' => 1, 'position' => 1]];
    fakeWeb([$TOKEN => fn () => $tokenOk(), $QUERY => [200, [], json_encode(['rows' => $rows])]]);
    $r = Gsc::handleJob(['connection_id' => $id]); eq($r['data']['rows'], 2);
    $a = $db->fetchOne("SELECT * FROM sw_analytics_keywords WHERE keyword = 'blue widgets'");
    eq((int)$a['impressions'], 340); eq((int)$a['clicks'], 12); eq((float)$a['position'], 4.2); eq((float)$a['ctr'], 3.53); eq($a['date'], $d); eq((int)$a['project_id'], 0);
    ok($db->fetchOne("SELECT 1 x FROM sw_analytics_keywords WHERE keyword = 'evil <script>x'") !== null, 'control characters replaced; stored as text');
    $q = array_values(array_filter($GLOBALS['__calls'], fn ($c) => str_contains($c['url'], 'searchAnalytics')))[0];
    $body = json_decode($q['body'], true); eq($body['dimensions'], ['date', 'query']); eq($q['url'], $QUERY);
    fakeWeb([$TOKEN => fn () => $tokenOk(), $QUERY => [200, [], json_encode(['rows' => $rows])]]);
    Gsc::handleJob(['connection_id' => $id]); eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_analytics_keywords')['n'], 2, 're-sync does not duplicate');
    eq($db->fetchOne('SELECT last_status FROM sw_gsc_connections WHERE id = :i', ['i' => $id])['last_status'], 'ok');
});
test('gsc sync: revoked access => status "reauth" with a clear message; no property => clear failure; Google 403 explained', function () use ($conn, $db, $TOKEN, $tokenOk, $QUERY) {
    $id = $conn(); fakeWeb([$TOKEN => [400, [], '{"error":"invalid_grant"}']]);
    try { Gsc::handleJob(['connection_id' => $id]); ok(false); } catch (JobFailedException $e) { ok(str_contains($e->getMessage(), 'connect again'), $e->getMessage()); }
    eq($db->fetchOne('SELECT last_status FROM sw_gsc_connections WHERE id = :i', ['i' => $id])['last_status'], 'reauth');
    $id = $conn('r', ''); try { Gsc::handleJob(['connection_id' => $id]); ok(false); } catch (JobFailedException $e) { ok(str_contains($e->getMessage(), 'property')); }
    $id = $conn(); fakeWeb([$TOKEN => fn () => $tokenOk(), $QUERY => [403, [], '{"error":{"code":403}}']]);
    try { Gsc::handleJob(['connection_id' => $id]); ok(false); } catch (JobFailedException $e) { ok(str_contains($e->getMessage(), 'refused access')); }
    fakeWeb([$TOKEN => fn () => $tokenOk(), $QUERY => [503, [], 'x']]);
    throws(Sitewright\Core\JobRetryException::class, fn () => Gsc::handleJob(['connection_id' => $id]));
});
test('gsc disconnect: revokes at Google (best effort) and deletes the connection', function () use ($conn, $db) {
    $id = $conn(); fakeWeb(['https://oauth2.googleapis.com/revoke' => [200, [], '{}']]);
    Gsc::disconnect($id); eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_gsc_connections')['n'], 0);
    ok(str_contains($GLOBALS['__calls'][0]['body'], 'token='));
    $id = $conn(); fakeWeb(['https://oauth2.googleapis.com/revoke' => new Sitewright\Core\FetchException('down')]);
    Gsc::disconnect($id); eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_gsc_connections')['n'], 0, 'still deleted when Google is unreachable');
});
test('gsc scheduler: a daily sync only for connections with a property; none for "reauth"', function () use ($db) {
    $db->query('TRUNCATE sw_gsc_connections'); $db->query('TRUNCATE sw_jobs');
    $db->query("INSERT INTO sw_gsc_connections (user_id, refresh_token_enc, property_url, last_status, last_sync_at) VALUES (1,'x','a','ok', NOW() - INTERVAL 2 DAY), (1,'x','','new', NULL), (1,'x','c','reauth', NOW() - INTERVAL 3 DAY), (1,'x','d','ok', NOW())");
    eq(Gsc::scheduleDue(), 1); eq(Gsc::scheduleDue(), 0);
});

// ---- HTTP: permissions and the callback redirect ------------------------------------------------------------------------------
seedAdmin('root', 'root passphrase long here', 'admin'); seedAdmin('ed', 'editor passphrase long here', 'editor');
$srv = startServer(['SW_APP_KEY' => Config::get('app_key'), 'SW_GOOGLE_CLIENT_ID' => $googleCfg['client_id'], 'SW_GOOGLE_CLIENT_SECRET' => $googleCfg['client_secret'], 'SW_GOOGLE_REDIRECT_URI' => $googleCfg['redirect_uri']]);
test('gsc over HTTP: admin only; connect returns a Google URL; a callback with a wrong state redirects to ?gsc=error without contacting Google', function () use ($srv) {
    $e = new TestHttp($srv['base']); $e->login('ed', 'editor passphrase long here');
    foreach ([['GET', '/api/gsc'], ['POST', '/api/gsc/connect'], ['GET', '/api/gsc/callback?code=x&state=y'], ['POST', '/api/gsc/1/sync']] as [$m, $p]) { eq($e->api($m, $p, $m === 'GET' ? null : [])['status'], 403, "$m $p"); }
    $a = new TestHttp($srv['base']); $a->login('root', 'root passphrase long here');
    $st = $a->api('GET', '/api/gsc'); eq($st['json']['data']['configured'], true);
    $c = $a->api('POST', '/api/gsc/connect', []); eq($c['status'], 200); ok(str_starts_with($c['json']['data']['url'], 'https://accounts.google.com/o/oauth2/v2/auth?'));
    parse_str((string)parse_url($c['json']['data']['url'], PHP_URL_QUERY), $q);
    $bad = $a->api('GET', '/api/gsc/callback?code=abc&state=wrong'); eq($bad['status'], 302); ok(str_ends_with($bad['headers']['location'], '/analytics?gsc=error'), $bad['headers']['location'] ?? '');
    $again = $a->api('GET', '/api/gsc/callback?code=abc&state=' . $q['state']);   // the pending state was consumed by the failed attempt
    ok(str_ends_with($again['headers']['location'], '/analytics?gsc=error'), 'state is single-use even after a failed attempt');
});
