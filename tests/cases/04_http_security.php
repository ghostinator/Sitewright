<?php
use RankMathWebapp\Core\{App, Router};

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }

$db = freshDb();
seedAdmin();
$srv = startServer();
$base = $srv['base'];
$GLOBALS['__srvbase'] = $base;

// ---- AUTH-001: every route of the real table is denied without a session ----------------------
$table = App::routes();
test('route table is non-trivial', fn () => ok(count($table) >= 40, 'routes: ' . count($table)));
foreach ($table as $row) {
    [$method, $path] = $row;
    $opts = $row[3] ?? [];
    $public = !empty($opts['public']);
    test(($public ? 'PUBLIC    ' : 'PROTECTED ') . "$method $path", function () use ($base, $method, $path, $public, $opts) {
        $r = (new TestHttp($base))->req($method, Router::samplePath($path), $method === 'GET' ? null : [], ['Content-Type' => !empty($opts['upload']) ? 'multipart/form-data' : 'application/json']);
        $public ? ok($r['status'] !== 401, 'public route must not demand auth') : eq($r['status'], 401, 'unauthenticated must be 401');
    });
}
test('unknown route is 404 JSON, wrong method is 405 with Allow, OPTIONS gets no CORS', function () use ($base) {
    $h = new TestHttp($base);
    eq($h->req('GET', '/api/nope')['status'], 404);
    $r = $h->req('DELETE', '/api/health');
    eq($r['status'], 405);
    $o = $h->req('OPTIONS', '/api/health', null, ['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'POST']);
    eq($o['status'], 405);
    ok(!isset($o['headers']['access-control-allow-origin']), 'no CORS headers');
    ok(!isset($h->req('GET', '/api/health')['headers']['access-control-allow-origin']));
});

// ---- login / session -------------------------------------------------------------------------
test('login: bad credentials 401 with generic message; unknown user identical', function () use ($base) {
    $h = new TestHttp($base);
    $a = $h->login('admin', 'wrong-password-123');
    $b = (new TestHttp($base))->login('nobody', 'wrong-password-123');
    eq($a['status'], 401); eq($b['status'], 401);
    eq($a['json']['message'], $b['json']['message']);
});
test('login: requires CSRF token even for the login request', function () use ($base) {
    $h = new TestHttp($base);
    $h->bootstrap();
    eq($h->req('POST', '/api/auth/login', ['username' => 'admin', 'password' => 'correct horse battery'], ['Content-Type' => 'application/json'])['status'], 403);
    eq($h->req('POST', '/api/auth/login', ['username' => 'admin', 'password' => 'correct horse battery'], ['Content-Type' => 'application/json', 'X-CSRF-Token' => 'forged'])['status'], 403);
});
$user = new TestHttp($base);
test('login success: session id regenerated, token rotated, cookie flags set', function () use ($user) {
    $cfg = $user->bootstrap();
    $before = $user->cookies['rmw_session'] ?? '';
    $firstToken = $user->csrf;
    ok($before !== '' && $firstToken, 'pre-login session + token');
    $r = $user->req('POST', '/api/auth/login', ['username' => 'admin', 'password' => 'correct horse battery'], ['Content-Type' => 'application/json', 'X-CSRF-Token' => $firstToken]);
    eq($r['status'], 200);
    ok(($user->cookies['rmw_session'] ?? '') !== $before, 'session id must change on login');
    ok($r['json']['data']['csrf_token'] !== $firstToken, 'CSRF token must rotate on login');
    $user->csrf = $r['json']['data']['csrf_token'];
    $sc = implode(' | ', $r['raw_set_cookie']);
    ok(stripos($sc, 'HttpOnly') !== false && stripos($sc, 'SameSite=Lax') !== false, "cookie flags: $sc");
});
test('authenticated GET works; page now renders the app shell', function () use ($user) {
    eq($user->api('GET', '/api/auth/me')['json']['data']['user']['username'], 'admin');
    $p = $user->req('GET', '/seo-analysis');
    eq($p['status'], 200); ok(str_contains($p['body'], 'id="view-seo-analysis"') && !str_contains($p['body'], 'id="login-form"'));
});

// ---- CSRF-001 --------------------------------------------------------------------------------
test('CSRF: state-changing request without/with wrong token is 403', function () use ($user) {
    eq($user->req('POST', '/api/404-monitor/log', ['uri' => '/x'], ['Content-Type' => 'application/json'])['status'], 403);
    eq($user->req('POST', '/api/404-monitor/log', ['uri' => '/x'], ['Content-Type' => 'application/json', 'X-CSRF-Token' => 'wrong'])['status'], 403);
    eq($user->req('DELETE', '/api/404-monitor/1', [], ['Content-Type' => 'application/json'])['status'], 403);
    eq($user->api('POST', '/api/404-monitor/log', ['uri' => '/ok'])['status'], 200);
});
test('CSRF: foreign Origin / cross-site fetch metadata rejected even with a valid token', function () use ($user) {
    eq($user->api('POST', '/api/404-monitor/log', ['uri' => '/x'], ['Origin' => 'https://evil.example'])['status'], 403);
    eq($user->api('POST', '/api/404-monitor/log', ['uri' => '/x'], ['Origin' => 'null'])['status'], 403);
    eq($user->api('POST', '/api/404-monitor/log', ['uri' => '/x'], ['Sec-Fetch-Site' => 'cross-site'])['status'], 403);
    eq($user->api('POST', '/api/404-monitor/log', ['uri' => '/x'], ['Origin' => $GLOBALS['__srvbase']])['status'] ?? 200, 200);
});
test('CSRF: non-JSON content type rejected for JSON routes', function () use ($user) {
    eq($user->req('POST', '/api/404-monitor/log', null, ['Content-Type' => 'text/plain', 'X-CSRF-Token' => $user->csrf], '{"uri":"/x"}')['status'], 415);
    eq($user->req('POST', '/api/404-monitor/log', null, ['Content-Type' => 'application/x-www-form-urlencoded', 'X-CSRF-Token' => $user->csrf], 'uri=/x')['status'], 415);
});
test('Host header validation (DNS rebinding): foreign Host rejected on API and page', function () use ($user) {
    eq($user->api('GET', '/api/auth/me', null, ['Host' => 'evil.example'])['status'], 400);
    eq($user->req('GET', '/', null, ['Host' => 'evil.example'])['status'], 400);
    eq($user->api('GET', '/api/auth/me', null, ['Host' => 'localhost:1234'])['status'], 200);
});

// ---- SQLI-001 / FUNC-016 ---------------------------------------------------------------------
foreach ([
    'order_by=id;SELECT SLEEP(4)', 'order_by=(SELECT SLEEP(4))', 'order_by=IF(1=1,SLEEP(4),0)', 'order=ASC,(SELECT SLEEP(4))', 'order=DESC;DROP TABLE rm_users',
    'per_page=1;SELECT SLEEP(4)', 'per_page=1%20UNION%20SELECT%20password,2,3,4,5,6,7,8,9,10%20FROM%20rm_users', 'page=1;SELECT SLEEP(4)', 'page=-5', 'page=abc', 'per_page=0', 'per_page=100000', 'project_id=1%20OR%201=1',
] as $qs) {
    test("getLogs injection payload neutralised: $qs", function () use ($user, $qs) {
        $qs = implode('&', array_map(fn ($kv) => explode('=', $kv, 2)[0] . '=' . rawurlencode(rawurldecode(explode('=', $kv, 2)[1] ?? '')), explode('&', $qs)));
        $r = $user->api('GET', '/api/404-monitor/logs?' . $qs);
        eq($r['status'], 200); ok($r['ms'] < 3000, 'must not execute SLEEP');
        ok(!str_contains($r['body'], 'password'), 'no leakage');
        ok($r['json']['data']['pagination']['per_page'] >= 1 && $r['json']['data']['pagination']['per_page'] <= 100);
        ok($r['json']['data']['pagination']['current_page'] >= 1);
    });
}
test('users table still intact after payloads', fn () => eq((int)$db->fetchOne('SELECT COUNT(*) c FROM rm_users')['c'], 1));

// ---- headers / page ----------------------------------------------------------------------------
test('security headers on API and page; CSP has no unsafe-inline/eval', function () use ($user, $base) {
    $a = $user->api('GET', '/api/auth/me');
    ok(($a['headers']['x-content-type-options'] ?? '') === 'nosniff' && str_contains($a['headers']['cache-control'] ?? '', 'no-store'));
    $p = $user->req('GET', '/');
    $csp = $p['headers']['content-security-policy'] ?? '';
    foreach (["default-src 'self'", "script-src 'self'", "frame-ancestors 'none'", "base-uri 'self'", "form-action 'self'"] as $d) { ok(str_contains($csp, $d), "CSP lacks $d"); }
    ok(!str_contains($csp, 'unsafe-inline') && !str_contains($csp, 'unsafe-eval'));
    ok(($p['headers']['x-frame-options'] ?? '') === 'DENY' && isset($p['headers']['referrer-policy']) && isset($p['headers']['permissions-policy']));
    ok(!isset($p['headers']['x-xss-protection']));
});
test('page has no inline event handlers or style attributes (CSP-compatible)', function () use ($user) {
    $b = $user->req('GET', '/')['body'];
    ok(!preg_match('/\son[a-z]+\s*=/i', $b), 'inline handler found');
    ok(!preg_match('/\sstyle\s*=/i', $b), 'inline style found');
    ok(substr_count($b, '<script') === 2, 'only config JSON + app.js script tags');
});
test('unknown view is a real 404; deep links serve the shell', function () use ($user) {
    eq($user->req('GET', '/nonexistent')['status'], 404);
    eq($user->req('GET', '/does/not/exist')['status'], 404);
    foreach (['/', '/seo-analysis', '/competitor', '/analytics', '/content-ai', '/local-seo', '/image-seo', '/sitemaps', '/404-monitor', '/redirections'] as $p) {
        $r = $user->req('GET', $p);
        eq($r['status'], 200, $p); ok(str_contains($r['body'], 'assets/js/app.js') && str_contains($r['body'], 'assets/css/style.css'), $p);
    }
    eq($user->req('GET', '/assets/js/app.js')['status'], 200);
    eq($user->req('GET', '/assets/css/style.css')['status'], 200);
});
test('config JSON in the page cannot be broken out of (SEC-011)', function () use ($user) {
    $r = $user->req('GET', '/seo-analysis?x=</script><script>alert(1)</script>');
    ok(substr_count($r['body'], '<script') === 2);
});

// ---- logout / expiry ---------------------------------------------------------------------------
test('logout invalidates the session', function () use ($user) {
    eq($user->api('POST', '/api/auth/logout')['status'], 200);
    eq($user->api('GET', '/api/auth/me')['status'], 401);
});

// ---- login throttling --------------------------------------------------------------------------
test('login throttling: 429 after repeated failures; correct password blocked while throttled', function () use ($base) {
    $statuses = [];
    for ($i = 0; $i < 7; $i++) { $statuses[] = (new TestHttp($base))->login('admin', "wrong-$i-password")['status']; }
    eq(array_slice($statuses, 0, 5), [401, 401, 401, 401, 401], 'first 5 attempts get 401');
    ok(in_array(429, array_slice($statuses, 5), true), 'later attempts get 429: ' . implode(',', $statuses));
    $r = (new TestHttp($base))->login('admin', 'correct horse battery');
    eq($r['status'], 429); ok(isset($r['headers']['retry-after']));
});
stopServer($srv);

// ---- SEC-008 / FUNC-019: DB outage returns generic errors only ----------------------------------
$bad = startServer(['RMW_DB_PASS' => 'definitely-wrong', 'RMW_DB_HOST' => testDbConfig()['host']]);
test('database failure: generic JSON 500 with request id, no internals leaked', function () use ($bad) {
    $h = new TestHttp($bad['base']);
    $page = $h->bootstrap();                       // login page does not need the DB
    eq($page['_response']['status'], 200);
    $r = $h->req('POST', '/api/auth/login', ['username' => 'admin', 'password' => 'x'], ['Content-Type' => 'application/json', 'X-CSRF-Token' => (string)$h->csrf]);
    eq($r['status'], 500); eq($r['json']['message'], 'Internal error'); ok(!empty($r['json']['request_id']));
    foreach (['SQLSTATE', 'Access denied', 'definitely-wrong', 'PDO', '.php', testDbConfig()['host']] as $needle) { ok(!str_contains($r['body'], $needle), "leaked $needle"); }
});
stopServer($bad);
