<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * When a site's TLS certificate and domain registration expire, and the warnings about it.
 * Certificate: Sitewright connects to the site's own host on port 443 (through the same address guard as every other fetch: the connection goes to
 * an address that was checked, not to whatever DNS says a moment later) and reads the certificate. If it is not trusted or is for another name, it
 * is read anyway and flagged. Domain: the public RDAP service of the domain's registry (the list of registries comes from IANA). Both are looked
 * up for hosts the owner entered, nothing else. A failed check is recorded, never thrown. Warnings fire when a threshold is crossed, once each.
 * A site behind a CDN such as Cloudflare shows the CDN's certificate, which renews itself.
 */
final class SiteExpiry
{
    /** Days left at which a warning goes out (once each); an expired certificate or domain is the last step. */
    public const THRESHOLDS = [30, 14, 7, 3, 1];
    public const KINDS = ['cert' => 'Certificate (HTTPS)', 'domain' => 'Domain registration'];
    private const BOOTSTRAP = 'https://data.iana.org/rdap/dns.json';

    /** Test seam: fn(string $host, array $ips): array{expires?:int,issuer?:string,names?:string,trusted?:bool,problem?:?string,error?:string}. */
    public static $certReader = null;
    /** Test seam: the TLS port (always 443 in real use). */
    public static int $port = 443;

    // ------------------------------------------------------------------ the checks

    /** Read the site's certificate and domain now. Never throws. @return array{cert:string,domain:string} state of each */
    public static function check(int $siteId, ?int $now = null): array
    {
        $now ??= time();
        $db = Database::getInstance();
        $site = $db->fetchOne('SELECT id, name, base_url FROM `' . $db->t('sites') . '` WHERE id = :i', ['i' => $siteId]);
        if (!$site) { return ['cert' => 'error', 'domain' => 'error']; }
        $host = strtolower((string)parse_url((string)$site['base_url'], PHP_URL_HOST));
        $https = strtolower((string)parse_url((string)$site['base_url'], PHP_URL_SCHEME)) === 'https';
        $out = ['cert' => 'skipped', 'domain' => 'skipped'];
        try {
            if ($https) { $out['cert'] = self::checkCert($site, $host, $now); }
            else { $db->delete('site_expiry', ['site_id' => $siteId, 'kind' => 'cert']); }
        } catch (\Throwable $e) {
            Logger::warn('site_expiry_failed', ['site' => $siteId, 'kind' => 'cert', 'error' => get_class($e)]);
            $out['cert'] = 'error';
        }
        try {
            if ((bool)Config::get('sites.rdap_enabled') && $host !== '' && !filter_var($host, FILTER_VALIDATE_IP)) { $out['domain'] = self::checkDomain($site, $host, $now); }
            else { $db->delete('site_expiry', ['site_id' => $siteId, 'kind' => 'domain']); }
        } catch (\Throwable $e) {
            Logger::warn('site_expiry_failed', ['site' => $siteId, 'kind' => 'domain', 'error' => get_class($e)]);
            $out['domain'] = 'error';
        }
        $db->update('sites', ['expiry_at' => gmdate('Y-m-d H:i:s', $now)], ['id' => $siteId]);
        return $out;
    }

    private static function checkCert(array $site, string $host, int $now): string
    {
        try {
            $ips = SafeHttpClient::default()->validate('https://' . $host . '/')['ips'];
            $c = (self::$certReader ?? [self::class, 'readCert'])($host, $ips);
        } catch (FetchException $e) {   // includes a blocked address and a name that does not resolve
            return self::record((int)$site['id'], 'cert', 'error', null, null, mb_substr($e->getMessage(), 0, 250), $now, $site, $host);
        }
        if (isset($c['error'])) { return self::record((int)$site['id'], 'cert', 'error', null, null, mb_substr((string)$c['error'], 0, 250), $now, $site, $host); }
        $detail = 'Issued by ' . (SiteProfile::clean($c['issuer'] ?? '', 80) ?? 'an unknown issuer');
        if (!empty($c['names'])) { $detail .= '; covers ' . (SiteProfile::clean($c['names'], 120) ?? ''); }
        if (!empty($c['problem'])) { $detail .= '. ' . $c['problem']; }
        return self::record((int)$site['id'], 'cert', ($c['trusted'] ?? false) ? 'ok' : 'problem', (int)$c['expires'], mb_substr($detail, 0, 300), null, $now, $site, $host);
    }

