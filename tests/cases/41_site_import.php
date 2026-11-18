<?php
use Sitewright\Core\{Certificates, Config, Crypto, DnsProvider, FetchException, SiteImport};

// Adding a DNS account's domains as sites: only those that serve a website.
if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb(); $key = Crypto::generateKey(); Config::set('app_key', $key);
seedAdmin('root', 'root passphrase long here', 'admin'); seedAdmin('ed', 'editor passphrase long here', 'editor');
$srv = startServer(['SW_APP_KEY' => $key]); $base = $srv['base'];
$as = function (string $u, string $p) use ($base) { $h = new TestHttp($base); eq($h->login($u, $p)['status'], 200); return $h; };
DnsProvider::$factories['fake'] = static fn (array $c) => new class($c) extends DnsProvider {
    public function __construct(private array $c) {}
    public function addTxt(string $name, string $value): string { return $name; }
    public function removeTxt(string $handle): void {}
    public function verify(): string { return 'ok'; }
    public function domains(): ?array { return isset($this->c['zones']) ? explode(',', $this->c['zones']) : null; }
};
$page = fn (string $t = 'A site') => [200, ['Content-Type' => 'text/html; charset=utf-8'], "<!doctype html><html><head><title>$t</title></head><body><h1>$t</h1></body></html>"];
$reset = function () use ($db) { foreach (['sites', 'jobs', 'dns_providers', 'site_profile', 'audit_log'] as $t) { try { $db->query("TRUNCATE sw_$t"); } catch (\Throwable) {} } };

test('probe: a domain with a website is found (with or without www); everything else says why not', function () use ($page) {
    fakeWeb([
        'https://shop.example/' => $page(), 'https://www.only-www.example/' => $page(), 'https://redirect.example/' => [301, ['Location' => 'https://shop.example/'], ''], 'https://blocked.example/' => [403, [], 'Just a moment...'],
        'https://dead.example/' => new FetchException('Could not fetch the URL'), 'https://www.dead.example/' => new FetchException('Host could not be resolved'), 'https://api.example/' => [200, ['Content-Type' => 'application/json'], '{}'],
        'https://err.example/' => [500, [], 'oops'], 'https://www.err.example/' => [500, [], 'oops'], 'https://notfound.example/' => [404, [], 'x'], 'https://www.notfound.example/' => [404, [], 'x'], 'https://www.apex-to-www.example/' => $page(),
        'https://apex-to-www.example/' => [301, ['Location' => 'https://www.apex-to-www.example/'], ''], 'https://sub.example/' => [301, ['Location' => 'https://app.sub.example/home'], ''], 'https://app.sub.example/home' => $page()]);
    eq(SiteImport::probe('shop.example'), ['url' => 'https://shop.example', 'reason' => null]);
    eq(SiteImport::probe('only-www.example')['url'], 'https://www.only-www.example');
    eq(SiteImport::probe('apex-to-www.example')['url'], 'https://www.apex-to-www.example', 'the apex that redirects to www is the www site');
    eq(SiteImport::probe('sub.example')['url'], 'https://app.sub.example', 'a redirect to a subdomain of the same domain is the same site');
    foreach (['redirect.example' => 'Redirects to shop.example', 'blocked.example' => 'Blocks automated visitors (HTTP 403)', 'api.example' => 'Not a web page (application/json)', 'err.example' => 'Answered with HTTP 500', 'notfound.example' => 'Answered with HTTP 404'] as $d => $why) { eq(SiteImport::probe($d), ['url' => null, 'reason' => $why], $d); }
    $r = SiteImport::probe('dead.example'); eq($r['url'], null); ok(str_starts_with((string)$r['reason'], 'Did not answer'), (string)$r['reason']);
});

