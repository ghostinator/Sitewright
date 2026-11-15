<?php
if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb();
seedAdmin();
$srv = startServer();
$base = $srv['base'];

test('rate limit: expensive endpoints answer 429 + Retry-After after the per-minute budget (DOS-001)', function () use ($base) {
    $h = new TestHttp($base);
    eq($h->login('admin', 'correct horse battery')['status'], 200);
    $codes = [];
    for ($i = 0; $i < 12; $i++) { $r = $h->api('POST', '/api/content-ai/generate', ['keyword' => "kw$i"]); $codes[] = $r['status']; }
    eq(array_slice($codes, 0, 10), array_fill(0, 10, 200)); eq($codes[10], 429); eq($r['status'], 429);
    ok(isset($r['headers']['retry-after']));
    eq($h->api('GET', '/api/content-ai/history')['status'], 200, 'cheap endpoints are unaffected');
});
test('request body limit: oversized JSON is rejected with 413 (DOS-001)', function () use ($base) {
    $h = new TestHttp($base);
    eq($h->login('admin', 'correct horse battery')['status'], 200);
    $r = $h->req('POST', '/api/404-monitor/log', null, ['Content-Type' => 'application/json', 'X-CSRF-Token' => $h->csrf], json_encode(['uri' => '/' . str_repeat('a', 2000000)]));
    eq($r['status'], 413);
    eq($h->req('POST', '/api/404-monitor/log', null, ['Content-Type' => 'application/json', 'X-CSRF-Token' => $h->csrf], '{not json')['status'], 400);
    eq($h->req('POST', '/api/404-monitor/log', null, ['Content-Type' => 'application/json', 'X-CSRF-Token' => $h->csrf], '"a string"')['status'], 400);
});
test('password change ends existing sessions', function () use ($base, $db) {
    $h = new TestHttp($base);
    seedAdmin('second', 'second user passphrase');
    eq($h->login('second', 'second user passphrase')['status'], 200);
    eq($h->api('GET', '/api/auth/me')['status'], 200);
    $db->update('users', ['password' => password_hash('changed passphrase!!', PASSWORD_DEFAULT)], ['username' => 'second']);
    eq($h->api('GET', '/api/auth/me')['status'], 401);
});
stopServer($srv);

$cfgFile = tempnam(sys_get_temp_dir(), 'cfg');
file_put_contents($cfgFile, "<?php return ['session' => ['idle_timeout' => 2, 'absolute_timeout' => 4]];");
$short = startServer(['RMW_CONFIG_FILE' => $cfgFile]);
test('session idle timeout (server-side) logs the user out; activity keeps it alive', function () use ($short) {
    $h = new TestHttp($short['base']);
    eq($h->login('admin', 'correct horse battery')['status'], 200);
    sleep(1); eq($h->api('GET', '/api/auth/me')['status'], 200);
    sleep(1); eq($h->api('GET', '/api/auth/me')['status'], 200, 'activity extends the idle window');
    sleep(3); eq($h->api('GET', '/api/auth/me')['status'], 401, 'idle for longer than idle_timeout');
});
test('session absolute timeout applies even with constant activity', function () use ($short) {
    $h = new TestHttp($short['base']);
    eq($h->login('admin', 'correct horse battery')['status'], 200);
    for ($i = 0; $i < 5; $i++) { sleep(1); $s = $h->api('GET', '/api/auth/me')['status']; }
    eq($s, 401);
});
stopServer($short); @unlink($cfgFile);
