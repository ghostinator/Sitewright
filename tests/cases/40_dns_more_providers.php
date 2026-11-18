<?php
use Sitewright\Core\{DnsDesec, DnsDigitalOcean, DnsGandi, DnsLinode, DnsPorkbun, DnsProvider, DnsProviderException, DnsRoute53, DnsVultr, FetchException};

// More DNS providers, tested against stateful fakes of their documented APIs (only Cloudflare and Spaceship have been tried with real accounts).
$J = fn ($d, int $s = 200, array $h = []) => [$s, ['Content-Type' => 'application/json'] + $h, json_encode($d)];
$TOK = 'tok_' . str_repeat('Ab9', 10);
$V1 = 'AbC-123_x'; $V2 = 'ZyX-987_w';

test('every provider type has its fields, can be made, refuses keys that are the wrong shape, and its verify() names the domains', function () use ($TOK) {
    $good = ['route53' => ['access_key_id' => 'AKIAEXAMPLE0123456789', 'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY'], 'porkbun' => ['api_key' => 'pk1_' . str_repeat('a', 12), 'api_secret' => 'sk1_' . str_repeat('b', 12)], 'namecheap' => ['api_user' => 'some_user', 'api_key' => 'abcdef0123456789abcdef0123456789', 'client_ip' => '203.0.113.7']];
    foreach (array_keys(DnsProvider::TYPES) as $type) {
        $fields = DnsProvider::fields($type); ok($fields !== [], "$type has fields");
        if (in_array($type, ['cloudflare', 'spaceship'], true)) { continue; }
        $cfg = $good[$type] ?? array_map(fn () => $TOK, $fields);
        ok(DnsProvider::make($type, $cfg) instanceof DnsProvider, $type);
        foreach ($fields as $k => $_) { foreach (['', 'has space inside it 1234567890', "line\nbreak1234567890123456"] as $bad) { throws(DnsProviderException::class, fn () => DnsProvider::make($type, [$k => $bad] + $cfg), "$type $k"); } }
        throws(DnsProviderException::class, fn () => DnsProvider::make($type, []), "$type empty");
    }
});

test('DigitalOcean: domains are paged, the record is created with a name relative to the domain and the minimum TTL, deleted by id; errors do not repeat the token', function () use ($J, $TOK) {
    $B = 'https://api.digitalocean.com/v2'; $seen = [];
    fakeWeb(["$B/domains?per_page=200&page=1" => $J(['domains' => [['name' => 'b.example'], ['name' => 'a.example']], 'links' => ['pages' => ['next' => 'x']]]), "$B/domains?per_page=200&page=2" => $J(['domains' => [['name' => 'c.example']], 'links' => []]),
        "$B/domains/a.example/records" => function ($r) use ($J, &$seen) { $seen[] = [$r['method'], json_decode($r['body'], true), $r['headers']]; return $J(['domain_record' => ['id' => 3456]], 201); },
        "$B/domains/a.example/records/3456" => function ($r) use (&$seen) { $seen[] = [$r['method']]; return [204, [], '']; }]);
    $p = new DnsDigitalOcean(['token' => $TOK]); eq($p->domains(), ['a.example', 'b.example', 'c.example']);
    $h = $p->addTxt('_acme-challenge.new.a.example', 'AbC-123_x'); eq($h, 'a.example|3456');
    eq($seen[0][1], ['type' => 'TXT', 'name' => '_acme-challenge.new', 'data' => 'AbC-123_x', 'ttl' => 30]); ok(in_array("Authorization: Bearer $TOK", $seen[0][2], true));
    $p->removeTxt($h); eq($seen[1], ['DELETE']);
    fakeWeb(["$B/domains?per_page=200&page=1" => $J(['id' => 'unauthorized', 'message' => "Unable to authenticate you $TOK <b>x</b>"], 401)]);
    $e = throws(DnsProviderException::class, fn () => (new DnsDigitalOcean(['token' => $TOK]))->domains()); ok(str_contains($e->getMessage(), 'check the token') && !str_contains($e->getMessage(), '<b>'), $e->getMessage());
    foreach (['../x|1', 'a.example|abc', 'a.example|1|2'] as $bad) { throws(DnsProviderException::class, fn () => $p->removeTxt($bad)); }
    throws(DnsProviderException::class, fn () => $p->addTxt('_acme-challenge.other.test', 'v'));
});

