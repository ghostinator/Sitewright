<?php
use RankMathWebapp\Core\{SafeHttpClient, UrlRejectedException, BlockedAddressException, FetchException,
    TooManyRedirectsException, FetchTimeoutException, ResponseTooLargeException};

/** Client with scripted DNS and transport. $pages: url => [status, headers, body]; $dns: host => ips. */
function mkClient(array $pages = [], array $dns = [], array $cfgOver = [], ?array &$log = null): SafeHttpClient
{
    $log = [];
    $cfg = array_replace(RankMathWebapp\Core\Config::get('fetch'), $cfgOver);
    $resolver = static fn (string $h): array => $dns[$h] ?? ['93.184.216.34'];
    $transport = static function (array $r) use ($pages, &$log): array {
        $log[] = $r;
        if (!isset($pages[$r['url']])) { return ['status' => 404, 'headers' => [], 'body' => 'nf']; }
        $p = $pages[$r['url']];
        if ($p instanceof Throwable) { throw $p; }
        return ['status' => $p[0], 'headers' => $p[1], 'body' => $p[2]];
    };
    return new SafeHttpClient($resolver, $transport, $cfg);
}

// ---- address classification ------------------------------------------------
foreach ([
    '127.0.0.1', '127.255.255.254', '0.0.0.0', '10.1.2.3', '172.16.0.1', '172.31.255.255', '192.168.1.1', '169.254.169.254',
    '100.64.0.1', '100.127.255.255', '192.0.0.8', '192.0.2.1', '198.18.0.1', '198.51.100.7', '203.0.113.9', '224.0.0.1',
    '240.0.0.1', '255.255.255.255', '::1', '::', 'fd00::1', 'fd00:ec2::254', 'fe80::1', 'fc00::1', 'ff02::1',
    '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:169.254.169.254', '::127.0.0.1', '64:ff9b::7f00:1', '64:ff9b::a9fe:a9fe',
    '2002:7f00:1::1', '2002:c0a8:1::1', '2001:0:4136:e378:8000:63bf:3fff:fdd2', '2001:db8::1', '100::1',
] as $ip) {
    test("blocks $ip", fn () => ok(!SafeHttpClient::isPublicIp($ip), "$ip must be blocked"));
}
foreach (['93.184.216.34', '8.8.8.8', '1.1.1.1', '2606:4700:4700::1111', '2001:4860:4860::8888', '::ffff:8.8.8.8', '64:ff9b::808:808'] as $ip) {
    test("allows $ip", fn () => ok(SafeHttpClient::isPublicIp($ip), "$ip must be allowed"));
}
test('dev CIDR allow-list is explicit and exact', function () {
    ok(SafeHttpClient::isPublicIp('10.5.5.5', ['10.0.0.0/8']));
    ok(SafeHttpClient::isPublicIp('11.0.0.1', ['10.0.0.0/8']));
    ok(!SafeHttpClient::isPublicIp('192.168.0.1', ['10.0.0.0/8']));
    ok(SafeHttpClient::isPublicIp('::ffff:10.5.5.5', ['10.0.0.0/8']));
});

// ---- URL validation --------------------------------------------------------
foreach ([
    'file:///etc/passwd', 'gopher://127.0.0.1:6379/_x', 'dict://localhost:11211/stats', 'ftp://example.com/x', 'php://filter/resource=/etc/passwd',
    'phar:///tmp/x.phar', 'data:text/html,hi', 'javascript:alert(1)', 'http://user:pass@example.com/', 'http://@example.com/',
    'http:///nohost', "http://exa mple.com/", "http://example.com/\x00", str_repeat('a', 2100), '', 'http://exa\\mple.com/',
    'http://[::1]/', 'http://127.0.0.1/', 'http://localhost/', 'http://169.254.169.254/latest/meta-data/', 'http://[fd00:ec2::254]/',
    'http://[::ffff:127.0.0.1]/', 'http://0.0.0.0/', 'http://100.64.0.1/', 'http://10.0.0.1/', 'http://172.16.5.5/', 'http://192.168.0.10/',
    'http://2130706433/', 'http://0177.0.0.1/', 'http://0x7f.1/', 'http://0x7f000001/', 'http://127.1/', 'http://017700000001/',
    'http://example.com:22/', 'http://example.com:6379/', 'https://example.com:8443/',
] as $u) {
    test('rejects ' . substr(json_encode($u), 0, 60), function () use ($u) {
        $c = mkClient([], ['localhost' => ['127.0.0.1']]);
        throws(FetchException::class, fn () => $c->get($u));
    });
}
test('rejects hostname that resolves to a private IP', function () {
    $c = mkClient([], ['evil.example' => ['93.184.216.34', '10.0.0.5']]);
    throws(BlockedAddressException::class, fn () => $c->get('http://evil.example/'));
});
test('rejects hostname resolving to metadata address', fn () =>
    throws(BlockedAddressException::class, fn () => mkClient([], ['m.example' => ['169.254.169.254']])->get('https://m.example/')));
