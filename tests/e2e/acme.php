<?php
// Runs inside the sw-php container (see tests/docker/acme-e2e.sh). Sitewright's ACME client and certificate service against Pebble.
declare(strict_types=1);
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str, string $file, int $line): bool { throw new ErrorException($str, 0, $no, $file, $line); });
require __DIR__ . '/../../core/autoload.php';
require __DIR__ . '/../lib.php';
use Sitewright\Core\{Acme, AcmeException, Certificates, Config, Crypto, DnsProvider, DnsProviderException, SafeHttpClient};

putenv('SW_BASE_URL'); putenv('SW_ALLOWED_HOSTS');
Config::useFile('/nonexistent/config.php'); Config::set('log.file', '/dev/null');
$db = freshDb(); Config::set('app_key', Crypto::generateKey());
Config::set('fetch.dev_allow_cidrs', ['172.16.0.0/12', '10.0.0.0/8', '192.168.0.0/16']);   // the Docker network; production never sets this
Config::set('fetch.allow_ports', array_merge((array)Config::get('fetch.allow_ports'), [14000]));
Config::set('fetch.cainfo', (string)getenv('PEBBLE_CA'));
Config::set('acme.directory', (string)getenv('PEBBLE_DIR')); Config::set('acme.dns_wait_seconds', 0); Config::set('acme.email', 'owner@example.test'); Config::set('acme.renew_days', 30);
Config::set('alerts', ['email_to' => [], 'teams_webhook' => 'https://example.webhook.office.com/x', 'telegram_token' => '', 'telegram_chat_id' => '', 'cooldown' => 0, 'login_failures' => 3, 'events' => ['cert_issued', 'cert_issue_failed', 'cert_target_stale']]);
SafeHttpClient::setDefault(null);
Certificates::$sleeper = static fn (int $ms) => null;
$fail = 0;
$check = function (string $name, bool $ok, string $detail = '') use (&$fail) { echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "  -> $detail") . "\n"; if (!$ok) { $fail++; } };

/** DNS for the test: Pebble's companion DNS server, driven over its management API. */
function ctx($c) { return $c; }
final class TestDns extends DnsProvider
{
    public static array $log = []; public static bool $publish = true;
    public function __construct(private array $c) {}
    private function post(string $path, array $body): void
    {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode($body), 'timeout' => 10, 'ignore_errors' => true]]);
        $body = @file_get_contents(getenv('CHALLTESTSRV') . $path, false, ctx($ctx));
        if ($body === false || !str_contains((string)($http_response_header[0] ?? ''), ' 200')) { throw new DnsProviderException('test DNS server said: ' . ($http_response_header[0] ?? 'not reachable') . ' ' . substr((string)$body, 0, 80)); }
    }
    public function addTxt(string $name, string $value): string { self::$log[] = "add $name"; if (self::$publish) { $this->post('/set-txt', ['host' => $name . '.', 'value' => $value]); } return $name; }
    public function removeTxt(string $handle): void { self::$log[] = "remove $handle"; $this->post('/clear-txt', ['host' => $handle . '.']); }
    public function verify(): string { return 'ok'; }
}
DnsProvider::$factories['challtestsrv'] = static fn (array $c) => new TestDns($c);

// ---- pure helpers ----------------------------------------------------------------------------------------------------------------
$check('names: valid ones are lower-cased, wildcards allowed, everything else refused', Acme::cleanName(' Example.COM ') === 'example.com' && Acme::cleanName('*.example.com') === '*.example.com'
    && Acme::cleanName('a_b.example.com') === null && Acme::cleanName("a.example.com\nDNS:evil.com") === null && Acme::cleanName('localhost') === null && Acme::cleanName('*.*.example.com') === null);
$check('TXT name: a wildcard is validated at the name itself', Acme::txtName('*.example.com') === '_acme-challenge.example.com' && Acme::txtName('www.example.com') === '_acme-challenge.www.example.com');
$k = Acme::newCertificateKey(); $der = Acme::csr(['example.com', '*.example.com'], $k);
$check('CSR: DER with both names and the right key', strlen($der) > 100 && str_contains($der, 'example.com') && openssl_csr_get_subject("-----BEGIN CERTIFICATE REQUEST-----\n" . chunk_split(base64_encode($der), 64) . "-----END CERTIFICATE REQUEST-----\n")['CN'] === 'example.com');
try { Acme::csr(["a.example.com\nDNS:evil.com"], $k); $check('CSR: a name with a line break is refused', false); } catch (AcmeException $e) { $check('CSR: a name with a line break is refused', true); }