test('Porkbun: the record is created in the zone, a "not delegated" warning is a failure and the record is removed, API errors are explained', function () use ($J) {
    $B = 'https://api.porkbun.com/api/json/v3'; $calls = []; $warn = false;
    fakeWeb(["$B/domain/listAll" => $J(['status' => 'SUCCESS', 'domains' => [['domain' => 'a.example'], ['domain' => 'b.example']]]),
        "$B/dns/create/a.example" => function ($r) use ($J, &$calls, &$warn) { $calls[] = [$r['method'], json_decode($r['body'], true), $r['headers']]; return $J(['status' => 'SUCCESS', 'id' => 777] + ($warn ? ['warnings' => ['Domain is not using Porkbun nameservers']] : [])); },
        "$B/dns/delete/a.example/777" => function ($r) use ($J, &$calls) { $calls[] = ['DELETE ' . $r['method']]; return $J(['status' => 'SUCCESS']); }]);
    $p = new DnsPorkbun(['api_key' => 'pk1_' . str_repeat('a', 12), 'api_secret' => 'sk1_' . str_repeat('b', 12)]); eq($p->domains(), ['a.example', 'b.example']);
    $h = $p->addTxt('_acme-challenge.a.example', 'AbC-123_x'); eq($h, 'a.example|777'); eq($calls[0][1], ['name' => '_acme-challenge', 'type' => 'TXT', 'content' => 'AbC-123_x', 'ttl' => '600']); ok(in_array('X-API-Key: pk1_' . str_repeat('a', 12), $calls[0][2], true));
    $p->removeTxt($h); eq($calls[1], ['DELETE POST']);
    $warn = true; $calls = []; $e = throws(DnsProviderException::class, fn () => $p->addTxt('_acme-challenge.a.example', 'AbC-123_x')); ok(str_contains($e->getMessage(), 'will not be seen') && str_contains($e->getMessage(), 'nameservers'), $e->getMessage()); eq($calls[1], ['DELETE POST'], 'the useless record was removed');
    fakeWeb(["$B/domain/listAll" => $J(['status' => 'ERROR', 'message' => 'Invalid API key. <script>'])]); $e = throws(DnsProviderException::class, fn () => (new DnsPorkbun(['api_key' => 'pk1_' . str_repeat('a', 12), 'api_secret' => 'sk1_' . str_repeat('b', 12)]))->domains());
    ok(str_contains($e->getMessage(), 'Invalid API key') && !str_contains($e->getMessage(), '<script>'), $e->getMessage());
});