test('rejects unresolvable host', fn () =>
    throws(FetchException::class, fn () => mkClient([], ['nx.example' => []])->get('http://nx.example/')));
test('block-list and allow-list narrow but never bypass IP checks', function () {
    throws(BlockedAddressException::class, fn () => mkClient([], [], ['block_hosts' => ['bad.example']])->get('http://www.bad.example/'));
    throws(BlockedAddressException::class, fn () => mkClient([], [], ['allow_hosts' => ['ok.example']])->get('http://other.example/'));
    throws(BlockedAddressException::class, fn () => mkClient([], ['ok.example' => ['127.0.0.1']], ['allow_hosts' => ['ok.example']])->get('http://ok.example/'));
});

// ---- happy path, pinning, redirects, limits ---------------------------------
test('fetches public URL and pins validated IPs', function () {
    $c = mkClient(['https://example.com/' => [200, ['Content-Type' => 'text/html; charset=utf-8'], '<html>hi</html>']], [], [], $log);
    $r = $c->get('https://example.com/');
    eq($r['status'], 200); eq($r['bytes'], 15); eq($r['content_type'], 'text/html'); eq($r['redirects'], []);
    eq($log[0]['ips'], ['93.184.216.34']); eq($log[0]['host'], 'example.com'); eq($log[0]['port'], 443);
});
test('follows relative redirect and revalidates each hop', function () {
    $c = mkClient([
        'http://a.example/start' => [301, ['Location' => '/next'], ''],
        'http://a.example/next' => [302, ['Location' => 'https://b.example/final#x'], ''],
        'https://b.example/final' => [200, [], 'done'],
    ], [], [], $log);
    $r = $c->get('http://a.example/start');
    eq($r['final_url'], 'https://b.example/final'); eq($r['redirects'], ['http://a.example/start', 'http://a.example/next']); eq(count($log), 3);
});
test('redirect to loopback is blocked', function () {
    $c = mkClient(['http://a.example/' => [302, ['Location' => 'http://127.0.0.1/admin'], '']]);
    throws(BlockedAddressException::class, fn () => $c->get('http://a.example/'));
});
test('redirect to hostname resolving privately is blocked', function () {
    $c = mkClient(['http://a.example/' => [302, ['Location' => 'http://int.example/'], '']], ['int.example' => ['192.168.1.5']]);
    throws(BlockedAddressException::class, fn () => $c->get('http://a.example/'));
});
foreach (['file:///etc/passwd', 'gopher://x/', 'ftp://x/'] as $loc) {
    test("redirect to $loc is rejected", function () use ($loc) {
        $c = mkClient(['http://a.example/' => [302, ['Location' => $loc], '']]);
        throws(FetchException::class, fn () => $c->get('http://a.example/'));
    });
}
test('redirect limit enforced (5)', function () {
    $pages = [];
    for ($i = 0; $i < 10; $i++) { $pages["http://a.example/$i"] = [302, ['Location' => '/' . ($i + 1)], '']; }
    throws(TooManyRedirectsException::class, fn () => mkClient($pages)->get('http://a.example/0'));
});
test('exactly 5 redirects are allowed', function () {
    $pages = [];
    for ($i = 0; $i < 5; $i++) { $pages["http://a.example/$i"] = [302, ['Location' => '/' . ($i + 1)], '']; }
    $pages['http://a.example/5'] = [200, [], 'ok'];
    eq(mkClient($pages)->get('http://a.example/0')['body'], 'ok');
});
test('POST is never redirected', function () {
    $c = mkClient(['https://api.example/x' => [302, ['Location' => 'http://127.0.0.1/'], '']]);
    eq($c->request('POST', 'https://api.example/x', ['body' => '{}'])['status'], 302);
});
test('transport timeout / oversize errors propagate typed', function () {
    throws(FetchTimeoutException::class, fn () => mkClient(['http://a.example/' => new FetchTimeoutException('t')])->get('http://a.example/'));
    throws(ResponseTooLargeException::class, fn () => mkClient(['http://a.example/' => new ResponseTooLargeException('l')])->get('http://a.example/'));
});
test('expired deadline raises timeout before any request', function () {
    $c = mkClient(['http://a.example/' => [200, [], 'x']], [], [], $log);
    throws(FetchTimeoutException::class, fn () => $c->get('http://a.example/', ['deadline' => microtime(true) - 1]));
    eq(count($log), 0);
});
test('timeout passed to transport is capped by deadline and config', function () {
    $c = mkClient(['http://a.example/' => [200, [], 'x']], [], ['timeout' => 15], $log);
    $c->get('http://a.example/', ['deadline' => microtime(true) + 3]);
    ok($log[0]['timeout'] <= 3.0 && $log[0]['timeout'] > 1.0);
    ok($log[0]['connect_timeout'] <= 5.0);
});

