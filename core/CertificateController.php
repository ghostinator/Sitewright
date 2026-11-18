<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** Certificates, the DNS accounts that prove ownership of names, and the servers that fetch each certificate (admin only). Secrets are write-only: shown once, stored encrypted. */
final class CertificateController
{
    public function list(array $params): never
    {
        $db = Database::getInstance();
        $now = time();
        $targets = [];
        foreach ($db->fetchAll('SELECT id, certificate_id, name, allow_cidrs, last_pull_at, last_pull_ip, installed_fingerprint, installed_at, install_status, install_error, created_at FROM `' . $db->t('cert_targets') . '` ORDER BY id') as $t) { $targets[(int)$t['certificate_id']][] = $t; }
        $certs = [];
        foreach ($db->fetchAll('SELECT c.id, c.name, c.names, c.dns_provider_id, c.site_id, c.directory, c.state, c.not_before, c.not_after, c.fingerprint, c.last_error, c.attempts, c.next_attempt_at, c.issued_at, c.created_at, p.name provider
            FROM `' . $db->t('certificates') . '` c LEFT JOIN `' . $db->t('dns_providers') . '` p ON p.id = c.dns_provider_id ORDER BY c.name') as $c) {
            $c['id'] = (int)$c['id']; $c['names'] = (array)json_decode((string)$c['names'], true);
            $c['days_left'] = $c['not_after'] !== null ? (int)floor((strtotime($c['not_after'] . ' UTC') - $now) / 86400) : null;
            $c['test'] = $c['directory'] !== '' && str_contains((string)$c['directory'], 'staging');
            $c['targets'] = array_map(static fn (array $t): array => ['id' => (int)$t['id'], 'name' => $t['name'], 'allow_cidrs' => $t['allow_cidrs'], 'last_pull_at' => $t['last_pull_at'], 'last_pull_ip' => $t['last_pull_ip'],
                'installed_at' => $t['installed_at'], 'install_status' => $t['install_status'], 'install_error' => $t['install_error'], 'up_to_date' => $c['fingerprint'] !== null && $t['installed_fingerprint'] === $c['fingerprint'] && $t['install_status'] === 'ok'], $targets[$c['id']] ?? []);
            unset($c['directory']);
            $certs[] = $c;
        }
        $providers = array_map(static fn (array $p): array => ['id' => (int)$p['id'], 'name' => $p['name'], 'type' => $p['type'], 'type_label' => DnsProvider::TYPES[$p['type']] ?? $p['type'], 'dns' => DnsProvider::editsDns((string)$p['type']), 'checked_at' => $p['checked_at'],
            'check_ok' => $p['check_ok'] === null ? null : (bool)$p['check_ok'], 'check_msg' => $p['check_msg']], $db->fetchAll('SELECT id, name, type, checked_at, check_ok, check_msg FROM `' . $db->t('dns_providers') . '` ORDER BY name'));
        $fields = []; foreach (array_keys(DnsProvider::TYPES) as $t) { $fields[$t] = DnsProvider::fields($t); }
        Response::success('Certificates', ['certificates' => $certs, 'dns_accounts' => $providers, 'provider_types' => DnsProvider::TYPES, 'provider_fields' => $fields,
            'environment' => (string)Config::get('acme.environment'), 'custom_service' => (string)Config::get('acme.directory') !== '', 'encryption_ready' => Crypto::configured()]);
    }

    public function createProvider(array $params): never
    {
        $this->needKey();
        $in = Request::json();
        $name = Validator::str($in, 'name', 100, true);
        $type = Validator::enum($in, 'type', array_keys(DnsProvider::TYPES), null) ?? Validator::fail('type', 'choose a DNS provider');
        $id = Certificates::createProvider($name, $type, (array)($in['secrets'] ?? []));
        Audit::log('dns_account_added', null, '', $name . ' (' . $type . ')');
        Response::success('DNS account saved. Test it before you use it.', ['id' => $id], 201);
    }

    public function testProvider(array $params): never
    {
        $id = Validator::id($params['id'] ?? null);
        $this->provider($id);
        $domain = trim((string)(Request::json()['domain'] ?? ''));
        $r = Certificates::testProvider($id, $domain);
        Response::success($r['ok'] ? 'It works' : 'It did not work', $r);
    }

    public function deleteProvider(array $params): never
    {
        $id = Validator::id($params['id'] ?? null);
        $p = $this->provider($id);
        $db = Database::getInstance();
        if ($db->fetchOne('SELECT 1 x FROM `' . $db->t('certificates') . '` WHERE dns_provider_id = :i', ['i' => $id])) { Response::error('A certificate still uses this DNS account. Delete or change that certificate first.', 409); }
        $db->delete('dns_providers', ['id' => $id]);
        Audit::log('dns_account_deleted', null, '', (string)$p['name']);
        Response::success('DNS account deleted');
    }

    public function create(array $params): never
    {
        $this->needKey();
        $in = Request::json();
        $name = Validator::str($in, 'name', 100, true);
        $names = is_array($in['names'] ?? null) ? $in['names'] : preg_split('/[\s,]+/', (string)($in['names'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $provider = Validator::id($in['dns_provider_id'] ?? null);
        $site = isset($in['site_id']) && (int)$in['site_id'] > 0 ? Validator::siteId($in, 'site_id') : 0;
        $id = Certificates::create($name, array_map('strval', (array)$names), $provider, $site);
        Audit::log('certificate_created', null, '', $name . ' (' . implode(', ', array_slice(array_map('strval', (array)$names), 0, 5)) . ')');
        $job = Certificates::queue($id);
        Response::success('Certificate created. Sitewright is getting it now; this takes a minute or two.', ['id' => $id, 'job_id' => $job], 201);
    }

    public function renew(array $params): never
    {
        $id = Validator::id($params['id'] ?? null);
        $c = $this->find($id);
        $db = Database::getInstance();
        $db->update('certificates', ['next_attempt_at' => null], ['id' => $id]);
        Certificates::queueUnlessOpen($id);
        Audit::log('certificate_renew_requested', null, '', (string)$c['name']);
        Response::success('Sitewright is getting a new certificate now', [], 202);
    }

    public function delete(array $params): never
    {
        $id = Validator::id($params['id'] ?? null);
        $c = $this->find($id);
        Certificates::delete($id);
        Audit::log('certificate_deleted', null, '', (string)$c['name']);
        Response::success('Certificate deleted. Servers that already installed it keep it until it expires.');
    }

    public function createTarget(array $params): never
    {
        $id = Validator::id($params['id'] ?? null);
        $c = $this->find($id);
        $in = Request::json();
        $t = Certificates::createTarget($id, Validator::str($in, 'name', 100, true), (string)($in['allow_cidrs'] ?? ''));
        Audit::log('cert_target_added', null, '', $c['name'] . ' -> ' . (string)$in['name']);
        $host = (string)Request::header('Host');
        $url = 'https://' . $host . '/api/cert-pull/' . $t['id'];
        $dest = '/etc/sitewright-certs/' . preg_replace('/[^A-Za-z0-9._-]+/', '-', strtolower((string)$in['name']));
        Response::success('Server added. Copy the token now; it is not shown again.', ['id' => $t['id'], 'token' => $t['token'], 'config' =>
            "URL=$url\nTOKEN={$t['token']}\nDEST=$dest\nRELOAD=\"systemctl reload nginx\"\n"], 201);
    }

    public function deleteTarget(array $params): never
    {
        $id = Validator::id($params['id'] ?? null);
        $db = Database::getInstance();
        $t = $db->fetchOne('SELECT id, name FROM `' . $db->t('cert_targets') . '` WHERE id = :i', ['i' => $id]) ?? Response::notFound('Server not found');
        $db->delete('cert_targets', ['id' => $id]);
        Audit::log('cert_target_deleted', null, '', (string)$t['name']);
        Response::success('Server removed. Its token no longer works.');
    }

    private function needKey(): void
    {
        if (!Crypto::configured()) { Response::error('Set app_key first (php bin/gen-key.php); it encrypts the API tokens and private keys', 409); }
    }

    private function find(int $id): array
    {
        $db = Database::getInstance();
        return $db->fetchOne('SELECT id, name FROM `' . $db->t('certificates') . '` WHERE id = :i', ['i' => $id]) ?? Response::notFound('Certificate not found');
    }

    private function provider(int $id): array
    {
        $db = Database::getInstance();
        return $db->fetchOne('SELECT id, name FROM `' . $db->t('dns_providers') . '` WHERE id = :i', ['i' => $id]) ?? Response::notFound('DNS account not found');
    }
}