test('Linode and Vultr: only master domains (Linode); TXT created with the right fields and quoting; removed by id', function () use ($J, $TOK) {
    $L = 'https://api.linode.com/v4'; $seen = [];
    fakeWeb(["$L/domains?page_size=100&page=1" => $J(['data' => [['id' => 11, 'domain' => 'a.example', 'type' => 'master'], ['id' => 12, 'domain' => 'slave.example', 'type' => 'slave']], 'pages' => 1, 'page' => 1]),
        "$L/domains/11/records" => function ($r) use ($J, &$seen) { $seen[] = json_decode($r['body'], true); return $J(['id' => 901]); }, "$L/domains/11/records/901" => [200, [], '{}']]);
    $p = new DnsLinode(['token' => $TOK]); eq($p->domains(), ['a.example']); $h = $p->addTxt('_acme-challenge.a.example', 'AbC-123_x'); eq($h, '11|901'); eq($seen[0], ['type' => 'TXT', 'name' => '_acme-challenge', 'target' => 'AbC-123_x', 'ttl_sec' => 30]); $p->removeTxt($h);
    throws(DnsProviderException::class, fn () => $p->addTxt('_acme-challenge.slave.example', 'v'));
    $V = 'https://api.vultr.com/v2'; $seen = [];
    fakeWeb(["$V/domains?per_page=500" => $J(['domains' => [['domain' => 'v.example']], 'meta' => ['links' => ['next' => '']]]), "$V/domains/v.example/records" => function ($r) use ($J, &$seen) { $seen[] = json_decode($r['body'], true); return $J(['record' => ['id' => 'cb676a46-66fd-4dfb-b839-443f2e6c0b60']], 201); },
        "$V/domains/v.example/records/cb676a46-66fd-4dfb-b839-443f2e6c0b60" => [204, [], '']]);
    $p = new DnsVultr(['token' => $TOK]); $h = $p->addTxt('_acme-challenge.deep.v.example', 'AbC-123_x'); eq($h, 'v.example|cb676a46-66fd-4dfb-b839-443f2e6c0b60'); eq($seen[0], ['name' => '_acme-challenge.deep', 'type' => 'TXT', 'data' => '"AbC-123_x"', 'ttl' => 120]); $p->removeTxt($h);
    fakeWeb(["$V/domains?per_page=500" => $J(['error' => 'Invalid API key'], 401)]); $e = throws(DnsProviderException::class, fn () => (new DnsVultr(['token' => $TOK]))->domains()); ok(str_contains($e->getMessage(), 'access control list'), $e->getMessage());
});

/** An in-memory deSEC / Gandi style record set store: the same name holds ONE set of TXT values. */
test('deSEC: two TXT values on one name (a name and its wildcard) both end up there, removing one leaves the other, removing the last deletes the set', function () use ($J, $TOK, $V1, $V2) {
    $B = 'https://desec.io/api/v1'; $store = null; $log = [];
    $set = "$B/domains/a.example/rrsets/" . rawurlencode('_acme-challenge') . '/TXT/';
    fakeWeb(["$B/domains/?cursor=" => $J([['name' => 'a.example'], ['name' => 'b.example']]),
        $set => function ($r) use ($J, &$store, &$log) {
            $log[] = $r['method']; $b = json_decode($r['body'] ?? '', true);
            if ($r['method'] === 'GET') { return $store === null ? [404, [], '{}'] : $J(['records' => $store]); }
            if ($r['method'] === 'PATCH') { $store = $b['records']; return $J(['records' => $store]); }
            if ($r['method'] === 'DELETE') { $store = null; return [204, [], '']; }
            return [405, [], ''];
        },
        "$B/domains/a.example/rrsets/" => function ($r) use ($J, &$store, &$log) { $log[] = 'POST'; $b = json_decode($r['body'], true); $store = $b['records']; return $J($b, 201); }]);
    $p = new DnsDesec(['token' => $TOK]); eq($p->domains(), ['a.example', 'b.example']);
    $h1 = $p->addTxt('_acme-challenge.a.example', $V1); eq($store, ['"' . $V1 . '"']); $h2 = $p->addTxt('_acme-challenge.a.example', $V2); eq($store, ['"' . $V1 . '"', '"' . $V2 . '"']);
    $p->removeTxt($h1); eq($store, ['"' . $V2 . '"'], 'only ours is removed'); $p->removeTxt($h2); eq($store, null, 'the empty set is deleted'); $p->removeTxt($h2); eq($store, null, 'removing twice is harmless');
    eq(array_count_values($log)['POST'], 1, 'the set is created once and then changed');
    foreach (['', 'a.example|x|', 'a.example|_acme challenge|v', "a.example|x|v\n"] as $bad) { throws(DnsProviderException::class, fn () => $p->removeTxt($bad)); }
});

