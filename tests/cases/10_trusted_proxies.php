<?php
use Sitewright\Core\{Config, ConfigException, Request};

/** Client IP as seen by the app for a given socket peer and X-Forwarded-For. */
function ipFor(string $remote, ?string $xff, array $trusted): string
{
    Config::set('trusted_proxies', $trusted);
    Request::fake(['ip' => $remote, 'headers' => $xff === null ? [] : ['X-Forwarded-For' => $xff]]);
    return Request::ip();
}

test('proxies: nothing trusted by default; X-Forwarded-For from anyone is ignored', function () {
    eq(Config::defaults()['trusted_proxies'], []);
    eq(ipFor('198.51.100.7', '1.2.3.4', []), '198.51.100.7');
});
test('proxies: spoofed X-Forwarded-For from an UNTRUSTED peer is ignored (cannot dodge the login throttle)', function () {
    eq(ipFor('198.51.100.7', '1.2.3.4', ['10.0.0.0/8']), '198.51.100.7');
});
test('proxies: trusted peer => client address from X-Forwarded-For', function () {
    eq(ipFor('10.0.0.5', '203.0.113.50', ['10.0.0.0/8']), '203.0.113.50');
    eq(ipFor('10.0.0.5', null, ['10.0.0.0/8']), '10.0.0.5', 'no header => the proxy itself');
});
test('proxies: two-proxy chain resolves to the real client, and a forged left-hand entry does not win', function () {
    eq(ipFor('10.0.0.5', '203.0.113.50, 10.0.0.9', ['10.0.0.0/8']), '203.0.113.50');
    eq(ipFor('10.0.0.5', '9.9.9.9, 203.0.113.50, 10.0.0.9', ['10.0.0.0/8']), '203.0.113.50', 'client-added 9.9.9.9 sits left of the first untrusted hop');
    eq(ipFor('10.0.0.5', '10.0.0.7, 10.0.0.9', ['10.0.0.0/8']), '10.0.0.7', 'all hops trusted => leftmost');
});
test('proxies: IPv6 peers and clients, and ::ffff: mapped peers', function () {
    eq(ipFor('fd00::1', '2001:db8::77', ['fd00::/8']), '2001:db8::77');
    eq(ipFor('::ffff:10.0.0.5', '203.0.113.50', ['10.0.0.0/8']), '203.0.113.50');
    eq(ipFor('2001:db8::9', '1.2.3.4', ['fd00::/8']), '2001:db8::9');
});
test('proxies: a garbage entry in the chain falls back to the proxy address (never to attacker text)', function () {
    eq(ipFor('10.0.0.5', 'not-an-ip', ['10.0.0.0/8']), '10.0.0.5');
    eq(ipFor('10.0.0.5', "1.2.3.4, <script>", ['10.0.0.0/8']), '10.0.0.5');
});
test('proxies: configuration is validated at boot; trusting everything is refused', function () {
    $f = tempnam(sys_get_temp_dir(), 'swcfg');
    foreach ([['0.0.0.0/0', 'every client'], ['::/0', 'every client'], ['nonsense', 'Invalid trusted_proxies'], ['10.0.0.0/33', 'Invalid trusted_proxies'], ['10.0.0.0/x', 'Invalid trusted_proxies']] as [$bad, $msg]) {
        file_put_contents($f, "<?php return ['trusted_proxies' => ['$bad']];");
        Config::useFile($f);
        $threw = null;
        try { Config::get('debug'); } catch (ConfigException $e) { $threw = $e->getMessage(); }
        ok($threw !== null && str_contains($threw, $msg), "$bad should be rejected, got: " . var_export($threw, true));
    }
    file_put_contents($f, "<?php return ['trusted_proxies' => ['10.0.0.0/8', '192.0.2.1', 'fd00::/8']];");
    Config::useFile($f);
    eq(Config::get('trusted_proxies'), ['10.0.0.0/8', '192.0.2.1', 'fd00::/8']);
    putenv('SW_TRUSTED_PROXIES=172.16.0.0/12, 192.0.2.9'); Config::useFile($f);
    eq(Config::get('trusted_proxies'), ['172.16.0.0/12', '192.0.2.9'], 'env overrides file');
    putenv('SW_TRUSTED_PROXIES'); unlink($f); Config::useFile(null);
    Request::reset();
});
