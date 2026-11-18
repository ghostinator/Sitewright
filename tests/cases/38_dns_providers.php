<?php
use Sitewright\Core\{Config, DnsCloudflare, DnsProvider, DnsProviderException, DnsSpaceship, FetchException};

// Certificate manager: the DNS providers. Answers are faked in the shapes the providers' documentation describes.
$J = fn ($d, int $s = 200) => [$s, ['Content-Type' => 'application/json'], json_encode($d)];
$CF = 'https://api.cloudflare.com/client/v4';
$TOKEN = 'cf_' . str_repeat('A1b2', 8);
$ZID = str_repeat('a', 32); $RID = str_repeat('b', 32);

test('Cloudflare: the zone is the longest suffix that is one; the TXT record is created with a short TTL and removed by its handle', function () use ($J, $CF, $TOKEN, $ZID, $RID) {
    $seen = [];
    fakeWeb([
        "$CF/zones?status=active&per_page=1&name=" . rawurlencode('_acme-challenge.shop.example.com') => $J(['success' => true, 'result' => []]),
        "$CF/zones?status=active&per_page=1&name=shop.example.com" => $J(['success' => true, 'result' => []]),
        "$CF/zones?status=active&per_page=1&name=example.com" => $J(['success' => true, 'result' => [['id' => $ZID, 'name' => 'example.com']]]),
        "$CF/zones/$ZID/dns_records" => function (array $r) use ($J, $RID, &$seen) { $seen[] = [$r['method'], $r['body'], $r['headers']]; return $J(['success' => true, 'result' => ['id' => $RID]]); },
        "$CF/zones/$ZID/dns_records/$RID" => function (array $r) use ($J, &$seen) { $seen[] = [$r['method'], $r['body']]; return $J(['success' => true, 'result' => ['id' => 'x']]); },
    ]);
    $p = new DnsCloudflare(['token' => $TOKEN]);
    $h = $p->addTxt('_acme-challenge.shop.example.com', 'abc_DEF-123');
    eq($h, "$ZID/$RID");
    $b = json_decode($seen[0][1], true); eq([$seen[0][0], $b['type'], $b['name'], $b['content'], $b['ttl']], ['POST', 'TXT', '_acme-challenge.shop.example.com', 'abc_DEF-123', 60]);
    ok(in_array("Authorization: Bearer $TOKEN", $seen[0][2], true), 'the token is sent as a bearer token');
    $p->removeTxt($h); eq($seen[1][0], 'DELETE');
});

test('Cloudflare: failures say what is wrong without ever repeating the token; a handle that is not ours is refused', function () use ($J, $CF, $TOKEN, $ZID) {
    $p = new DnsCloudflare(['token' => $TOKEN]);
    $miss = ["$CF/zones?status=active&per_page=1&name=" . rawurlencode('_acme-challenge.example.com') => $J(['success' => true, 'result' => []])];   // a name that is not a zone is an empty list, not an error
    $lookup = "$CF/zones?status=active&per_page=1&name=example.com";
    fakeWeb($miss + [$lookup => $J(['success' => false, 'errors' => [['code' => 10000, 'message' => "Authentication error $TOKEN <b>x</b>"]]], 403)]);
    $e = throws(DnsProviderException::class, fn () => $p->addTxt('_acme-challenge.example.com', 'v'));
    ok(str_contains($e->getMessage(), 'Cloudflare refused it') && str_contains($e->getMessage(), 'check the token'), $e->getMessage()); ok(!str_contains($e->getMessage(), '<b>'), 'markup removed');
    fakeWeb($miss + [$lookup => $J([], 429)]); $e = throws(DnsProviderException::class, fn () => $p->addTxt('_acme-challenge.example.com', 'v')); ok(str_contains($e->getMessage(), 'limiting'), $e->getMessage());
    fakeWeb($miss + [$lookup => new FetchException('Host could not be resolved')]); $e = throws(DnsProviderException::class, fn () => $p->addTxt('_acme-challenge.example.com', 'v')); ok(str_contains($e->getMessage(), 'Could not reach Cloudflare'), $e->getMessage());
    fakeWeb($miss + [$lookup => $J(['success' => true, 'result' => []])]); $e = throws(DnsProviderException::class, fn () => $p->addTxt('_acme-challenge.example.com', 'v')); ok(str_contains($e->getMessage(), 'cannot see a zone'), $e->getMessage());
    foreach (['../../x', "$ZID/../x", 'abc', "$ZID/" . str_repeat('b', 31)] as $bad) { throws(DnsProviderException::class, fn () => $p->removeTxt($bad)); }
    foreach (['', 'short', "has space $TOKEN", "line\nbreak"] as $bad) { throws(DnsProviderException::class, fn () => new DnsCloudflare(['token' => $bad])); }
});