test('Gandi: the same record-set handling with PUT and DELETE, values in quotes, domains paged', function () use ($J, $TOK, $V1, $V2) {
    $B = 'https://api.gandi.net/v5/livedns'; $store = null; $bodies = [];
    $set = "$B/domains/a.example/records/" . rawurlencode('_acme-challenge') . '/TXT';
    fakeWeb(["$B/domains?per_page=100&page=1" => $J([['fqdn' => 'a.example'], ['fqdn' => 'b.example']]),
        $set => function ($r) use ($J, &$store, &$bodies) {
            $b = json_decode($r['body'] ?? '', true);
            if ($r['method'] === 'GET') { return $store === null ? [404, [], '{}'] : $J(['rrset_values' => $store]); }
            if ($r['method'] === 'PUT') { $bodies[] = $b; $store = $b['rrset_values']; return $J(['message' => 'ok'], 201); }
            if ($r['method'] === 'DELETE') { $store = null; return [204, [], '']; }
            return [405, [], ''];
        }]);
    $p = new DnsGandi(['token' => $TOK]); $h1 = $p->addTxt('_acme-challenge.a.example', $V1); $h2 = $p->addTxt('_acme-challenge.a.example', $V2);
    eq($store, ['"' . $V1 . '"', '"' . $V2 . '"']); eq($bodies[1]['rrset_ttl'], 300); $p->removeTxt($h1); eq($store, ['"' . $V2 . '"']); $p->removeTxt($h2); eq($store, null);
    fakeWeb(["$B/domains?per_page=100&page=1" => $J(['message' => "Access to the resource is denied $TOK"], 403)]); $e = throws(DnsProviderException::class, fn () => (new DnsGandi(['token' => $TOK]))->domains()); ok(str_contains($e->getMessage(), 'check the token'), $e->getMessage());
});

