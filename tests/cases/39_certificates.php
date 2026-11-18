<?php
use Sitewright\Core\{Acme, Certificates, Config, Crypto, DnsProvider, DnsProviderException, ResponseException};

// Certificate manager: the service, the admin API, the endpoints servers fetch from, and the pull script itself against the real server.
if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb(); $key = Crypto::generateKey(); Config::set('app_key', $key);
seedAdmin('root', 'root passphrase long here', 'admin'); seedAdmin('ed', 'editor passphrase long here', 'editor');
$srv = startServer(['SW_APP_KEY' => $key]); $base = $srv['base'];
$as = function (string $u, string $p) use ($base) { $h = new TestHttp($base); eq($h->login($u, $p)['status'], 200); return $h; };
Config::set('alerts', ['email_to' => [], 'teams_webhook' => 'https://example.webhook.office.com/x', 'telegram_token' => '', 'telegram_chat_id' => '', 'cooldown' => 0, 'login_failures' => 3, 'events' => ['cert_issued', 'cert_issue_failed', 'cert_target_stale']]);
DnsProvider::$factories['fake'] = static fn (array $c) => new class($c) extends DnsProvider {
    public function __construct(private array $c) {}
    public function addTxt(string $name, string $value): string { return $name; }
    public function removeTxt(string $handle): void {}
    public function verify(): string { if (($this->c['bad'] ?? '') === '1') { throw new DnsProviderException('no good'); } if (isset($this->c['leak'])) { throw new DnsProviderException('refused for key ' . $this->c['leak'] . ' sorry'); } return 'fake ok'; }
    public function domains(): ?array { if (($this->c['down'] ?? '') === '1') { throw new DnsProviderException('api down'); } return isset($this->c['zones']) ? explode(',', $this->c['zones']) : null; }
    public function elsewhere(): array { return isset($this->c['elsewhere']) ? ['moved.example' => $this->c['elsewhere']] : []; }
};
$reset = function () use ($db) { foreach (['dns_providers', 'acme_accounts', 'certificates', 'cert_targets', 'jobs', 'alert_log', 'rate_limits'] as $t) { $db->query("TRUNCATE sw_$t"); } };
/** A certificate row with a self-signed certificate (or a mismatching key) standing in for one from Let's Encrypt. */
$seed = function (string $name = 'Main', int $days = 90, bool $wrongKey = false) use ($db, $key) {
    $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $csr = openssl_csr_new(['commonName' => 'seed.example.test'], $k); $crt = openssl_csr_sign($csr, null, $k, $days); openssl_x509_export($crt, $chain); openssl_pkey_export($k, $kp);
    if ($wrongKey) { $o = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']); openssl_pkey_export($o, $kp); }
    $row = $db->fetchOne('SELECT id FROM sw_dns_providers LIMIT 1'); $pid = $row ? (int)$row['id'] : Certificates::createProvider('P ' . bin2hex(random_bytes(2)), 'fake', []);
    $id = Certificates::create($name, ['seed.example.test'], $pid);
    $info = Acme::describe($chain);
    $db->update('certificates', ['state' => 'issued', 'directory' => Certificates::directoryUrl(), 'key_enc' => Crypto::encrypt($kp, 'cert:' . $id), 'chain_pem' => $chain, 'not_before' => $info['not_before'], 'not_after' => $info['not_after'],
        'fingerprint' => $info['fingerprint'], 'issued_at' => gmdate('Y-m-d H:i:s', time() - 5 * 86400)], ['id' => $id]);
    return ['id' => $id, 'chain' => $chain, 'key' => $kp, 'fp' => $info['fingerprint']];
};

test('DNS accounts: the secrets are encrypted at rest and bound to the account; bad input is refused; the check records its outcome', function () use ($reset, $db) {
    $reset();
    $id = Certificates::createProvider('Cloudflare main', 'cloudflare', ['token' => 'cf_' . str_repeat('Zz9', 10)]);
    $raw = $db->fetchOne('SELECT config_enc FROM sw_dns_providers WHERE id = :i', ['i' => $id])['config_enc'];
    ok(!str_contains($raw, 'Zz9') && !str_contains($raw, 'token'), 'nothing readable in the table');
    eq(json_decode(Crypto::decrypt($raw, 'dns:' . $id), true), ['token' => 'cf_' . str_repeat('Zz9', 10)]);
    throws(\Throwable::class, fn () => Crypto::decrypt($raw, 'dns:' . ($id + 1)));
    foreach ([['n', 'nope', []], ['', 'cloudflare', ['token' => 'cf_' . str_repeat('Zz9', 10)]], ['x', 'cloudflare', []], ['x', 'cloudflare', ['token' => 'has space in it 1234567890']], ['Cloudflare main', 'cloudflare', ['token' => 'cf_' . str_repeat('Yy8', 10)]]] as [$n, $t, $s]) {
        throws(ResponseException::class, fn () => Certificates::createProvider($n, $t, $s));
    }
    $f = Certificates::createProvider('Fake ok', 'fake', []); eq(Certificates::testProvider($f), ['ok' => true, 'message' => 'fake ok']);
    $g = Certificates::createProvider('Fake bad', 'fake', ['bad' => '1']); eq(Certificates::testProvider($g)['ok'], false);
    eq([(int)$db->fetchOne('SELECT check_ok c FROM sw_dns_providers WHERE id = :i', ['i' => $f])['c'], (int)$db->fetchOne('SELECT check_ok c FROM sw_dns_providers WHERE id = :i', ['i' => $g])['c']], [1, 0]);
});

test('certificates: names are cleaned and checked (wildcards allowed, line breaks, duplicates, too many), the provider must exist, names are unique', function () use ($reset, $db) {
    $reset(); $p = Certificates::createProvider('Fake', 'fake', []);
    $id = Certificates::create('Web', ['Example.COM', 'example.com', '*.example.com'], $p);
    eq(json_decode($db->fetchOne('SELECT names FROM sw_certificates WHERE id = :i', ['i' => $id])['names'], true), ['example.com', '*.example.com']);
    foreach ([['Web2', ['a.example.com', "b.example.com\nDNS:evil.com"], $p], ['Web2', [], $p], ['Web2', array_map(fn ($i) => "h$i.example.com", range(1, 21)), $p], ['Web2', ['a.example.com'], $p + 99], ['Web', ['a.example.com'], $p], ['bad/name', ['a.example.com'], $p], ['Web2', ['localhost'], $p]] as [$n, $names, $pid]) {
        throws(ResponseException::class, fn () => Certificates::create($n, $names, $pid));
    }
});

test('a failed issue is recorded with the reason and a growing pause; the owner is told; a certificate that is still valid keeps being served', function () use ($reset, $db, $seed) {
    $reset(); $p = Certificates::createProvider('Fake', 'fake', []); $id = Certificates::create('Web', ['example.com'], $p);
    Config::set('acme.directory', 'https://acme.example.test/dir');
    fakeWeb(['https://acme.example.test/dir' => [503, [], 'down']]);
    $now = 1_800_000_000; $pauses = [];
    for ($i = 0; $i < 4; $i++) { $r = Certificates::issue($id, $now); eq($r['ok'], false); $c = $db->fetchOne('SELECT * FROM sw_certificates WHERE id = :i', ['i' => $id]); $pauses[] = strtotime($c['next_attempt_at'] . ' UTC') - $now; }
    eq($pauses, [1800, 3600, 7200, 14400], 'the pause doubles'); eq([$c['state'], (int)$c['attempts']], ['failed', 4]); ok(str_contains($c['last_error'], 'ACME directory'), $c['last_error']);
    $ev = array_map(fn ($x) => json_decode($x['payload'], true), $db->fetchAll("SELECT payload FROM sw_jobs WHERE type = 'alert'")); ok(count($ev) === 4 && $ev[0]['event'] === 'cert_issue_failed' && $ev[0]['level'] === 'error', json_encode($ev[0]));
    $s = $seed('Valid'); fakeWeb(['https://acme.example.test/dir' => [503, [], 'down']]);
    $r = Certificates::issue($s['id']); $c = $db->fetchOne('SELECT * FROM sw_certificates WHERE id = :i', ['i' => $s['id']]);
    eq([$r['ok'], $c['state'], $c['chain_pem']], [false, 'issued', $s['chain']]); $last = json_decode($db->fetchOne("SELECT payload FROM sw_jobs WHERE type = 'alert' ORDER BY id DESC LIMIT 1")['payload'], true);
    eq($last['level'], 'warning'); ok(str_contains($last['message'], 'still valid'), $last['message']);
    Config::set('acme.directory', '');
});

test('servers: a token is shown once and only its hash is kept; the right token (and an allowed address) is needed; a pull is recorded', function () use ($reset, $db, $seed) {
    $reset(); $s = $seed(); $t = Certificates::createTarget($s['id'], 'Web 1');
    ok(preg_match('/^swc_[a-f0-9]{48}$/', $t['token']) === 1); $row = $db->fetchOne('SELECT * FROM sw_cert_targets WHERE id = :i', ['i' => $t['id']]);
    ok(!str_contains(json_encode($row), $t['token']) && $row['token_hash'] === hash('sha256', $t['token']));
    $ok = fn (?string $auth, string $ip = '127.0.0.1', ?int $id = null) => Certificates::authTarget($id ?? $t['id'], $auth, $ip) !== null;
    eq([$ok('Bearer ' . $t['token']), $ok(null), $ok('Bearer swc_' . str_repeat('0', 48)), $ok($t['token']), $ok('Bearer ' . $t['token'], '127.0.0.1', $t['id'] + 1), $ok('Bearer ' . $t['token'] . 'x')], [true, false, false, false, false, false]);
    eq($db->fetchOne('SELECT last_pull_ip FROM sw_cert_targets WHERE id = :i', ['i' => $t['id']])['last_pull_ip'], '127.0.0.1');
    $r = Certificates::createTarget($s['id'], 'Web 2', '10.0.0.0/8, 192.168.1.5, 2001:db8::/32'); $a = fn (string $ip) => Certificates::authTarget($r['id'], 'Bearer ' . $r['token'], $ip) !== null;
    eq([$a('10.1.2.3'), $a('192.168.1.5'), $a('192.168.1.6'), $a('2001:db8::7'), $a('127.0.0.1')], [true, true, false, true, false]);
    foreach (['not an address', '10.0.0.0/8, evil', "1.2.3.4\n5.6.7.8"] as $bad) { throws(ResponseException::class, fn () => Certificates::createTarget($s['id'], 'X', $bad)); }
    throws(ResponseException::class, fn () => Certificates::createTarget($s['id'] + 99, 'X')); throws(ResponseException::class, fn () => Certificates::createTarget($s['id'], '../x'));
    eq(Certificates::bundle($s['id'])['key'], $s['key']); $n = Certificates::create('Empty', ['empty.example.test'], (int)$db->fetchOne('SELECT id FROM sw_dns_providers LIMIT 1')['id']); eq(Certificates::bundle($n), null);
});

test('stale servers: reported once per certificate version, only when the certificate is old enough and the server is behind', function () use ($reset, $db, $seed) {
    $reset(); $s = $seed(); $t = Certificates::createTarget($s['id'], 'Web 1'); $now = time();
    eq(Certificates::checkTargets($now), 1, 'issued 5 days ago, never installed'); eq(Certificates::checkTargets($now), 0, 'said once');
    Certificates::reportInstalled($t['id'], $s['fp'], 'ok', ''); $db->query('UPDATE sw_cert_targets SET stale_alerted = NULL'); eq(Certificates::checkTargets($now), 0, 'up to date');
    $db->query("UPDATE sw_certificates SET issued_at = UTC_TIMESTAMP()"); Certificates::reportInstalled($t['id'], str_repeat('0', 64), 'ok', ''); eq(Certificates::checkTargets($now), 0, 'issued just now: too early to complain');
    $n = json_decode($db->fetchOne("SELECT payload FROM sw_jobs WHERE type = 'alert' ORDER BY id LIMIT 1")['payload'], true); ok(str_contains($n['message'], 'Web 1') && str_contains($n['message'], 'sw-cert-pull'), $n['message']);
});

test('admin API: only administrators; secrets and keys are never listed; tokens are shown once; a DNS account in use cannot be deleted', function () use ($as, $reset, $db, $base) {
    $reset(); $a = $as('root', 'root passphrase long here'); $e = $as('ed', 'editor passphrase long here');
    eq($e->api('GET', '/api/certificates')['status'], 403); eq($e->api('POST', '/api/dns-accounts', ['name' => 'x', 'type' => 'cloudflare'])['status'], 403);
    $r = $a->api('POST', '/api/dns-accounts', ['name' => 'CF', 'type' => 'cloudflare', 'secrets' => ['token' => 'cf_' . str_repeat('Qq7', 10)]]); eq($r['status'], 201, $r['body']); $pid = $r['json']['data']['id'];
    ok(!str_contains($r['body'], 'Qq7'), 'the answer does not repeat the secret');
    eq($a->api('POST', '/api/dns-accounts', ['name' => 'CF', 'type' => 'cloudflare', 'secrets' => ['token' => 'cf_' . str_repeat('Rr6', 10)]])['status'], 400);
    $r = $a->api('POST', '/api/certificates', ['name' => 'Web', 'names' => "example.com\n*.example.com", 'dns_provider_id' => $pid]); eq($r['status'], 201, $r['body']); $cid = $r['json']['data']['id'];
    eq((int)$db->fetchOne("SELECT COUNT(*) n FROM sw_jobs WHERE type = 'cert_issue' AND status = 'queued'")['n'], 1, 'issuing was queued');
    eq($a->api('POST', '/api/certificates', ['name' => 'Web', 'names' => 'a.example.com', 'dns_provider_id' => $pid])['status'], 400, 'duplicate name');
    eq($a->api('DELETE', "/api/dns-accounts/$pid")['status'], 409, 'in use');
    $t = $a->api('POST', "/api/certificates/$cid/targets", ['name' => 'Web server', 'allow_cidrs' => '']); eq($t['status'], 201, $t['body']);
    $token = $t['json']['data']['token']; ok(str_contains($t['json']['data']['config'], "TOKEN=$token") && str_contains($t['json']['data']['config'], '/api/cert-pull/' . $t['json']['data']['id']));
    $l = $a->api('GET', '/api/certificates'); eq($l['status'], 200); ok(!str_contains($l['body'], $token) && !str_contains($l['body'], 'Qq7') && !str_contains($l['body'], 'PRIVATE KEY') && !str_contains($l['body'], 'config_enc') && !str_contains($l['body'], 'token_hash'), 'nothing secret in the list');
    eq($l['json']['data']['certificates'][0]['targets'][0]['name'], 'Web server'); eq($l['json']['data']['dns_accounts'][0]['name'], 'CF');
    eq($a->api('DELETE', '/api/cert-targets/' . $t['json']['data']['id'])['status'], 200); eq($a->api('DELETE', "/api/certificates/$cid")['status'], 200); eq($a->api('DELETE', "/api/dns-accounts/$pid")['status'], 200);
    eq($a->api('DELETE', '/api/certificates/999')['status'], 404);
});

test('the endpoints a server fetches from: refused without the right token (all the same 403), never cached, and the report needs no browser session', function () use ($reset, $db, $seed, $base) {
    $reset(); $s = $seed(); $t = Certificates::createTarget($s['id'], 'Web'); $other = Certificates::createTarget($s['id'], 'Other'); $h = new TestHttp($base);
    $auth = fn (string $tok) => ['Authorization' => "Bearer $tok"]; $p = "/api/cert-pull/{$t['id']}";
    foreach (['/fingerprint', '/fullchain.pem', '/privkey.pem'] as $f) {
        $bodies = [];
        foreach ([[], $auth('swc_' . str_repeat('1', 48)), $auth($other['token']), $auth('nonsense')] as $hdr) { $r = $h->req('GET', $p . $f, null, $hdr); eq($r['status'], 403, $f); $bodies[] = $r['body']; }
        eq(count(array_unique($bodies)), 1, 'every refusal looks the same');
        $r = $h->req('GET', "/api/cert-pull/9999$f", null, $auth($t['token'])); eq($r['status'], 403, 'an unknown id looks the same');
    }
    $r = $h->req('GET', "$p/fingerprint", null, $auth($t['token'])); eq([$r['status'], trim($r['body'])], [200, $s['fp']]); ok(str_contains($r['headers']['cache-control'] ?? '', 'no-store'));
    $r = $h->req('GET', "$p/fullchain.pem", null, $auth($t['token'])); eq([$r['status'], $r['body']], [200, $s['chain']]);
    $r = $h->req('GET', "$p/privkey.pem", null, $auth($t['token'])); eq([$r['status'], $r['body']], [200, $s['key']]); ok(str_contains($r['headers']['cache-control'] ?? '', 'no-store'));
    $r = $h->req('POST', "$p/installed", ['fingerprint' => $s['fp'], 'status' => 'ok'], ['Content-Type' => 'application/json'] + $auth($t['token'])); eq($r['status'], 200, $r['body']);
    $row = $db->fetchOne('SELECT * FROM sw_cert_targets WHERE id = :i', ['i' => $t['id']]); eq([$row['installed_fingerprint'], $row['install_status']], [$s['fp'], 'ok']);
    eq($h->req('POST', "$p/installed", ['fingerprint' => $s['fp'], 'status' => 'ok'], ['Content-Type' => 'application/json'])['status'], 403, 'without the token the report is refused too');
    $r = $h->req('POST', "$p/installed", ['fingerprint' => 'x"y', 'status' => 'failed', 'error' => "bad\r\nline"], ['Content-Type' => 'application/json'] + $auth($t['token']));
    $row = $db->fetchOne('SELECT * FROM sw_cert_targets WHERE id = :i', ['i' => $t['id']]); eq([$row['installed_fingerprint'], $row['install_status'], $row['install_error']], [null, 'failed', 'bad line']);
    $r = $h->req('GET', "$p/fingerprint", null, $auth($t['token'])); $h2 = new TestHttp($base); $n = Certificates::create('NoCert', ['nc.example.test'], (int)$db->fetchOne('SELECT id FROM sw_dns_providers LIMIT 1')['id']); $t3 = Certificates::createTarget($n, 'X');
    $r = $h2->req('GET', "/api/cert-pull/{$t3['id']}/fingerprint", null, $auth($t3['token'])); eq([$r['status'], trim($r['body'])], [200, 'none']); eq($h2->req('GET', "/api/cert-pull/{$t3['id']}/privkey.pem", null, $auth($t3['token']))['status'], 404);
});

test('refused requests are throttled (guessing tokens runs into a limit)', function () use ($reset, $seed, $base) {
    $reset(); $s = $seed(); $t = Certificates::createTarget($s['id'], 'Web'); $h = new TestHttp($base); $codes = [];
    for ($i = 0; $i < 40; $i++) { $codes[] = $h->req('GET', "/api/cert-pull/{$t['id']}/fingerprint", null, ['Authorization' => 'Bearer swc_' . str_repeat('2', 48)])['status']; }
    ok(in_array(429, $codes, true) && $codes[0] === 403, json_encode(array_count_values($codes)));
    $r = $h->req('GET', "/api/cert-pull/{$t['id']}/fingerprint", null, ['Authorization' => 'Bearer ' . $t['token']]); ok(in_array($r['status'], [429, 200], true));
});

$script = dirname(__DIR__, 2) . '/deploy/sw-cert-pull';
$runScript = function (array $conf, string $dir, array $env = []) use ($script) {
    $f = $dir . '/test.conf'; $lines = ''; foreach ($conf as $k => $v) { $lines .= "$k=$v\n"; } file_put_contents($f, $lines);
    $e = ''; foreach ($env + ['SW_CERT_PULL_INSECURE' => '1'] as $k => $v) { $e .= "$k=" . escapeshellarg($v) . ' '; }
    $out = []; exec("$e sh " . escapeshellarg($script) . ' ' . escapeshellarg($f) . ' 2>&1', $out, $code);
    return ['code' => $code, 'out' => implode("\n", $out)];
};
$tmp = fn () => (function () { $d = sys_get_temp_dir() . '/swpull' . bin2hex(random_bytes(4)); mkdir($d); return $d; })();

test('pull script: installs the certificate and key (key private), reloads, reports; a second run only reports; a renewed certificate replaces it', function () use ($reset, $db, $seed, $base, $runScript, $tmp) {
    $reset(); $s = $seed(); $t = Certificates::createTarget($s['id'], 'Web'); $d = $tmp(); $mark = "$d/reloads";
    $conf = ['URL' => "$base/api/cert-pull/{$t['id']}", 'TOKEN' => $t['token'], 'DEST' => "$d/certs", 'RELOAD' => "\"echo r >> $mark\""];
    $r = $runScript($conf, $d); eq($r['code'], 0, $r['out']); ok(str_contains($r['out'], 'installed certificate ' . $s['fp']), $r['out']);
    eq([file_get_contents("$d/certs/fullchain.pem"), file_get_contents("$d/certs/privkey.pem")], [$s['chain'], $s['key']]);
    eq([substr(sprintf('%o', fileperms("$d/certs/privkey.pem")), -4), substr(sprintf('%o', fileperms("$d/certs/fullchain.pem")), -4)], ['0600', '0644']); eq(count(file($mark)), 1, 'reloaded once');
    $row = $db->fetchOne('SELECT installed_fingerprint f, install_status s FROM sw_cert_targets WHERE id = :i', ['i' => $t['id']]); eq([$row['f'], $row['s']], [$s['fp'], 'ok']);
    $r = $runScript($conf, $d); eq($r['code'], 0, $r['out']); eq(count(file($mark)), 1, 'nothing new: no reload');
    $n = $seed('Renewed'); $db->query('UPDATE sw_certificates SET fingerprint = :f, chain_pem = :c, key_enc = :k, not_after = :a WHERE id = :i', ['f' => $n['fp'], 'c' => $n['chain'], 'k' => Crypto::encrypt($n['key'], 'cert:' . $s['id']), 'a' => gmdate('Y-m-d H:i:s', time() + 86400 * 90), 'i' => $s['id']]);
    $r = $runScript($conf, $d); eq($r['code'], 0, $r['out']); eq([file_get_contents("$d/certs/fullchain.pem"), count(file($mark))], [$n['chain'], 2]); eq(file_get_contents("$d/certs/previous/fullchain.pem"), $s['chain'], 'the old pair is kept');
    exec('rm -rf ' . escapeshellarg($d));
});

test('pull script: a failing reload puts the previous certificate back and reports the failure; the group option opens the key to that group only', function () use ($reset, $db, $seed, $base, $runScript, $tmp) {
    $reset(); $s = $seed(); $t = Certificates::createTarget($s['id'], 'Web'); $d = $tmp();
    $conf = ['URL' => "$base/api/cert-pull/{$t['id']}", 'TOKEN' => $t['token'], 'DEST' => "$d/certs", 'RELOAD' => '"true"'];
    eq($runScript($conf, $d)['code'], 0);
    $n = $seed('Renewed'); $db->query('UPDATE sw_certificates SET fingerprint = :f, chain_pem = :c, key_enc = :k WHERE id = :i', ['f' => $n['fp'], 'c' => $n['chain'], 'k' => Crypto::encrypt($n['key'], 'cert:' . $s['id']), 'i' => $s['id']]);
    $r = $runScript(['RELOAD' => '"echo nginx: config error; exit 1"'] + $conf, $d); eq($r['code'], 1); ok(str_contains($r['out'], 'previous certificate was put back'), $r['out']);
    eq([file_get_contents("$d/certs/fullchain.pem"), file_get_contents("$d/certs/privkey.pem")], [$s['chain'], $s['key']], 'the old pair is back in place');
    $row = $db->fetchOne('SELECT install_status s, install_error e FROM sw_cert_targets WHERE id = :i', ['i' => $t['id']]); eq($row['s'], 'failed'); ok(str_contains($row['e'], 'config error'), (string)$row['e']);
    $grp = trim((string)shell_exec('id -gn')); $n2 = $seed('Third'); $db->query('UPDATE sw_certificates SET fingerprint = :f, chain_pem = :c, key_enc = :k WHERE id = :i', ['f' => $n2['fp'], 'c' => $n2['chain'], 'k' => Crypto::encrypt($n2['key'], 'cert:' . $s['id']), 'i' => $s['id']]);
    $r = $runScript(['GROUP' => $grp] + $conf, $d); eq($r['code'], 0, $r['out']);
    eq([substr(sprintf('%o', fileperms("$d/certs/privkey.pem")), -4), posix_getgrgid(filegroup("$d/certs/privkey.pem"))['name']], ['0640', $grp], 'with GROUP the key is readable by that group, still not by everyone');
    $n3 = $seed('Fourth'); $db->query('UPDATE sw_certificates SET fingerprint = :f, chain_pem = :c, key_enc = :k WHERE id = :i', ['f' => $n3['fp'], 'c' => $n3['chain'], 'k' => Crypto::encrypt($n3['key'], 'cert:' . $s['id']), 'i' => $s['id']]);
    $r = $runScript(['GROUP' => 'no_such_group_xyz'] + $conf, $d); eq($r['code'], 1); ok(str_contains($r['out'], 'could not give group no_such_group_xyz access'), 'an unknown group is not silently ignored: ' . $r['out']);
    eq(file_get_contents("$d/certs/fullchain.pem"), $n2['chain'], 'and the installed pair is untouched');
    exec('rm -rf ' . escapeshellarg($d));
});

test('pull script: refuses a key that does not match, a certificate that is about to expire, a plain-http address, a wrong token and bad config; nothing is installed', function () use ($reset, $db, $seed, $base, $runScript, $tmp) {
    $reset(); $d = $tmp();
    $bad = $seed('BadKey', 90, true); $t = Certificates::createTarget($bad['id'], 'Web'); $conf = ['URL' => "$base/api/cert-pull/{$t['id']}", 'TOKEN' => $t['token'], 'DEST' => "$d/a"];
    $r = $runScript($conf, $d); eq($r['code'], 1); ok(str_contains($r['out'], 'does not belong to the certificate'), $r['out']); ok(!file_exists("$d/a/fullchain.pem") && !file_exists("$d/a/privkey.pem"), 'nothing installed');
    eq($db->fetchOne('SELECT install_status s FROM sw_cert_targets WHERE id = :i', ['i' => $t['id']])['s'], 'failed');
    $soon = $seed('Soon', 1); $t2 = Certificates::createTarget($soon['id'], 'Web'); $r = $runScript(['URL' => "$base/api/cert-pull/{$t2['id']}", 'TOKEN' => $t2['token'], 'DEST' => "$d/b"], $d); eq($r['code'], 1); ok(str_contains($r['out'], 'expires within a day'), $r['out']);
    $ok = $seed('Fine'); $t3 = Certificates::createTarget($ok['id'], 'Web'); $good = ['URL' => "$base/api/cert-pull/{$t3['id']}", 'TOKEN' => $t3['token'], 'DEST' => "$d/c"];
    $r = $runScript($good, $d, ['SW_CERT_PULL_INSECURE' => '0']); eq($r['code'], 2); ok(str_contains($r['out'], 'must be https'), $r['out']);
    $r = $runScript(['TOKEN' => 'swc_' . str_repeat('3', 48)] + $good, $d); eq($r['code'], 1); ok(str_contains($r['out'], 'refused'), $r['out']); ok(!file_exists("$d/c/fullchain.pem"));
    foreach ([['DEST' => 'relative/path'] + $good, ['TOKEN' => 'not-a-token'] + $good, ['URL' => 'ftp://x/y'] + $good, ['DEST' => ''] + $good] as $c) { eq($runScript($c, $d)['code'], 2, json_encode($c)); }
    $r = $runScript($good, $d); eq($r['code'], 0, $r['out']);
    exec('rm -rf ' . escapeshellarg($d));
});

test('names are checked against the domains of the DNS account: a mistyped domain is caught with a suggestion, a subdomain that does not exist yet is fine, a wildcard follows its parent', function () use ($reset, $db) {
    $reset(); $p = Certificates::createProvider('Zones', 'fake', ['zones' => 'downwiththeman.org,example.com', 'elsewhere' => 'kobe.ns.cloudflare.com']);
    $e = throws(ResponseException::class, fn () => Certificates::create('Typo', ['test.downwiththemain.org'], $p));
    $msg = $e->body; ok(str_contains($msg, 'test.downwiththemain.org is not in any domain') && str_contains($msg, 'Did you mean downwiththeman.org?'), $msg);
    foreach (['test.downwiththeman.org', 'brand-new.sub.example.com', 'example.com', '*.example.com', '*.new.example.com'] as $i => $ok) { Certificates::create('Fine ' . $i, [$ok], $p); }
    eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_certificates')['n'], 5, 'a name that does not exist yet is allowed: DNS validation does not need it to');
    $e = throws(ResponseException::class, fn () => Certificates::create('Moved', ['www.moved.example'], $p)); ok(str_contains($e->body, 'DNS is hosted elsewhere (kobe.ns.cloudflare.com)'), $e->body);
    $e = throws(ResponseException::class, fn () => Certificates::create('Far', ['nothing.like.this.test'], $p)); $m = $e->body; ok(str_contains($m, 'not in any domain') && !str_contains($m, 'Did you mean'), 'no suggestion when nothing is close: ' . $m);
    $e = throws(ResponseException::class, fn () => Certificates::create('Two', ['example.com', 'typo.exmple.com'], $p)); ok(str_contains($e->body, 'typo.exmple.com') && !str_contains($e->body, '"example.com is'), 'only the bad name is reported');
});

test('the name check never blocks when the provider cannot list its domains or its API is down (the issue job reports problems instead)', function () use ($reset) {
    $reset(); $none = Certificates::createProvider('NoList', 'fake', []); $down = Certificates::createProvider('Down', 'fake', ['zones' => 'a.example', 'down' => '1']);
    ok(Certificates::create('A', ['anything.example'], $none) > 0); ok(Certificates::create('B', ['anything.example'], $down) > 0);
    eq(Certificates::coverage(['x.example'], $down), []); eq(Certificates::coverage(['x.example'], $none), []);
});

test('a provider that echoes a secret back in an error never gets it recorded: the stored status, the returned message and the failure alert all say [hidden]', function () use ($reset, $db) {
    $reset(); $secret = 'sekrit-' . bin2hex(random_bytes(6)); $id = Certificates::createProvider('Leaky', 'fake', ['leak' => $secret]);
    $r = Certificates::testProvider($id); ok(!str_contains(json_encode($r), $secret) && str_contains($r['message'], 'refused for key [hidden] sorry'), json_encode($r));
    eq(str_contains((string)$db->fetchOne('SELECT check_msg m FROM sw_dns_providers WHERE id = :i', ['i' => $id])['m'], $secret), false);
    $leaky = new class extends DnsProvider { public function addTxt(string $n, string $v): string { throw new DnsProviderException('nope ' . $GLOBALS['__leak']); } public function removeTxt(string $h): void {} public function verify(): string { return ''; } };
    $GLOBALS['__leak'] = $secret; DnsProvider::$factories['leaky'] = fn () => $leaky;
    $pid = Certificates::createProvider('Leaky2', 'leaky', ['x' => $secret]); $cid = Certificates::create('Leak cert', ['a.example.com'], $pid);
    Config::set('acme.directory', 'https://acme.example.test/dir'); fakeWeb(['https://acme.example.test/dir' => [503, [], 'down']]);
    Certificates::issue($cid); Config::set('acme.directory', '');
    $row = $db->fetchOne('SELECT last_error e FROM sw_certificates WHERE id = :i', ['i' => $cid]); ok(!str_contains((string)$row['e'], $secret), (string)$row['e']);
    ok(!str_contains(json_encode($db->fetchAll('SELECT payload FROM sw_jobs')), $secret), 'no alert payload holds it');
});
