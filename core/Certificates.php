<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Certificates from Let's Encrypt, proven by DNS. Sitewright issues and renews them and keeps the private key encrypted; it never logs in to a
 * server. A server that needs a certificate runs a small script (deploy/sw-cert-pull) that fetches it with its own revocable token, installs it
 * and reloads the web server, then reports what it installed. A failed issue never fails its job (the reason is recorded and retried later with
 * growing pauses); a certificate that is still valid keeps being served while a renewal is failing.
 */
final class Certificates
{
    public const DIRECTORIES = ['staging' => 'https://acme-staging-v02.api.letsencrypt.org/directory', 'production' => 'https://acme-v02.api.letsencrypt.org/directory'];
    private const BUDGET_SECONDS = 270;   // below job_stale_seconds (600), with Jobs::touch() on every step
    private const STALE_DAYS = 3;         // a server that has not installed the newest certificate this long after it was issued is reported

    /** Test seam: fn(int $milliseconds). */
    public static $sleeper = null;

    public static function directoryUrl(): string
    {
        $o = (string)Config::get('acme.directory');
        if ($o !== '') { return $o; }   // config file only (tests, other ACME services)
        return self::DIRECTORIES[(string)Config::get('acme.environment')] ?? self::DIRECTORIES['staging'];
    }

    // ------------------------------------------------------------------ DNS accounts

    /** @param array<string,string> $secrets @throws ResponseException */
    public static function createProvider(string $name, string $type, array $secrets): int
    {
        if (!isset(DnsProvider::TYPES[$type]) && !isset(DnsProvider::$factories[$type])) { Validator::fail('type', 'unknown DNS provider'); }
        if ($name === '' || mb_strlen($name) > 100) { Validator::fail('name', 'give it a name of up to 100 characters'); }
        $clean = [];
        foreach (DnsProvider::fields($type) as $k => $label) {
            $v = trim((string)($secrets[$k] ?? ''));
            if ($v === '' || strlen($v) > 300 || preg_match('/[\x00-\x1F\x7F\s]/', $v)) { Validator::fail($k, 'enter the ' . $label . ' (no spaces)'); }
            $clean[$k] = $v;
        }
        foreach ($secrets as $k => $v) { if (!isset($clean[$k]) && isset(DnsProvider::$factories[$type])) { $clean[(string)$k] = (string)$v; } }
        try { DnsProvider::make($type, $clean); } catch (DnsProviderException $e) { Validator::fail('token', $e->getMessage()); }
        $db = Database::getInstance();
        if ($db->fetchOne('SELECT 1 x FROM `' . $db->t('dns_providers') . '` WHERE name = :n', ['n' => $name])) { Validator::fail('name', 'there is already a DNS account with this name'); }
        $id = (int)$db->insert('dns_providers', ['name' => $name, 'type' => $type, 'config_enc' => 'x']);
        $db->update('dns_providers', ['config_enc' => Crypto::encrypt((string)json_encode($clean), 'dns:' . $id)], ['id' => $id]);
        return $id;
    }

    public static function provider(int $id): DnsProvider
    {
        $db = Database::getInstance();
        $r = $db->fetchOne('SELECT type, config_enc FROM `' . $db->t('dns_providers') . '` WHERE id = :i', ['i' => $id]) ?? throw new DnsProviderException('The DNS account no longer exists');
        return DnsProvider::make((string)$r['type'], (array)json_decode(Crypto::decrypt((string)$r['config_enc'], 'dns:' . $id), true));
    }

    /** Check the credentials (and, when a domain is given, that records can be added and removed there). Records the outcome. @return array{ok:bool,message:string} */
    public static function testProvider(int $id, string $domain = ''): array
    {
        $db = Database::getInstance();
        try {
            $p = self::provider($id);
            $msg = $domain !== '' ? $p->test($domain) : $p->verify();
            $ok = true;
        } catch (\Throwable $e) {
            $ok = false; $msg = $e instanceof DnsProviderException ? $e->getMessage() : 'The check failed (see the log)';
            if (!$e instanceof DnsProviderException) { Logger::warn('dns_test_failed', ['provider' => $id, 'error' => get_class($e)]); }
        }
        $db->update('dns_providers', ['checked_at' => gmdate('Y-m-d H:i:s'), 'check_ok' => (int)$ok, 'check_msg' => mb_substr($msg, 0, 300)], ['id' => $id]);
        return ['ok' => $ok, 'message' => $msg];
    }

