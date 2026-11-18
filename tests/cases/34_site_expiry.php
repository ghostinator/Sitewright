<?php
use Sitewright\Core\{Config, Crypto, SiteExpiry, SafeHttpClient};

// Phase 4a: certificate and domain expiry, and the warnings about them.
if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb(); Config::set('app_key', Crypto::generateKey());
Config::set('sites.rdap_enabled', true); Config::set('sites.expiry_hours', 24);
Config::set('alerts', ['email_to' => [], 'teams_webhook' => 'https://example.webhook.office.com/x', 'telegram_token' => '', 'telegram_chat_id' => '', 'cooldown' => 0, 'login_failures' => 3,
    'events' => ['cert_expiring', 'cert_problem', 'domain_expiring']]);

$NOW = 1_800_000_000;   // a fixed "now" so day counts are exact
$mk = function (string $url = 'https://www.corner.test') use ($db) {
    foreach (['sites', 'site_expiry', 'jobs', 'rate_limits', 'alert_log'] as $t) { try { $db->query("TRUNCATE sw_$t"); } catch (\Throwable) {} }
    $db->query("DELETE FROM sw_kv WHERE k = 'rdap_bootstrap'");
    return (int)$db->insert('sites', ['name' => 'Corner', 'base_url' => $url, 'secret_enc' => 'x']);
};
$alerts = fn () => array_map(fn ($r) => json_decode($r['payload'], true)['event'] . ':' . (json_decode($r['payload'], true)['level'] ?? ''), $db->fetchAll("SELECT payload FROM sw_jobs WHERE type = 'alert' ORDER BY id"));
$certIn = function (float $days, bool $trusted = true) use ($NOW) { SiteExpiry::$certReader = fn () => ['expires' => (int)($NOW + $days * 86400), 'issuer' => "Let's Encrypt", 'names' => 'www.corner.test, corner.test', 'trusted' => $trusted, 'problem' => $trusted ? null : 'Browsers will not trust it.']; };
$row = fn (int $id, string $k = 'cert') => $db->fetchOne('SELECT * FROM sw_site_expiry WHERE site_id = :s AND kind = :k', ['s' => $id, 'k' => $k]);
$noRdap = function () { Config::set('sites.rdap_enabled', false); };
$web = fn (array $pages = []) => fakeWeb($pages);

test('certificate: warns when each threshold (30, 14, 7, 3, 1 days, expired) is crossed, once each, and starts over after a renewal', function () use ($mk, $certIn, $alerts, $row, $noRdap, $web, $NOW) {
    $noRdap(); $web(); $id = $mk();
    foreach ([[40, []], [29, ['cert_expiring:warning']], [28, []], [13, ['cert_expiring:warning']], [6, ['cert_expiring:error']], [5, []], [2, ['cert_expiring:error']], [0.5, ['cert_expiring:error']], [-3, []]] as [$days, $new]) {
        $before = count($alerts()); $certIn($days); SiteExpiry::check($id, $NOW);
        eq(array_slice($alerts(), $before), $new, "at $days days");
    }
    $before = count($alerts()); $certIn(90); SiteExpiry::check($id, $NOW); eq($row($id)['alerted'], null, 'renewed: warnings reset'); eq(count($alerts()), $before);
    $certIn(25); SiteExpiry::check($id, $NOW); eq(array_slice($alerts(), $before), ['cert_expiring:warning'], 'and the next approach warns again');
});

test('certificate: the warning says which site, how many days and the date; an expired one says so', function () use ($mk, $certIn, $row, $noRdap, $web, $db, $NOW) {
    $noRdap(); $web(); $id = $mk(); $certIn(13); SiteExpiry::check($id, $NOW);
    $msg = json_decode($db->fetchOne("SELECT payload FROM sw_jobs WHERE type = 'alert' ORDER BY id DESC LIMIT 1")['payload'], true)['message'];
    ok(str_contains($msg, 'www.corner.test') && str_contains($msg, 'expires in 13 days') && str_contains($msg, gmdate('Y-m-d', $NOW + 13 * 86400)) && str_contains($msg, 'Cloudflare'), $msg);
    $certIn(-1); SiteExpiry::check($id, $NOW);
    $msg = json_decode($db->fetchOne("SELECT payload FROM sw_jobs WHERE type = 'alert' ORDER BY id DESC LIMIT 1")['payload'], true)['message']; ok(str_contains($msg, 'has expired'), $msg);
});