test('Amazon Route 53: requests are signed (AWS\'s own example gives AWS\'s own signature); a name and its wildcard share one record set; changes are waited for; only public zones; errors are explained', function () use ($V1, $V2) {
    eq(DnsRoute53::authorization('GET', 'iam.amazonaws.com', '/', 'Action=ListUsers&Version=2010-05-08', 'application/x-www-form-urlencoded; charset=utf-8', '', '20150830T123600Z', 'AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 'us-east-1', 'iam'),
        'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/iam/aws4_request, SignedHeaders=content-type;host;x-amz-date, Signature=5d672d79c15b13162d9279b0855cfba6789a8edb4c82c400e06b5924a6f2b5d7');
    $B = 'https://route53.amazonaws.com/2013-04-01'; $values = []; $ttl = 60; $changes = []; $polls = 0; $authSeen = [];
    $ns = ' xmlns="https://route53.amazonaws.com/doc/2013-04-01/"';
    $rrsetXml = function () use (&$values, &$ttl, $ns) {
        $rr = implode('', array_map(fn ($v) => '<ResourceRecord><Value>' . htmlspecialchars($v, ENT_XML1) . '</Value></ResourceRecord>', $values));
        return "<ListResourceRecordSetsResponse$ns><ResourceRecordSets>" . ($values ? "<ResourceRecordSet><Name>_acme-challenge.a.example.</Name><Type>TXT</Type><TTL>$ttl</TTL><ResourceRecords>$rr</ResourceRecords></ResourceRecordSet>" : '') . '</ResourceRecordSets><IsTruncated>false</IsTruncated></ListResourceRecordSetsResponse>';
    };
    DnsRoute53::$sleeper = fn () => null;
    fakeWeb([
        "$B/hostedzone?maxitems=100" => [200, [], "<ListHostedZonesResponse$ns><HostedZones><HostedZone><Id>/hostedzone/Z111AAA</Id><Name>a.example.</Name><Config><PrivateZone>false</PrivateZone></Config></HostedZone><HostedZone><Id>/hostedzone/Z222BBB</Id><Name>internal.example.</Name><Config><PrivateZone>true</PrivateZone></Config></HostedZone></HostedZones><IsTruncated>false</IsTruncated></ListHostedZonesResponse>"],
        "$B/hostedzone/Z111AAA/rrset?maxitems=1&name=_acme-challenge.a.example.&type=TXT" => fn ($r) => [200, [], $rrsetXml()],
        "$B/hostedzone/Z111AAA/rrset" => function ($r) use (&$values, &$ttl, &$changes, &$authSeen, $ns) {
            $authSeen[] = $r['headers']; $x = simplexml_load_string(preg_replace('/\sxmlns="[^"]*"/', '', $r['body'], 1)); $c = $x->ChangeBatch->Changes->Change; $action = (string)$c->Action; $set = $c->ResourceRecordSet;
            $vals = []; foreach ($set->ResourceRecords->ResourceRecord as $rr) { $vals[] = (string)$rr->Value; }
            $changes[] = [$action, (string)$set->Name, (int)$set->TTL, $vals]; $values = $action === 'DELETE' ? [] : $vals; $ttl = (int)$set->TTL;
            return [200, [], "<ChangeResourceRecordSetsResponse$ns><ChangeInfo><Id>/change/C0123ABC</Id><Status>PENDING</Status></ChangeInfo></ChangeResourceRecordSetsResponse>"];
        },
        "$B/change/C0123ABC" => function () use (&$polls, $ns) { $polls++; return [200, [], "<GetChangeResponse$ns><ChangeInfo><Id>/change/C0123ABC</Id><Status>" . ($polls >= 2 ? 'INSYNC' : 'PENDING') . '</Status></ChangeInfo></GetChangeResponse>']; },
    ]);
    $p = new DnsRoute53(['access_key_id' => 'AKIAEXAMPLE0123456789', 'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY']);
    eq($p->domains(), ['a.example'], 'the private zone is not offered');
    $h1 = $p->addTxt('_acme-challenge.a.example', $V1); eq($polls, 2, 'waited until the change was in sync'); $h2 = $p->addTxt('_acme-challenge.a.example', $V2);
    eq($changes[1], ['UPSERT', '_acme-challenge.a.example.', 60, ['"' . $V1 . '"', '"' . $V2 . '"']], 'the second value joins the first');
    ok(str_starts_with(implode(' ', $authSeen[0]), 'Content-Type: text/xml') && count(array_filter($authSeen[0], fn ($h) => str_starts_with($h, 'Authorization: AWS4-HMAC-SHA256 Credential=AKIAEXAMPLE0123456789/'))) === 1);
    $p->removeTxt($h1); eq($changes[2][0] . ' ' . json_encode($changes[2][3]), 'UPSERT ["\"' . $V2 . '\""]'); $p->removeTxt($h2); eq($changes[3][0], 'DELETE', 'the last value deletes the set'); eq($values, []); $p->removeTxt($h2); eq(count($changes), 4, 'removing twice is harmless');
    foreach (['', 'Z111AAA|x|', 'z111aaa|a.example|v', "Z111AAA|a.example|v\n"] as $bad) { throws(DnsProviderException::class, fn () => $p->removeTxt($bad)); }
    fakeWeb(["$B/hostedzone?maxitems=100" => [403, [], '<ErrorResponse xmlns="https://route53.amazonaws.com/doc/2013-04-01/"><Error><Type>Sender</Type><Code>SignatureDoesNotMatch</Code><Message>The request signature we calculated does not match <b>the</b> one</Message></Error></ErrorResponse>']]);
    $e = throws(DnsProviderException::class, fn () => (new DnsRoute53(['access_key_id' => 'AKIAEXAMPLE0123456789', 'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY']))->domains());
    ok(str_contains($e->getMessage(), 'does not match') && str_contains($e->getMessage(), 'check the access key') && !str_contains($e->getMessage(), '<b>') && !str_contains($e->getMessage(), 'wJalr'), $e->getMessage());
    DnsRoute53::$sleeper = null;
});