test('Cloudflare: verify lists the zones the token can see; "test a domain" adds and removes a harmless record', function () use ($J, $CF, $TOKEN, $ZID, $RID) {
    $calls = [];
    fakeWeb(["$CF/zones?status=active&per_page=50&page=1" => $J(['success' => true, 'result' => [['name' => 'a.example'], ['name' => 'b.example']]]),
        "$CF/zones?status=active&per_page=1&name=" . rawurlencode('_sitewright-test.a.example') => $J(['success' => true, 'result' => []]),
        "$CF/zones?status=active&per_page=1&name=a.example" => $J(['success' => true, 'result' => [['id' => $ZID]]]),
        "$CF/zones/$ZID/dns_records" => function ($r) use ($J, $RID, &$calls) { $calls[] = $r['method'] . ' ' . (json_decode($r['body'], true)['name'] ?? ''); return $J(['success' => true, 'result' => ['id' => $RID]]); },
        "$CF/zones/$ZID/dns_records/$RID" => function ($r) use ($J, &$calls) { $calls[] = $r['method']; return $J(['success' => true, 'result' => []]); }]);
    $p = new DnsCloudflare(['token' => $TOKEN]);
    $m = $p->verify(); ok(str_contains($m, '2 zones') && str_contains($m, 'a.example, b.example'), $m);
    $m = $p->test('A.Example'); eq($calls, ['POST _sitewright-test.a.example', 'DELETE']); ok(str_contains($m, 'a.example'), $m);
    throws(DnsProviderException::class, fn () => $p->test('*.a.example')); throws(DnsProviderException::class, fn () => $p->test("a.example\nb"));
    fakeWeb(["$CF/zones?status=active&per_page=50&page=1" => $J(['success' => true, 'result' => []])]); ok(str_contains($p->verify(), 'cannot see any zone'));
});

$SS = 'https://spaceship.dev/api/v1'; $KEY = 'key_0123456789ABCD'; $SEC = 'secret_0123456789ABCDEFGH';
test('Spaceship: the domain that holds the name is found, the TXT record is saved with a name relative to it, and deleted by its exact value', function () use ($J, $SS, $KEY, $SEC) {
    $seen = [];
    fakeWeb([
        "$SS/dns/records/" . rawurlencode('_acme-challenge.shop.example.org') . '?take=1&skip=0' => [404, [], '{}'],
        "$SS/dns/records/shop.example.org?take=1&skip=0" => [404, [], '{}'],
        "$SS/dns/records/example.org?take=1&skip=0" => $J(['items' => [], 'total' => 0]),
        "$SS/dns/records/example.org" => function (array $r) use (&$seen) { $seen[] = [$r['method'], $r['body'], $r['headers']]; return [204, [], '']; },
    ]);
    $p = new DnsSpaceship(['api_key' => $KEY, 'api_secret' => $SEC]);
    $h = $p->addTxt('_acme-challenge.shop.example.org', 'AbC-123_x');
    eq($h, 'example.org|_acme-challenge.shop|AbC-123_x');
    $b = json_decode($seen[0][1], true); eq([$seen[0][0], $b['items'][0]], ['PUT', ['type' => 'TXT', 'name' => '_acme-challenge.shop', 'value' => 'AbC-123_x', 'ttl' => 60]]);
    ok(in_array("X-API-Key: $KEY", $seen[0][2], true) && in_array("X-API-Secret: $SEC", $seen[0][2], true));
    $p->removeTxt($h); eq([$seen[1][0], json_decode($seen[1][1], true)], ['DELETE', [['type' => 'TXT', 'name' => '_acme-challenge.shop', 'value' => 'AbC-123_x']]]);
});