test('certificate: one that browsers would not trust is flagged once; a failed check keeps what was known and never warns', function () use ($mk, $certIn, $alerts, $row, $noRdap, $web, $NOW) {
    $noRdap(); $web(); $id = $mk(); $certIn(100, false);
    SiteExpiry::check($id, $NOW); SiteExpiry::check($id, $NOW);
    eq($alerts(), ['cert_problem:error'], 'once'); eq($row($id)['state'], 'problem'); ok(str_contains($row($id)['detail'], 'Browsers will not trust it'));
    $certIn(10); SiteExpiry::check($id, $NOW + 1); $known = $row($id)['expires_at'];
    SiteExpiry::$certReader = fn () => ['error' => 'Could not complete a secure connection: timed out'];
    $n = count($alerts()); SiteExpiry::check($id, $NOW + 2);
    eq([$row($id)['state'], $row($id)['expires_at'], count($alerts())], ['error', $known, $n]); ok(str_contains($row($id)['error'], 'timed out'));
});

test('certificate: a plain-http site has none; a name that resolves to a private address is refused by the address guard', function () use ($mk, $noRdap, $web, $row, $db, $NOW) {
    $noRdap(); $web(); $id = $mk('http://plain.test'); SiteExpiry::check($id, $NOW); eq($row($id), null);
    $id = $mk('https://internal.test'); SiteExpiry::$certReader = fn () => throw new \RuntimeException('must not connect');
    SafeHttpClient::setDefault(new SafeHttpClient(fn () => ['10.0.0.5'], fn () => [200, [], ''], Config::get('fetch')));
    SiteExpiry::check($id, $NOW); eq($row($id)['state'], 'error'); ok(str_contains($row($id)['error'], 'not allowed'), $row($id)['error']);
});

$RDAP = ['https://data.iana.org/rdap/dns.json' => [200, ['Content-Type' => 'application/json'], json_encode(['services' => [[['test'], ['http://insecure.reg.test/', 'https://rdap.reg.test/']], [['bad tld!'], ['https://x.test/']]]])],
    'https://rdap.reg.test/domain/www.corner.test' => [404, [], '{}'],
    'https://rdap.reg.test/domain/corner.test' => [200, ['Content-Type' => 'application/rdap+json'], json_encode(['events' => [['eventAction' => 'registration', 'eventDate' => '2020-01-01T00:00:00Z'], ['eventAction' => 'expiration', 'eventDate' => '2027-03-01T00:00:00Z']]])]];
test('domain: the registry is found through IANA, the host is shortened until a registered name answers, and the expiry date is read', function () use ($mk, $certIn, $row, $web, $RDAP, $db, $NOW) {
    Config::set('sites.rdap_enabled', true); $web($RDAP); $id = $mk(); $certIn(100); SiteExpiry::check($id, $NOW);
    $r = $row($id, 'domain'); eq([$r['state'], $r['expires_at'], $r['detail']], ['ok', '2027-03-01 00:00:00', 'Registered as corner.test']);
    $urls = array_column($GLOBALS['__calls'], 'url'); ok(in_array('https://rdap.reg.test/domain/www.corner.test', $urls, true) && in_array('https://rdap.reg.test/domain/corner.test', $urls, true));
    ok(!in_array('http://insecure.reg.test/domain/corner.test', $urls, true), 'only https registries are used');
    $web($RDAP); SiteExpiry::check($id, $NOW); ok(!in_array('https://data.iana.org/rdap/dns.json', array_column($GLOBALS['__calls'], 'url'), true), 'the registry list is cached for a week');
});