// ---- a certificate, start to finish ------------------------------------------------------------------------------------------------
$pid = Certificates::createProvider('Test DNS', 'challtestsrv', []);
$cid = Certificates::create('E2E wildcard', ['E2E-Test.example', '*.e2e-test.example'], $pid);
$r = Certificates::issue($cid);
$c = $db->fetchOne('SELECT * FROM sw_certificates WHERE id = :i', ['i' => $cid]);
$check('issue: a certificate for a name and its wildcard (two TXT records on one name) comes back', $r['ok'] === true && $c['state'] === 'issued', json_encode($r));
$info = Acme::describe((string)$c['chain_pem']);
$check('the certificate covers both names and the private key matches it', $info !== null && in_array('e2e-test.example', $info['names'], true) && in_array('*.e2e-test.example', $info['names'], true) && Acme::keyMatches((string)$c['chain_pem'], Crypto::decrypt((string)$c['key_enc'], 'cert:' . $cid)), json_encode($info));
$check('the private key is stored encrypted, and bound to this certificate', !str_contains((string)$c['key_enc'], 'PRIVATE KEY') && (function () use ($c, $cid) { try { Crypto::decrypt((string)$c['key_enc'], 'cert:' . ($cid + 1)); return false; } catch (\Throwable) { return true; } })());
$check('expiry, fingerprint and issue time are recorded; the failure counters are clear', $c['not_after'] > gmdate('Y-m-d H:i:s') && preg_match('/^[a-f0-9]{64}$/', (string)$c['fingerprint']) === 1 && $c['issued_at'] !== null && (int)$c['attempts'] === 0 && $c['last_error'] === null);
$adds = count(array_filter(TestDns::$log, fn ($l) => str_starts_with($l, 'add '))); $removes = count(array_filter(TestDns::$log, fn ($l) => str_starts_with($l, 'remove ')));
$check('every TXT record that was added was removed again', $adds === 2 && $removes === 2, json_encode(TestDns::$log));
$check('the account was registered once and kept (key encrypted)', (int)$db->fetchOne('SELECT COUNT(*) n FROM sw_acme_accounts')['n'] === 1);
$r2 = Certificates::issue($cid); $c2 = $db->fetchOne('SELECT fingerprint FROM sw_certificates WHERE id = :i', ['i' => $cid]);
$check('a second issue reuses the account and gives a new certificate with a new key', $r2['ok'] === true && $c2['fingerprint'] !== $c['fingerprint'] && (int)$db->fetchOne('SELECT COUNT(*) n FROM sw_acme_accounts')['n'] === 1);

// ---- when it goes wrong ------------------------------------------------------------------------------------------------------------
$before = $db->fetchOne('SELECT chain_pem, fingerprint FROM sw_certificates WHERE id = :i', ['i' => $cid]);
TestDns::$publish = false; TestDns::$log = [];
$r3 = Certificates::issue($cid); $c3 = $db->fetchOne('SELECT * FROM sw_certificates WHERE id = :i', ['i' => $cid]);
$check('a validation that fails is reported with the reason, and the cleanup still runs', $r3['ok'] === false && $c3['last_error'] !== null && (int)$c3['attempts'] === 1 && count(array_filter(TestDns::$log, fn ($l) => str_starts_with($l, 'add '))) >= 1 && count(array_filter(TestDns::$log, fn ($l) => str_starts_with($l, 'remove '))) === count(array_filter(TestDns::$log, fn ($l) => str_starts_with($l, 'add '))), json_encode([$r3, TestDns::$log]));
$check('the certificate that was already valid is kept, and the next try is planned', $c3['state'] === 'issued' && $c3['chain_pem'] === $before['chain_pem'] && $c3['next_attempt_at'] > gmdate('Y-m-d H:i:s'));
$ev = array_map(fn ($x) => json_decode($x['payload'], true)['event'], $db->fetchAll("SELECT payload FROM sw_jobs WHERE type = 'alert' ORDER BY id"));
$check('the owner is told about the issues and the failure', in_array('cert_issued', $ev, true) && in_array('cert_issue_failed', $ev, true), json_encode($ev));
TestDns::$publish = true;

// ---- scheduling --------------------------------------------------------------------------------------------------------------------
$db->query('DELETE FROM sw_jobs'); $db->query('UPDATE sw_certificates SET next_attempt_at = NULL, not_before = UTC_TIMESTAMP(), not_after = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 90 DAY)');   // Pebble sometimes issues 6-day certificates: pin the lifetime
$dbg = $db->fetchOne('SELECT state, not_after, directory, next_attempt_at, last_attempt_at FROM sw_certificates WHERE id = ' . $cid);
$q = Certificates::scheduleDue();
$check('far from expiry: nothing is queued', $q === 0, json_encode([$q, $dbg, Config::get('acme.renew_days'), gmdate('Y-m-d H:i:s')]));
$db->query("UPDATE sw_certificates SET not_before = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 80 DAY), not_after = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 DAY)");
$check('inside the renewal window: queued once, not twice', Certificates::scheduleDue() === 1 && Certificates::scheduleDue() === 0);
$db->query('DELETE FROM sw_jobs'); $db->query("UPDATE sw_certificates SET not_before = UTC_TIMESTAMP(), not_after = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 60 DAY), directory = 'https://other.example/dir'");
$check('made with another service (staging to production): reissued', Certificates::scheduleDue() === 1);

$db->query("UPDATE sw_certificates SET directory = '" . Config::get('acme.directory') . "', not_before = UTC_TIMESTAMP(), not_after = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 6 DAY)"); $db->query('DELETE FROM sw_jobs');
$check('a short-lived (6-day) certificate that was just issued is not renewed in a loop', Certificates::scheduleDue() === 0);
$db->query("UPDATE sw_certificates SET not_before = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 4 DAY), not_after = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 2 DAY)");
$check('...and is renewed when a third of its life is left', Certificates::scheduleDue() === 1);

echo $fail === 0 ? "\nACME E2E: ALL PASSED\n" : "\nACME E2E: $fail FAILED\n";
exit($fail === 0 ? 0 : 1);
