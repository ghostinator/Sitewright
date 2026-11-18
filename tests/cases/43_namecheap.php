<?php
use Sitewright\Core\{Certificates, Config, Crypto, DnsNamecheap, DnsProvider, DnsProviderException, ResponseException, SiteExpiry};

// Namecheap as a registrar account: domain lists and expiry dates, never DNS.
$URL = 'https://api.namecheap.com/xml.response';
$cfg = ['api_user' => 'brandon_user', 'api_key' => 'abcdef0123456789abcdef0123456789', 'client_ip' => '203.0.113.7'];
$xml = fn (string $domains, int $total, string $status = 'OK') => [200, ['Content-Type' => 'text/xml'], '<?xml version="1.0" encoding="utf-8"?><ApiResponse Status="' . $status . '" xmlns="http://api.namecheap.com/xml.response"><Errors /><RequestedCommand>namecheap.domains.getList</RequestedCommand><CommandResponse Type="namecheap.domains.getList"><DomainGetListResult>' . $domains . '</DomainGetListResult><Paging><TotalItems>' . $total . '</TotalItems><CurrentPage>1</CurrentPage><PageSize>100</PageSize></Paging></CommandResponse></ApiResponse>'];
$dom = fn (string $name, string $expires, bool $ours = true) => '<Domain ID="1" Name="' . $name . '" User="u" Created="01/02/2025" Expires="' . $expires . '" IsExpired="false" IsLocked="false" AutoRenew="true" WhoisGuard="ENABLED" IsPremium="false" IsOurDNS="' . ($ours ? 'true' : 'false') . '" />';
$err = fn (string $n, string $m) => [200, ['Content-Type' => 'text/xml'], '<?xml version="1.0"?><ApiResponse Status="ERROR" xmlns="http://api.namecheap.com/xml.response"><Errors><Error Number="' . $n . '">' . $m . '</Error></Errors></ApiResponse>'];

test('Namecheap: the request carries the documented parameters as a form (the key never appears in a URL); domains and expiry dates are read, including the account\'s own name servers flag', function () use ($URL, $cfg, $xml, $dom) {
    $seen = [];
    fakeWeb([$URL => function ($r) use (&$seen, $xml, $dom) { parse_str($r['body'], $b); $seen[] = [$r['method'], $r['url'], $b, $r['headers']]; return $xml($dom('livelaughpueblo.co', '11/19/2026', false) . $dom('Shop.Example.COM', '03/04/2027') . $dom('bad name!', '01/01/2027'), 3); }]);
    $p = new DnsNamecheap($cfg);
    eq($p->domains(), ['livelaughpueblo.co', 'shop.example.com'], 'sorted, lower case, invalid names dropped');
    [$m, $u, $b, $h] = $seen[0]; eq([$m, $u], ['POST', $URL]); ok(!str_contains($u, $cfg['api_key']), 'the key is not in the address');
    eq([$b['ApiUser'], $b['UserName'], $b['ApiKey'], $b['ClientIp'], $b['Command'], $b['PageSize'], $b['Page']], ['brandon_user', 'brandon_user', $cfg['api_key'], '203.0.113.7', 'namecheap.domains.getList', '100', '1']); ok(in_array('Content-Type: application/x-www-form-urlencoded', $h, true));
    eq($p->elsewhere(), ['livelaughpueblo.co' => 'name servers are not Namecheap\'s']); eq(count($seen), 1, 'the list is read once');
    eq($p->registration('www.livelaughpueblo.co'), ['expires' => strtotime('2026-11-19 UTC'), 'domain' => 'livelaughpueblo.co', 'registrar' => 'Namecheap']); eq($p->registration('shop.example.com')['expires'], strtotime('2027-03-04 UTC')); eq($p->registration('unknown.example'), null);
});

test('Namecheap: a long account is read page by page', function () use ($URL, $cfg, $xml, $dom) {
    $pages = [];
    fakeWeb([$URL => function ($r) use (&$pages, $xml, $dom) { parse_str($r['body'], $b); $pages[] = (int)$b['Page']; $from = ((int)$b['Page'] - 1) * 100; $d = ''; for ($i = $from; $i < min(150, $from + 100); $i++) { $d .= $dom("d$i.example", '01/01/2027'); } return $xml($d, 150); }]);
    eq(count((new DnsNamecheap($cfg))->domains()), 150); eq($pages, [1, 2]);
});

test('Namecheap: it never edits DNS (adding or removing a record is refused with the reason), and cannot be chosen for a certificate', function () use ($cfg) {
    $p = new DnsNamecheap($cfg);
    foreach ([fn () => $p->addTxt('_acme-challenge.a.example', 'v'), fn () => $p->removeTxt('x')] as $f) { $e = throws(DnsProviderException::class, $f); ok(str_contains($e->getMessage(), 'replaces a domain\'s whole record list'), $e->getMessage()); }
    eq(DnsProvider::editsDns('namecheap'), false); eq(DnsProvider::editsDns('cloudflare'), true); ok(isset(DnsProvider::TYPES['namecheap'])); eq(array_keys(DnsProvider::fields('namecheap')), ['api_user', 'api_key', 'client_ip']);
});