test('domain: warnings use the same steps; IANA being down falls back to the old list; every failure is recorded, not thrown', function () use ($mk, $certIn, $alerts, $row, $web, $RDAP, $NOW) {
    Config::set('sites.rdap_enabled', true); $web($RDAP); $id = $mk(); $certIn(100);
    SiteExpiry::check($id, strtotime('2027-02-20 00:00:00 UTC'));   // 9 days before 2027-03-01
    eq($alerts(), ['domain_expiring:warning'], '9 days left: the 14-day step'); 
    $web(['https://data.iana.org/rdap/dns.json' => [503, [], ''], 'https://rdap.reg.test/domain/www.corner.test' => [404, [], '{}'], 'https://rdap.reg.test/domain/corner.test' => $RDAP['https://rdap.reg.test/domain/corner.test']]);
    SiteExpiry::check($id, $NOW); eq($row($id, 'domain')['state'], 'ok');
    foreach ([[[500, [], '{}']], [[200, [], 'not json']], [[200, [], json_encode(['events' => [['eventAction' => 'expiration', 'eventDate' => 'garbage']]])]]] as [$answer]) {
        $web(['https://data.iana.org/rdap/dns.json' => $RDAP['https://data.iana.org/rdap/dns.json'], 'https://rdap.reg.test/domain/www.corner.test' => [404, [], '{}'], 'https://rdap.reg.test/domain/corner.test' => $answer]);
        SiteExpiry::check($id, $NOW); eq($row($id, 'domain')['state'], 'error'); eq($row($id, 'domain')['expires_at'], '2027-03-01 00:00:00', 'the date we knew stays');
    }
});

test('domain: turned off, no registry is contacted and nothing is kept; an unknown ending is explained', function () use ($mk, $certIn, $row, $web, $RDAP, $NOW) {
    $id = $mk(); $certIn(100); Config::set('sites.rdap_enabled', false); $web($RDAP); SiteExpiry::check($id, $NOW);
    eq($row($id, 'domain'), null); eq(array_values(array_filter(array_column($GLOBALS['__calls'], 'url'), fn ($u) => str_contains($u, 'rdap'))), []);
    Config::set('sites.rdap_enabled', true); $id = $mk('https://thing.unknowntld'); $web($RDAP); SiteExpiry::check($id, $NOW);
    ok(str_contains($row($id, 'domain')['error'], 'does not offer a public RDAP service'), $row($id, 'domain')['error']);
});

test('scheduler: sites never checked or checked more than sites.expiry_hours ago get one job; the job is not an app outage', function () use ($mk, $certIn, $noRdap, $web, $db, $NOW) {
    $noRdap(); $web(); $id = $mk(); $certIn(100);
    eq(SiteExpiry::scheduleDue(), 1); eq(SiteExpiry::scheduleDue(), 0, 'one already waiting');
    while (\Sitewright\Core\Jobs::runNext()) {}
    ok($db->fetchOne('SELECT expiry_at FROM sw_sites')['expiry_at'] !== null); eq(SiteExpiry::scheduleDue(), 0, 'just checked');
    $db->query('UPDATE sw_sites SET expiry_at = NOW() - INTERVAL 25 HOUR'); eq(SiteExpiry::scheduleDue(), 1, 'a day later');
    $db->query("UPDATE sw_jobs SET status = 'failed', finished_at = NOW() WHERE type = 'site_expiry'");
    $c = testDbConfig(); $env = sprintf('SW_DB_HOST=%s SW_DB_NAME=%s SW_DB_USER=%s SW_DB_PASS=%s SW_LOG_FILE=/dev/null', escapeshellarg($c['host']), 'sw_test', escapeshellarg($c['user']), escapeshellarg($c['pass']));
    @unlink(dirname(__DIR__, 2) . '/storage/health.state');
    ok(str_contains((string)shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 2)) . " && $env php bin/healthcheck.php 2>&1; echo \"exit=\$?\""), 'exit=0'));
    @unlink(dirname(__DIR__, 2) . '/storage/health.state');
});