// ---- real curl transport against a local server (no external network) ------
$serverPort = null; $proc = null;
$serverDir = sys_get_temp_dir() . '/rmw-fixture-' . getmypid();
@mkdir($serverDir);
file_put_contents($serverDir . '/router.php', <<<'PHP'
<?php
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($p === '/big') { header('Content-Type: text/html'); echo str_repeat('A', 300000); exit; }
if ($p === '/slow') { sleep(3); echo 'late'; exit; }
if ($p === '/redir') { header('Location: http://127.0.0.1:%PORT%/ok', true, 302); exit; }
if ($p === '/hostecho') { header('Content-Type: text/plain'); echo $_SERVER['HTTP_HOST'] . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''); exit; }
header('Content-Type: text/html'); echo '<html><title>fixture</title></html>';
PHP);
$sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es); $serverPort = (int)substr(strrchr((string)stream_socket_get_name($sock, false), ':'), 1); fclose($sock);
file_put_contents($serverDir . '/router.php', str_replace('%PORT%', (string)$serverPort, file_get_contents($serverDir . '/router.php')));
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$serverPort", $serverDir . '/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $serverPort)) { break; } usleep(100000); }
$real = static fn (array $over = []) => new SafeHttpClient(
    static fn (string $h): array => ['127.0.0.1'], null,
    array_replace(RankMathWebapp\Core\Config::get('fetch'), ['dev_allow_cidrs' => ['127.0.0.0/8'], 'allow_ports' => [$serverPort]], $over)
);
test('curl transport: pinned connection reaches validated IP for an unresolvable name', function () use ($real, $serverPort) {
    $r = $real()->get("http://pinned.test:$serverPort/hostecho");
    eq($r['status'], 200);
    ok(str_starts_with($r['body'], "pinned.test:$serverPort|RankMathWebapp-SEO/1.0"), $r['body']);
});
test('curl transport: size cap aborts streaming download', function () use ($real, $serverPort) {
    throws(ResponseTooLargeException::class, fn () => $real()->get("http://pinned.test:$serverPort/big", ['max_bytes' => 100000]));
});
test('curl transport: timeout honoured', function () use ($real, $serverPort) {
    $t = microtime(true);
    throws(FetchTimeoutException::class, fn () => $real()->get("http://pinned.test:$serverPort/slow", ['timeout' => 1.0]));
    ok(microtime(true) - $t < 2.5);
});
test('curl transport: redirect hop is revalidated (dev CIDR removed => blocked)', function () use ($serverPort) {
    // First hop allowed via dev CIDR, but the redirect target 127.0.0.1 literal is checked against the same list: allowed here,
    // so assert the redirect IS followed when permitted and counted.
    $c = new SafeHttpClient(static fn ($h) => ['127.0.0.1'], null,
        array_replace(RankMathWebapp\Core\Config::get('fetch'), ['dev_allow_cidrs' => ['127.0.0.0/8'], 'allow_ports' => [$serverPort]]));
    $r = $c->get("http://pinned.test:$serverPort/redir");
    eq(count($r['redirects']), 1); eq($r['status'], 200);
});
test('curl transport: loopback refused by default (no dev CIDR)', function () use ($serverPort) {
    $c = new SafeHttpClient(static fn ($h) => ['127.0.0.1'], null, array_replace(RankMathWebapp\Core\Config::get('fetch'), ['allow_ports' => [$serverPort]]));
    throws(BlockedAddressException::class, fn () => $c->get("http://pinned.test:$serverPort/ok"));
});
if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
array_map('unlink', glob($serverDir . '/*') ?: []); @rmdir($serverDir);