test('Namecheap: refusals say what to check, never show the key, and a rate limit is explained; the shapes of the credentials are enforced', function () use ($URL, $cfg, $err) {
    fakeWeb([$URL => $err('1011150', 'Parameter RequestIP is invalid or not whitelisted ' . $cfg['api_key'] . ' <b>x</b>')]);
    $e = throws(DnsProviderException::class, fn () => (new DnsNamecheap($cfg))->domains()); $m = $e->getMessage();
    ok(str_contains($m, 'API whitelist') && str_contains($m, '203.0.113.7') && str_contains($m, '1011150') && !str_contains($m, '<b>'), $m);
    fakeWeb([$URL => [405, [], '']]); $e = throws(DnsProviderException::class, fn () => (new DnsNamecheap($cfg))->domains()); ok(str_contains($e->getMessage(), 'limiting'), $e->getMessage());
    fakeWeb([$URL => [200, [], 'not xml']]); throws(DnsProviderException::class, fn () => (new DnsNamecheap($cfg))->domains());
    foreach ([['api_user' => ''], ['api_user' => 'a b'], ['api_key' => 'short'], ['api_key' => str_repeat('a', 16) . ' x'], ['client_ip' => ''], ['client_ip' => '2001:db8::1'], ['client_ip' => '999.1.1.1'], ['client_ip' => '203.0.113.7/24']] as $bad) { throws(DnsProviderException::class, fn () => new DnsNamecheap($bad + $cfg), json_encode($bad)); }
});

test('Namecheap: "test a domain" checks the registrar record instead of writing anything; verify lists the domains', function () use ($URL, $cfg, $xml, $dom) {
    fakeWeb([$URL => $xml($dom('a.example', '12/31/2027') . $dom('b.example', '01/15/2027', false), 2)]);
    $p = new DnsNamecheap($cfg); eq($p->test('A.Example'), 'Namecheap knows a.example and says the registration ends on 2027-12-31.'); throws(DnsProviderException::class, fn () => $p->test('nope.example'));
    $m = $p->verify(); ok(str_contains($m, '2 domains') && str_contains($m, '1 use Namecheap\'s own name servers') && str_contains($m, 'expiry dates only'), $m);
});

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb(); Config::set('app_key', Crypto::generateKey());
test('a Namecheap account is stored encrypted, cannot be used for a certificate, and its key is scrubbed from any recorded error while the address and user name are kept readable', function () use ($db, $cfg, $URL, $err) {
    foreach (['dns_providers', 'certificates'] as $t) { $db->query("TRUNCATE sw_$t"); }
    $id = Certificates::createProvider('NC', 'namecheap', $cfg);
    ok(!str_contains($db->fetchOne('SELECT config_enc c FROM sw_dns_providers WHERE id = :i', ['i' => $id])['c'], $cfg['api_key']));
    $e = throws(ResponseException::class, fn () => Certificates::create('Web', ['a.example.com'], $id)); ok(str_contains($e->body, 'domain lists and expiry dates only'), $e->body);
    fakeWeb([$URL => $err('1011102', 'API Key is invalid or API access has not been enabled: ' . $cfg['api_key'])]); $r = Certificates::testProvider($id);
    ok(!$r['ok'] && !str_contains($r['message'], $cfg['api_key']) && str_contains($r['message'], '[hidden]') && str_contains($r['message'], '203.0.113.7'), $r['message']);
});

test('domain expiry: for a .co domain with no public registry lookup, a connected Namecheap account supplies the date', function () use ($db, $cfg, $URL, $xml, $dom) {
    foreach (['dns_providers', 'sites', 'site_expiry', 'jobs', 'rate_limits'] as $t) { $db->query("TRUNCATE sw_$t"); }
    Config::set('sites.rdap_enabled', true); Certificates::createProvider('NC', 'namecheap', $cfg);
    $sid = (int)$db->insert('sites', ['name' => 'LLP', 'base_url' => 'https://www.llp.co', 'secret_enc' => '']);
    SiteExpiry::$certReader = fn () => ['expires' => time() + 86400 * 100, 'issuer' => 'X', 'names' => 'llp.co', 'trusted' => true, 'problem' => null];
    fakeWeb(['https://data.iana.org/rdap/dns.json' => [200, ['Content-Type' => 'application/json'], json_encode(['services' => [[['com'], ['https://rdap.reg.test/']]]])], $URL => $xml($dom('llp.co', '11/19/2027', false), 1)]);
    $db->query("DELETE FROM sw_kv WHERE k LIKE 'rdap\\_%'"); SiteExpiry::check($sid);
    $r = $db->fetchOne("SELECT state, expires_at, detail FROM sw_site_expiry WHERE kind = 'domain'"); eq([$r['state'], substr($r['expires_at'], 0, 10)], ['ok', '2027-11-19']); ok(str_contains($r['detail'], 'at Namecheap'), $r['detail']);
});