test('the default reader reads a real certificate over TLS (self-signed: read anyway and flagged as not trusted)', function () use ($mk, $noRdap) {
    $noRdap();
    $key = openssl_pkey_new(['private_key_bits' => 2048]); $csr = openssl_csr_new(['commonName' => 'corner.test'], $key);
    $cert = openssl_csr_sign($csr, null, $key, 10); openssl_x509_export($cert, $crt); openssl_pkey_export($key, $k);
    $dir = sys_get_temp_dir() . '/swtls' . bin2hex(random_bytes(4)); mkdir($dir); file_put_contents("$dir/c.pem", $crt . $k);
    $probe = stream_socket_server('tcp://127.0.0.1:0', $e1, $e2); $port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1); fclose($probe);
    file_put_contents("$dir/s.php", '<?php $c = stream_context_create(["ssl" => ["local_cert" => "' . $dir . '/c.pem", "verify_peer" => false]]); $s = stream_socket_server("ssl://127.0.0.1:' . $port . '", $a, $b, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $c); $end = time() + 60; while (time() < $end) { $x = @stream_socket_accept($s, 5); if ($x) { fclose($x); } }');
    $proc = proc_open([PHP_BINARY, "$dir/s.php"], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    try {
        for ($i = 0; $i < 50; $i++) { if (($c = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 1)) !== false) { fclose($c); break; } usleep(100000); }   // wait until the child listens
        SiteExpiry::$port = $port; SiteExpiry::$certReader = null;
        $r = SiteExpiry::readCert('corner.test', ['127.0.0.1']);
        ok(!isset($r['error']), json_encode($r)); eq($r['trusted'], false); ok(str_contains((string)$r['problem'], 'not trust'));
        $days = ($r['expires'] - time()) / 86400; ok($days > 9 && $days < 11, "expires in $days days");
        SiteExpiry::$port = 1; $r = SiteExpiry::readCert('corner.test', ['127.0.0.1']); ok(isset($r['error']) && str_contains($r['error'], 'secure connection'), json_encode($r));
    } finally { SiteExpiry::$port = 443; proc_terminate($proc); proc_close($proc); @unlink("$dir/c.pem"); @unlink("$dir/s.php"); @rmdir($dir); }
});

test('checklist: each step is done or not from what Sitewright knows, with what to do next; sites without the plugin or with RDAP off have fewer steps', function () use ($mk, $certIn, $noRdap, $web, $db, $NOW) {
    Config::set('sites.rdap_enabled', true);
    $id = $mk('https://corner.test'); $db->query('UPDATE sw_sites SET sync_enabled = 1, plugin_version = "1.0.1", last_status = "ok"');
    foreach (['site_profile', 'watch_items', 'audits', 'gsc_connections'] as $t) { $db->query("TRUNCATE sw_$t"); }
    $c = fn () => array_column(\Sitewright\Core\SiteChecklist::for($id)['items'], null, 'key');
    $x = $c(); eq($x['read']['done'], false); eq($x['read']['action'], 'profile-refresh'); eq($x['plugin']['done'], false); ok(str_contains($x['plugin']['detail'], 'update it'), $x['plugin']['detail']);
    eq([$x['watch']['action'], $x['audit']['action']], ['profile-watch', 'profile-audit']); eq(\Sitewright\Core\SiteChecklist::for($id)['done'], 0);
    $db->query('UPDATE sw_sites SET plugin_version = "1.1.0", profile_at = NOW()');
    $db->query("INSERT INTO sw_site_profile (site_id, field, found, value, state, updated_at) VALUES ($id, 'https', 'Yes', NULL, 'info', NOW()), ($id, 'robots', 'Allowed', NULL, 'info', NOW()), ($id, 'sitemap_url', 'https://corner.test/sitemap.xml', NULL, 'pending', NOW()), ($id, 'phone', '555', '555', 'used', NOW()), ($id, 'street', '1 A St', '1 A St', 'used', NOW()), ($id, 'city', 'X', NULL, 'manual', NOW())");
    $x = $c(); eq([$x['read']['done'], $x['https']['done'], $x['robots']['done'], $x['sitemap']['done'], $x['plugin']['done'], $x['business']['done']], [true, true, true, true, true, false], 'a typed value with no text does not count');
    eq($x['review']['done'], false); ok(str_contains($x['review']['detail'], '1 item waiting'), $x['review']['detail']);
    $noRdap(); ok(!isset($c()['domain']), 'RDAP off: no domain step');
    $db->query("UPDATE sw_sites SET base_url = 'http://corner.test'"); ok(!isset($c()['cert']), 'plain http: no certificate step');
});