test('Spaceship: the apex is "@"; failures are explained without the secret; bad handles and keys are refused', function () use ($J, $SS, $KEY, $SEC) {
    $seen = [];
    fakeWeb(["$SS/dns/records/example.org?take=1&skip=0" => $J(['items' => []]), "$SS/dns/records/example.org" => function ($r) use (&$seen) { $seen[] = json_decode($r['body'], true); return [204, [], '']; }]);
    $p = new DnsSpaceship(['api_key' => $KEY, 'api_secret' => $SEC]);
    eq($p->addTxt('example.org', 'v1'), 'example.org|@|v1'); eq($seen[0]['items'][0]['name'], '@');
    fakeWeb(["$SS/dns/records/example.org?take=1&skip=0" => $J(['detail' => "Forbidden $SEC <i>x</i>"], 403)]);
    $e = throws(DnsProviderException::class, fn () => $p->addTxt('example.org', 'v')); ok(str_contains($e->getMessage(), 'check the key') && !str_contains($e->getMessage(), '<i>'), $e->getMessage());
    fakeWeb(["$SS/dns/records/example.org?take=1&skip=0" => [404, [], '{}']]); $e = throws(DnsProviderException::class, fn () => $p->addTxt('example.org', 'v')); ok(str_contains($e->getMessage(), 'cannot see a domain'), $e->getMessage());
    foreach (['', 'a|b|c|d', '../x|@|v', 'example.org|a b|v', "example.org|@|v\n"] as $bad) { throws(DnsProviderException::class, fn () => $p->removeTxt($bad)); }
    foreach ([['api_key' => '', 'api_secret' => $SEC], ['api_key' => $KEY, 'api_secret' => 'bad secret'], []] as $bad) { throws(DnsProviderException::class, fn () => new DnsSpaceship($bad)); }
    fakeWeb(["$SS/domains?take=100&skip=0" => $J(['items' => [['name' => 'one.example', 'nameservers' => ['provider' => 'basic', 'hosts' => ['launch1.spaceship.net']]], ['name' => 'two.example', 'nameservers' => ['provider' => 'basic']]], 'total' => 2])]); ok(str_contains($p->verify(), '2 domains use Spaceship DNS'));
});

test('provider types and the fields asked for are fixed in code; an unknown type is refused', function () {
    eq(array_keys(DnsProvider::TYPES), ['cloudflare', 'spaceship']); eq(array_keys(DnsProvider::fields('cloudflare')), ['token']); eq(array_keys(DnsProvider::fields('spaceship')), ['api_key', 'api_secret']); eq(DnsProvider::fields('nope'), []);
    throws(DnsProviderException::class, fn () => DnsProvider::make('nope', []));
});

test('Cloudflare: every page of zones is read; only valid names come back, sorted', function () use ($J, $CF, $TOKEN) {
    fakeWeb(["$CF/zones?status=active&per_page=50&page=1" => $J(['success' => true, 'result' => [['name' => 'b.example'], ['name' => 'a.example'], ['name' => "bad name\n"]], 'result_info' => ['total_pages' => 2]]),
        "$CF/zones?status=active&per_page=50&page=2" => $J(['success' => true, 'result' => [['name' => 'c.example']], 'result_info' => ['total_pages' => 2]])]);
    eq((new DnsCloudflare(['token' => $TOKEN]))->domains(), ['a.example', 'b.example', 'c.example']);
});

test('Spaceship: only domains whose name servers are Spaceship\'s own are offered; the others are named with where their DNS is', function () use ($J, $SS, $KEY, $SEC) {
    fakeWeb(["$SS/domains?take=100&skip=0" => $J(['total' => 3, 'items' => [
        ['name' => 'mine.example', 'nameservers' => ['provider' => 'basic', 'hosts' => ['launch1.spaceship.net', 'launch2.spaceship.net']]],
        ['name' => 'cf.example', 'nameservers' => ['provider' => 'custom', 'hosts' => ['kobe.ns.cloudflare.com', 'monika.ns.cloudflare.com']]],
        ['name' => 'also-mine.example', 'nameservers' => ['provider' => 'basic']]]])]);
    $p = new DnsSpaceship(['api_key' => $KEY, 'api_secret' => $SEC]);
    eq($p->domains(), ['also-mine.example', 'mine.example']); eq($p->elsewhere(), ['cf.example' => 'kobe.ns.cloudflare.com, monika.ns.cloudflare.com']);
    $m = $p->verify(); ok(str_contains($m, '2 domains use Spaceship DNS') && str_contains($m, '1 more use name servers elsewhere'), $m);
});