test('import job: adds only domains with a website, skips the rest with a reason, never twice, and the new sites are read and checked like any other', function () use ($reset, $db, $page) {
    $reset(); $db->insert('sites', ['name' => 'Old', 'base_url' => 'https://www.old.example', 'secret_enc' => '']);
    fakeWeb(['https://shop.example/' => $page(), 'https://blog.example/' => $page(), 'https://park.example/' => [500, [], ''], 'https://www.park.example/' => [500, [], '']]);
    $r = SiteImport::handleJob(['domains' => ['shop.example', 'old.example', 'blog.example', 'park.example', 'shop.example', "bad\nname.example", '*.wild.example', 'not a domain']]);
    eq(array_column($r['data']['added'], 'domain'), ['shop.example', 'blog.example']); eq(array_column($r['data']['skipped'], 'reason', 'domain'), ['old.example' => 'Already added', 'park.example' => 'Answered with HTTP 500']);
    eq($r['message'], 'Added 2 sites, skipped 2');
    $rows = $db->fetchAll('SELECT name, base_url, sync_enabled FROM sw_sites ORDER BY id'); eq(array_column($rows, 'base_url'), ['https://www.old.example', 'https://shop.example', 'https://blog.example']); eq((int)$rows[1]['sync_enabled'], 0, 'no plugin');
    eq((int)$db->fetchOne("SELECT COUNT(*) n FROM sw_jobs WHERE type = 'site_profile'")['n'], 2, 'each new site is read by the setup guide');
    fakeWeb(['https://shop.example/' => $page()]); $again = SiteImport::handleJob(['domains' => ['shop.example']]); eq([count($again['data']['added']), $again['data']['skipped'][0]['reason']], [0, 'Already added']);
    eq(count($GLOBALS['__calls']), 0, 'an existing site is not even contacted');
});

test('import job: a very long list is cut at the limit; names that are not plain domain names never reach the network', function () use ($reset, $db) {
    $reset(); $many = array_map(fn ($i) => "d$i.example", range(1, 150)); eq(count(SiteImport::clean($many)), SiteImport::MAX);
    eq(SiteImport::clean(['A.Example', 'a.example', 'http://y.example', 'z.example/path', "x.example\r\n", '', 5, null, ['a'], 'ok-name.example', "in\nside.example"]), ['a.example', 'x.example', 'ok-name.example'], 'surrounding white space is trimmed; anything else that is not a plain name is dropped');
});

test('API: administrators list a DNS account\'s domains with what is already a site, and import them as a background job; others cannot', function () use ($as, $reset, $db) {
    $reset(); $a = $as('root', 'root passphrase long here'); $e = $as('ed', 'editor passphrase long here');
    $db->insert('sites', ['name' => 'Have', 'base_url' => 'https://have.example', 'secret_enc' => '']);
    $p = Certificates::createProvider('Zones', 'fake', ['zones' => 'have.example,new.example']); $none = Certificates::createProvider('NoList', 'fake', []);
    eq($e->api('GET', "/api/dns-accounts/$p/domains")['status'], 403); eq($e->api('POST', '/api/sites/import', ['domains' => ['new.example']])['status'], 403);
    // the provider is a fake that only exists in this process, so the listing itself is called directly (roles were checked over HTTP above)
    $r = call(Sitewright\Core\SiteController::class, 'accountDomains', [], ['id' => (string)$p]); eq($r['status'], 200, json_encode($r['json'])); eq($r['json']['data']['domains'], [['name' => 'have.example', 'added' => true], ['name' => 'new.example', 'added' => false]]);
    eq(call(Sitewright\Core\SiteController::class, 'accountDomains', [], ['id' => (string)$none])['status'], 409); eq(call(Sitewright\Core\SiteController::class, 'accountDomains', [], ['id' => '9999'])['status'], 404);
    $r = $a->api('POST', '/api/sites/import', ['domains' => ['new.example', "bad\nname", 'new.example']]); eq($r['status'], 202, $r['body']);
    $job = $db->fetchOne("SELECT payload FROM sw_jobs WHERE type = 'site_import'"); eq(json_decode($job['payload'], true)['domains'], ['new.example'], 'only the clean names are queued');
    eq($a->api('POST', '/api/sites/import', ['domains' => ['not a domain']])['status'], 400); eq($a->api('POST', '/api/sites/import', ['domains' => []])['status'], 400);
    $l = $a->api('GET', '/api/sites'); eq($l['json']['data']['dns_accounts'][0]['name'], 'NoList');
});