    // ------------------------------------------------------------------ certificates

    /** @param list<string> $names @throws ResponseException */
    public static function create(string $name, array $names, int $providerId, int $siteId = 0): int
    {
        $db = Database::getInstance();
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,99}$/D', $name)) { Validator::fail('name', 'use letters, numbers, spaces, dots, dashes and underscores (up to 100)'); }
        $clean = [];
        foreach ($names as $n) {
            $c = Acme::cleanName((string)$n);
            if ($c === null) { Validator::fail('names', mb_substr((string)$n, 0, 60) . ' is not a valid domain name'); }
            $clean[$c] = true;
        }
        $clean = array_keys($clean);
        if (!$clean || count($clean) > 20) { Validator::fail('names', 'give between 1 and 20 domain names'); }
        if (!$db->fetchOne('SELECT 1 x FROM `' . $db->t('dns_providers') . '` WHERE id = :i', ['i' => $providerId])) { Validator::fail('dns_provider_id', 'choose a DNS account'); }
        if ($db->fetchOne('SELECT 1 x FROM `' . $db->t('certificates') . '` WHERE name = :n', ['n' => $name])) { Validator::fail('name', 'there is already a certificate with this name'); }
        return (int)$db->insert('certificates', ['name' => $name, 'names' => json_encode($clean), 'dns_provider_id' => $providerId, 'site_id' => $siteId]);
    }

    public static function delete(int $id): void
    {
        $db = Database::getInstance();
        $db->delete('cert_targets', ['certificate_id' => $id]);
        $db->delete('certificates', ['id' => $id]);
    }

    public static function queue(int $id): int
    {
        return Jobs::enqueue('cert_issue', ['certificate_id' => $id], null, 0, 1);
    }

    public static function queueUnlessOpen(int $id): bool
    {
        $db = Database::getInstance();
        if ($db->fetchOne('SELECT 1 x FROM `' . $db->t('jobs') . "` WHERE type = 'cert_issue' AND status IN ('queued','running') AND payload = :p", ['p' => (string)json_encode(['certificate_id' => $id])])) { return false; }
        self::queue($id);
        return true;
    }

    public static function handleJob(array $p): array
    {
        $r = self::issue((int)($p['certificate_id'] ?? 0));
        return ['message' => $r['message'], 'data' => $r];
    }

    /** Get (or renew) the certificate now. Never throws. @return array{ok:bool,message:string} */
    public static function issue(int $id, ?int $now = null): array
    {
        $now ??= time();
        $db = Database::getInstance();
        $t = $db->t('certificates');
        $c = $db->fetchOne("SELECT * FROM `$t` WHERE id = :i", ['i' => $id]);
        if (!$c) { return ['ok' => false, 'message' => 'The certificate no longer exists']; }
        $dir = self::directoryUrl();
        $db->update('certificates', ['state' => 'issuing', 'last_attempt_at' => gmdate('Y-m-d H:i:s', $now)], ['id' => $id]);
        $handles = []; $provider = null;
        try {
            $names = (array)json_decode((string)$c['names'], true);
            $provider = self::provider((int)$c['dns_provider_id']);
            $acme = self::acmeFor($dir);
            $key = Acme::newCertificateKey(); $csr = Acme::csr($names, $key);
            $deadline = microtime(true) + self::BUDGET_SECONDS;
            $order = $acme->newOrder($names);
            $pending = [];
            foreach ($order['authz'] as $url) {
                Jobs::touch();
                $a = $acme->authorization($url);
                if ($a['status'] === 'valid') { continue; }
                if ($a['token'] === null) { throw new AcmeException('The certificate service offered no DNS validation for ' . $a['domain']); }
                $pending[] = $a;
            }
            foreach ($pending as $a) { $handles[] = $provider->addTxt($a['txt_name'], (string)$a['txt_value']); Jobs::touch(); }
            if ($pending) { self::pause(max(0, (int)Config::get('acme.dns_wait_seconds'))); }
            foreach ($pending as $a) { $acme->respond((string)$a['challenge']); }
            foreach ($pending as $a) { Jobs::touch(); $acme->waitStatus($a['url'], ['valid'], $deadline); }
            $chain = $acme->certificate($acme->finalize($order, $csr, $deadline));
        } catch (\Throwable $e) {
            self::cleanup($provider, $handles);
            return self::failed($c, $e, $now);
        }
        self::cleanup($provider, $handles);
        $info = Acme::describe($chain);
        if ($info === null || !Acme::keyMatches($chain, $key)) { return self::failed($c, new AcmeException('The certificate that came back does not match the key or cannot be read'), $now); }
        $missing = array_diff(array_map('strtolower', $names), array_map('strtolower', $info['names']));
        if ($missing) { return self::failed($c, new AcmeException('The certificate does not cover: ' . implode(', ', array_slice($missing, 0, 5))), $now); }
        $db->update('certificates', ['state' => 'issued', 'directory' => $dir, 'key_enc' => Crypto::encrypt($key, 'cert:' . $id), 'chain_pem' => $chain, 'not_before' => $info['not_before'], 'not_after' => $info['not_after'],
            'fingerprint' => $info['fingerprint'], 'last_error' => null, 'attempts' => 0, 'next_attempt_at' => null, 'issued_at' => gmdate('Y-m-d H:i:s', $now)], ['id' => $id]);
        $staging = str_contains($dir, 'staging');
        Alerts::notify('cert_issued', 'Certificate "' . $c['name'] . '" ' . ($c['issued_at'] === null ? 'was issued' : 'was renewed') . ($staging ? ' (a TEST certificate: browsers do not trust it)' : '') . '. Valid until ' . substr($info['not_after'], 0, 10) . ' UTC. Servers that fetch it will pick it up.', 'info', 'certi' . $id);
        return ['ok' => true, 'message' => 'Issued; valid until ' . substr($info['not_after'], 0, 10)];
    }

    private static function acmeFor(string $dir): Acme
    {
        $db = Database::getInstance();
        $h = hash('sha256', $dir);
        $row = $db->fetchOne('SELECT key_enc, kid FROM `' . $db->t('acme_accounts') . '` WHERE directory_hash = :h', ['h' => $h]);
        if ($row) { return new Acme($dir, Crypto::decrypt((string)$row['key_enc'], 'acme:' . $h), (string)$row['kid']); }
        $key = Acme::newAccountKey();
        $acme = new Acme($dir, $key);
        $email = (string)Config::get('acme.email');
        $kid = $acme->ensureAccount($email);
        $db->query('INSERT INTO `' . $db->t('acme_accounts') . '` (directory_hash, directory, email, key_enc, kid) VALUES (:h, :d, :e, :k, :kid) ON DUPLICATE KEY UPDATE kid = VALUES(kid)',
            ['h' => $h, 'd' => $dir, 'e' => $email, 'k' => Crypto::encrypt($key, 'acme:' . $h), 'kid' => $kid]);
        return $acme;
    }

    private static function cleanup(?DnsProvider $p, array $handles): void
    {
        foreach ($handles as $h) {
            try { $p?->removeTxt($h); } catch (\Throwable $e) { Logger::warn('dns_cleanup_failed', ['error' => get_class($e)]); }
        }
    }

    private static function pause(int $seconds): void
    {
        for ($i = 0; $i < $seconds; $i++) { (self::$sleeper ?? static fn (int $ms) => usleep($ms * 1000))(1000); if ($i % 5 === 4) { Jobs::touch(); } }
    }

    /** Record why it failed, plan the next try and tell the owner. A still-valid certificate keeps being served. @return array{ok:bool,message:string} */
    private static function failed(array $c, \Throwable $e, int $now): array
    {
        $db = Database::getInstance();
        $msg = ($e instanceof AcmeException || $e instanceof DnsProviderException) ? $e->getMessage() : 'Unexpected error (see the log)';
        if (!($e instanceof AcmeException || $e instanceof DnsProviderException)) { Logger::warn('cert_issue_failed', ['certificate' => (int)$c['id'], 'error' => get_class($e)]); }
        $attempts = (int)$c['attempts'] + 1;
        $limited = $e instanceof AcmeException && str_contains($e->type, 'rateLimited');
        $pause = $limited ? 6 * 3600 : min(86400, 1800 * (2 ** min($attempts - 1, 5)));
        $stillValid = $c['not_after'] !== null && strtotime($c['not_after'] . ' UTC') > $now;
        $db->update('certificates', ['state' => $stillValid ? 'issued' : 'failed', 'last_error' => mb_substr($msg, 0, 500), 'attempts' => $attempts, 'next_attempt_at' => gmdate('Y-m-d H:i:s', $now + $pause)], ['id' => (int)$c['id']]);
        Alerts::notify('cert_issue_failed', 'Certificate "' . $c['name'] . '" could not be ' . ($c['issued_at'] === null ? 'issued' : 'renewed') . ': ' . $msg . ' Sitewright will try again in ' . ($pause >= 3600 ? round($pause / 3600) . ' h' : round($pause / 60) . ' min') . '.'
            . ($stillValid ? ' The current certificate is still valid until ' . substr((string)$c['not_after'], 0, 10) . ' UTC.' : ''), $stillValid ? 'warning' : 'error', 'certf' . (int)$c['id']);
        return ['ok' => false, 'message' => $msg];
    }

    /** Worker tick: queue what is due (new, retrying, near expiry, made with another service, or stuck). @return int jobs queued */
    public static function scheduleDue(?int $now = null): int
    {
        $now ??= time();
        $db = Database::getInstance();
        $dir = self::directoryUrl();
        $days = max(7, (int)Config::get('acme.renew_days')) * 86400;
        $nowSql = gmdate('Y-m-d H:i:s', $now);
        $n = 0;
        foreach ($db->fetchAll('SELECT id, state, not_before, not_after, directory, next_attempt_at, last_attempt_at FROM `' . $db->t('certificates') . '`') as $c) {
            $retryOk = $c['next_attempt_at'] === null || $c['next_attempt_at'] <= $nowSql;
            // renew when little time is left: the configured days, but never more than a third of this certificate's own lifetime (a short-lived certificate must not be "due" the moment it is issued)
            $life = $c['not_before'] !== null && $c['not_after'] !== null ? strtotime($c['not_after'] . ' UTC') - strtotime($c['not_before'] . ' UTC') : 0;
            $window = $life > 0 ? min($days, intdiv($life, 3)) : $days;
            $due = match ($c['state']) {
                'new', 'failed' => $retryOk,
                'issued' => $retryOk && ($c['not_after'] === null || strtotime($c['not_after'] . ' UTC') - $now <= $window || $c['directory'] !== $dir),
                'issuing' => $c['last_attempt_at'] !== null && $c['last_attempt_at'] < gmdate('Y-m-d H:i:s', $now - 1800),
                default => false,
            };
            if ($due && self::queueUnlessOpen((int)$c['id'])) { $n++; }
        }
        return $n;
    }

    // ------------------------------------------------------------------ servers that fetch certificates

    /** @return array{id:int,token:string} the token is shown once and only its hash is kept @throws ResponseException */
    public static function createTarget(int $certificateId, string $name, string $cidrs = ''): array
    {
        $db = Database::getInstance();
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,99}$/D', $name)) { Validator::fail('name', 'use letters, numbers, spaces, dots, dashes and underscores (up to 100)'); }
        if (!$db->fetchOne('SELECT 1 x FROM `' . $db->t('certificates') . '` WHERE id = :i', ['i' => $certificateId])) { Validator::fail('certificate_id', 'unknown certificate'); }
        $list = [];
        foreach (array_filter(array_map('trim', explode(',', $cidrs))) as $c) {
            if (!preg_match('#^[0-9a-fA-F:.]+(/\d{1,3})?$#D', $c) || @inet_pton(explode('/', $c)[0]) === false) { Validator::fail('allow_cidrs', mb_substr($c, 0, 50) . ' is not an address or range'); }
            $list[] = $c;
        }
        $token = 'swc_' . bin2hex(random_bytes(24));
        $id = (int)$db->insert('cert_targets', ['certificate_id' => $certificateId, 'name' => $name, 'token_hash' => hash('sha256', $token), 'allow_cidrs' => implode(',', $list)]);
        return ['id' => $id, 'token' => $token];
    }

    /** A server's request: right token for this target, and from an allowed address. Records the pull. @return ?array the target row, null when refused */
    public static function authTarget(int $id, ?string $authorization, string $ip): ?array
    {
        $db = Database::getInstance();
        $t = $db->fetchOne('SELECT * FROM `' . $db->t('cert_targets') . '` WHERE id = :i', ['i' => $id]);
        $token = $authorization !== null && preg_match('/^Bearer (swc_[a-f0-9]{48})$/D', $authorization, $m) ? $m[1] : '';
        // always hash and compare, so a wrong id and a wrong token take the same time
        if (!hash_equals($t['token_hash'] ?? str_repeat('0', 64), hash('sha256', $token)) || $t === null) { return null; }
        if ($t['allow_cidrs'] !== '') {
            $bin = @inet_pton($ip); $ok = false;
            foreach (explode(',', (string)$t['allow_cidrs']) as $c) { if ($bin !== false && SafeHttpClient::inCidr($bin, $c)) { $ok = true; break; } }
            if (!$ok) { return null; }
        }
        $db->update('cert_targets', ['last_pull_at' => gmdate('Y-m-d H:i:s'), 'last_pull_ip' => mb_substr($ip, 0, 45)], ['id' => $id]);
        return $t;
    }

    /** @return ?array{fingerprint:string,chain:string,key:string} what a server may fetch: only an issued, unexpired certificate */
    public static function bundle(int $certificateId): ?array
    {
        $db = Database::getInstance();
        $c = $db->fetchOne('SELECT id, state, chain_pem, key_enc, fingerprint, not_after FROM `' . $db->t('certificates') . '` WHERE id = :i', ['i' => $certificateId]);
        if (!$c || $c['chain_pem'] === null || $c['key_enc'] === null || $c['fingerprint'] === null) { return null; }
        return ['fingerprint' => (string)$c['fingerprint'], 'chain' => (string)$c['chain_pem'], 'key' => Crypto::decrypt((string)$c['key_enc'], 'cert:' . (int)$c['id'])];
    }

    /** The server says what it installed. */
    public static function reportInstalled(int $targetId, string $fingerprint, string $status, string $error): void
    {
        $db = Database::getInstance();
        $ok = $status === 'ok' && preg_match('/^[a-f0-9]{64}$/D', $fingerprint);
        $db->update('cert_targets', ['installed_fingerprint' => preg_match('/^[a-f0-9]{64}$/D', $fingerprint) ? $fingerprint : null, 'installed_at' => gmdate('Y-m-d H:i:s'), 'install_status' => $ok ? 'ok' : 'failed',
            'install_error' => $ok ? null : mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $error) ?? ''), 0, 250)], ['id' => $targetId]);
    }

    /** Worker tick: report servers that have not installed the newest certificate days after it was issued. Once per certificate version. @return int reported */
    public static function checkTargets(?int $now = null): int
    {
        $now ??= time();
        $db = Database::getInstance();
        $cut = gmdate('Y-m-d H:i:s', $now - self::STALE_DAYS * 86400); $n = 0;
        foreach ($db->fetchAll('SELECT t.id, t.name tname, t.installed_fingerprint, t.stale_alerted, c.name cname, c.fingerprint FROM `' . $db->t('cert_targets') . '` t JOIN `' . $db->t('certificates') . "` c ON c.id = t.certificate_id
            WHERE c.state IN ('issued') AND c.fingerprint IS NOT NULL AND c.issued_at < :cut AND (t.installed_fingerprint IS NULL OR t.installed_fingerprint <> c.fingerprint) AND (t.stale_alerted IS NULL OR t.stale_alerted <> c.fingerprint)", ['cut' => $cut]) as $r) {
            $db->update('cert_targets', ['stale_alerted' => $r['fingerprint']], ['id' => (int)$r['id']]);
            Alerts::notify('cert_target_stale', 'The server "' . $r['tname'] . '" has not installed the newest "' . $r['cname'] . '" certificate ' . self::STALE_DAYS . ' days after it was issued. Check that its sw-cert-pull timer runs (or that the server is on).', 'warning', 'certt' . (int)$r['id']);
            $n++;
        }
        return $n;
    }
}