    /** The default certificate reader. @return array<string,mixed> */
    public static function readCert(string $host, array $ips): array
    {
        $last = 'Could not complete a secure connection';
        foreach (array_slice($ips, 0, 2) as $ip) {
            foreach ([true, false] as $verify) {   // first the way a browser would; if that fails, read it anyway and say why it is not trusted
                $ctx = stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true, 'capture_peer_cert' => true, 'verify_peer' => $verify, 'verify_peer_name' => $verify]]);
                $s = @stream_socket_client('ssl://' . (str_contains($ip, ':') ? "[$ip]" : $ip) . ':' . self::$port, $errno, $err, 8, STREAM_CLIENT_CONNECT, $ctx);
                if ($s === false) { $last = 'Could not complete a secure connection' . ($err !== '' ? ': ' . mb_substr(preg_replace('/\s+/', ' ', (string)$err) ?? '', 0, 120) : ''); continue; }
                $params = stream_context_get_params($s);
                fclose($s);
                $x = isset($params['options']['ssl']['peer_certificate']) ? @openssl_x509_parse($params['options']['ssl']['peer_certificate']) : false;
                if (!is_array($x) || empty($x['validTo_time_t'])) { $last = 'The site did not present a readable certificate'; continue; }
                $san = (string)($x['extensions']['subjectAltName'] ?? '');
                $names = trim(preg_replace('/DNS:/', '', $san) ?? '') ?: (string)($x['subject']['CN'] ?? '');
                return ['expires' => (int)$x['validTo_time_t'], 'issuer' => (string)($x['issuer']['O'] ?? $x['issuer']['CN'] ?? ''), 'names' => $names, 'trusted' => $verify,
                    'problem' => $verify ? null : 'Browsers will not trust it: it is self-signed, not complete, or made for a different name.'];
            }
        }
        return ['error' => $last];
    }

    private static function checkDomain(array $site, string $host, int $now): string
    {
        $r = self::rdapExpiry($host);
        if ($r['error'] !== null) {   // the public registry could not say (no RDAP for .co, for example): ask a registrar the owner connected
            $reg = self::registrarExpiry($host);
            if ($reg !== null) { return self::record((int)$site['id'], 'domain', 'ok', $reg['expires'], 'Registered as ' . $reg['domain'] . ' at ' . $reg['registrar'] . ' (from your ' . $reg['registrar'] . ' account)', null, $now, $site, $host); }
        }
        return self::record((int)$site['id'], 'domain', $r['error'] === null ? 'ok' : 'error', $r['expires'], $r['domain'] !== null ? 'Registered as ' . $r['domain'] : null, $r['error'], $now, $site, $host);
    }

    /** The first connected DNS/registrar account that registers this domain. Never throws. @return ?array{expires:int,domain:string,registrar:string} */
    public static function registrarExpiry(string $host): ?array
    {
        $db = Database::getInstance();
        foreach ($db->fetchAll('SELECT id FROM `' . $db->t('dns_providers') . '` ORDER BY id') as $row) {
            try { $r = Certificates::provider((int)$row['id'])->registration($host); } catch (\Throwable) { continue; }
            if ($r !== null) { return $r; }
        }
        return null;
    }

    /**
     * Ask the registry that holds the domain. Tries the host, then shorter names, until one is registered (www.shop.example.co.uk -> shop.example.co.uk -> example.co.uk).
     * @return array{expires:?int,domain:?string,error:?string}
     */
    public static function rdapExpiry(string $host): array
    {
        $labels = explode('.', $host);
        if (count($labels) < 2) { return ['expires' => null, 'domain' => null, 'error' => 'Not a domain name']; }
        $tld = end($labels);
        if (!preg_match('/^[a-z0-9-]{1,63}$/', $tld)) { return ['expires' => null, 'domain' => null, 'error' => 'Not a domain name']; }
        $base = self::registryFor($tld);
        if ($base === null) { return ['expires' => null, 'domain' => null, 'error' => 'Could not get the list of registries from IANA']; }
        if ($base === '-') { return ['expires' => null, 'domain' => null, 'error' => 'The registry for .' . $tld . ' does not offer a public RDAP service']; }
        for ($i = 0; $i < min(4, count($labels) - 1); $i++) {
            $name = implode('.', array_slice($labels, $i));
            try {
                $res = SafeHttpClient::default()->get($base . 'domain/' . rawurlencode($name), ['max_bytes' => 524288, 'timeout' => 10, 'accept' => 'application/rdap+json,application/json']);
            } catch (FetchException $e) {
                return ['expires' => null, 'domain' => $name, 'error' => 'The registry could not be reached: ' . mb_substr($e->getMessage(), 0, 120)];
            }
            if ($res['status'] === 404) { continue; }
            if ($res['status'] !== 200) { return ['expires' => null, 'domain' => $name, 'error' => 'The registry answered HTTP ' . (int)$res['status']]; }
            $j = json_decode($res['body'], true);
            foreach (is_array($j) && is_array($j['events'] ?? null) ? $j['events'] : [] as $ev) {
                if (is_array($ev) && ($ev['eventAction'] ?? '') === 'expiration' && is_string($ev['eventDate'] ?? null) && ($t = strtotime($ev['eventDate'])) !== false && $t > 946684800) {
                    return ['expires' => $t, 'domain' => SiteProfile::clean($name, 100), 'error' => null];
                }
            }
            return ['expires' => null, 'domain' => $name, 'error' => 'The registry does not publish an expiry date for ' . $name];
        }
        return ['expires' => null, 'domain' => null, 'error' => 'No registered domain found for ' . $host];
    }

    /**
     * The RDAP address of the registry for one domain ending (for example "co"): from IANA's public list, kept per ending for a week (the whole list does not
     * fit the small key-value table and nobody needs 1,400 endings). "-" is kept for an ending the list does not have. When IANA cannot be reached an older
     * answer is used if there is one. @return ?string base address ending in "/", "-" when the ending has none, null when unknown and IANA is down
     */
    private static function registryFor(string $tld): ?string
    {
        $db = Database::getInstance();
        $kv = $db->t('kv');
        $key = 'rdap_' . $tld;
        $row = $db->fetchOne("SELECT v, updated_at FROM `$kv` WHERE k = :k", ['k' => $key]);
        if ($row && strtotime((string)$row['updated_at'] . ' UTC') > time() - 7 * 86400) { return (string)$row['v']; }
        $found = null;
        try {
            $res = SafeHttpClient::default()->get(self::BOOTSTRAP, ['max_bytes' => 2097152, 'timeout' => 10, 'accept' => 'application/json']);
            $j = $res['status'] === 200 ? json_decode($res['body'], true) : null;
            if (!is_array($j) || !is_array($j['services'] ?? null)) { return $row ? (string)$row['v'] : null; }
            $found = '-';
            foreach ($j['services'] as $svc) {
                $tlds = is_array($svc) && is_array($svc[0] ?? null) ? $svc[0] : []; $urls = is_array($svc) && is_array($svc[1] ?? null) ? $svc[1] : [];
                $https = array_values(array_filter($urls, static fn ($u) => is_string($u) && str_starts_with($u, 'https://') && strlen($u) <= 200 && !preg_match('/[\x00-\x20\x7F]/', $u)));
                if ($https && in_array($tld, $tlds, true)) { $found = rtrim($https[0], '/') . '/'; break; }
            }
        } catch (FetchException) {
            return $row ? (string)$row['v'] : null;   // IANA is down: an old answer is better than none
        }
        $db->query("INSERT INTO `$kv` (k, v) VALUES (:k, :v) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = NOW()", ['k' => $key, 'v' => $found]);
        return $found;
    }

    // ------------------------------------------------------------------ storing and warning

    private static function record(int $siteId, string $kind, string $state, ?int $expires, ?string $detail, ?string $error, int $now, array $site, string $host): string
    {
        $db = Database::getInstance();
        $t = $db->t('site_expiry');
        $old = $db->fetchOne("SELECT state, expires_at, alerted FROM `$t` WHERE site_id = :s AND kind = :k", ['s' => $siteId, 'k' => $kind]);
        $alerted = $old && $old['alerted'] !== null ? (int)$old['alerted'] : null;
        $exp = $expires !== null ? gmdate('Y-m-d H:i:s', $expires) : null;
        if ($exp === null && $old) { $exp = $old['expires_at']; }      // a failed check keeps what we knew
        $expTs = $exp !== null ? strtotime($exp . ' UTC') : null;

        $days = $expTs !== null ? (int)floor(($expTs - $now) / 86400) : null;
        if ($state !== 'error' && $days !== null) {
            $step = null;
            if ($days <= 0) { $step = 0; }
            else { foreach (self::THRESHOLDS as $th) { if ($days <= $th) { $step = $th; } } }   // THRESHOLDS descend, so this ends on the tightest one reached
            if ($step === null) { $alerted = null; }                                   // renewed (or never close): the next approach warns again
            elseif ($alerted === null || $step < $alerted) {
                self::warn($kind, $site, $host, $days, (int)$expTs, $step);
                $alerted = $step;
            }
        }
        if ($kind === 'cert' && $state === 'problem' && (!$old || $old['state'] !== 'problem')) {
            Alerts::notify('cert_problem', 'The certificate for ' . $host . ' has a problem. ' . (string)$detail, 'error', 'certp' . $siteId, $siteId);
        }
        $db->query("INSERT INTO `$t` (site_id, kind, state, expires_at, detail, error, checked_at, alerted) VALUES (:s, :k, :st, :e, :d, :er, :c, :a)
            ON DUPLICATE KEY UPDATE state = VALUES(state), expires_at = VALUES(expires_at), detail = VALUES(detail), error = VALUES(error), checked_at = VALUES(checked_at), alerted = VALUES(alerted)",
            ['s' => $siteId, 'k' => $kind, 'st' => $state, 'e' => $exp, 'd' => $detail, 'er' => $error, 'c' => gmdate('Y-m-d H:i:s', $now), 'a' => $alerted]);
        return $state;
    }

    private static function warn(string $kind, array $site, string $host, int $days, int $expTs, int $step): void
    {
        $what = $kind === 'cert' ? 'The HTTPS certificate for ' : 'The domain registration for ';
        $when = gmdate('Y-m-d', $expTs) . ' UTC';
        $msg = $step === 0 ? $what . $host . ' has expired (' . $when . ').' : $what . $host . ' expires in ' . max(0, $days) . ' day' . ($days === 1 ? '' : 's') . ' (' . $when . ').';
        if ($kind === 'cert') { $msg .= ' If the site is behind a CDN such as Cloudflare, check the certificate on the origin server too.'; }
        Alerts::notify($kind === 'cert' ? 'cert_expiring' : 'domain_expiring', $msg, $step <= 7 ? 'error' : 'warning', $kind . (int)$site['id'] . '-' . $step, (int)$site['id']);
    }

    // ------------------------------------------------------------------ for the page and the worker

    /** @return list<array<string,mixed>> */
    public static function view(int $siteId, ?int $now = null): array
    {
        $now ??= time();
        $db = Database::getInstance();
        $rows = array_column($db->fetchAll('SELECT kind, state, expires_at, detail, error, checked_at FROM `' . $db->t('site_expiry') . '` WHERE site_id = :s', ['s' => $siteId]), null, 'kind');
        $out = [];
        foreach (self::KINDS as $kind => $label) {
            $r = $rows[$kind] ?? null;
            $days = $r && $r['expires_at'] !== null ? (int)floor((strtotime($r['expires_at'] . ' UTC') - $now) / 86400) : null;
            $out[] = ['kind' => $kind, 'label' => $label, 'state' => $r['state'] ?? 'unknown', 'expires_at' => $r['expires_at'] ?? null, 'days_left' => $days,
                'detail' => $r['detail'] ?? null, 'error' => $r['error'] ?? null, 'checked_at' => $r['checked_at'] ?? null];
        }
        return $out;
    }

    public static function handleJob(array $p): array
    {
        $r = self::check((int)($p['site_id'] ?? 0));
        return ['message' => 'Certificate: ' . $r['cert'] . '; domain: ' . $r['domain'], 'data' => $r];
    }

    public static function queueUnlessOpen(int $siteId): bool
    {
        $db = Database::getInstance();
        if ($db->fetchOne('SELECT 1 x FROM `' . $db->t('jobs') . "` WHERE type = 'site_expiry' AND status IN ('queued','running') AND payload = :p", ['p' => (string)json_encode(['site_id' => $siteId])])) { return false; }
        Jobs::enqueue('site_expiry', ['site_id' => $siteId], null, 0, 1);
        return true;
    }

    /** Worker tick: queue a check for sites never checked or last checked more than sites.expiry_hours ago. */
    public static function scheduleDue(): int
    {
        $hours = max(1, (int)Config::get('sites.expiry_hours'));
        $db = Database::getInstance();
        $n = 0;
        foreach ($db->fetchAll('SELECT id FROM `' . $db->t('sites') . '` WHERE expiry_at IS NULL OR expiry_at < :c', ['c' => gmdate('Y-m-d H:i:s', time() - $hours * 3600)]) as $s) {
            if (self::queueUnlessOpen((int)$s['id'])) { $n++; }
        }
        return $n;
    }
}
