<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * An ACME client (RFC 8555) for Let's Encrypt and compatible services, with DNS-01 validation only. No Composer dependency: signing and CSRs use
 * PHP's openssl, requests go through SafeHttpClient (so the address guard applies to the ACME server too).
 *
 * Steps for a certificate: ensureAccount() once; newOrder(names); for each authorization: authorization() tells which TXT record to publish;
 * once it is published respond() and waitStatus(); then finalize() with a CSR from csr(), waitStatus() on the order, and certificate().
 * The account key is RSA 2048 (RS256); certificate keys are ECDSA P-256. Every ACME answer is untrusted: it is parsed, never evaluated.
 */
final class Acme
{
    private const JOSE = 'Content-Type: application/jose+json';
    /** Test seam: fn(int $milliseconds). */
    public static $sleeper = null;

    private ?array $dir = null;
    private ?string $nonce = null;
    private ?array $jwk = null;
    private string $thumbprint = '';

    public function __construct(private string $directoryUrl, private string $accountKeyPem, private ?string $kid = null, private ?SafeHttpClient $http = null)
    {
        $key = openssl_pkey_get_private($accountKeyPem);
        $d = $key ? openssl_pkey_get_details($key) : false;
        if (!$d || ($d['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) { throw new AcmeException('The ACME account key is not a usable RSA key'); }
        $this->jwk = ['e' => self::b64($d['rsa']['e']), 'kty' => 'RSA', 'n' => self::b64($d['rsa']['n'])];   // keys in this order: also the thumbprint form
        $this->thumbprint = self::b64(hash('sha256', (string)json_encode($this->jwk, JSON_UNESCAPED_SLASHES), true));
    }

    // ------------------------------------------------------------------ keys and encodings (pure)

    public static function b64(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }

    public static function newAccountKey(): string
    {
        $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        if (!$k || !openssl_pkey_export($k, $pem)) { throw new AcmeException('Could not create an account key'); }
        return $pem;
    }

    public static function newCertificateKey(): string
    {
        $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if (!$k || !openssl_pkey_export($k, $pem)) { throw new AcmeException('Could not create a certificate key'); }
        return $pem;
    }

    /** What the TXT record for a challenge token must contain. */
    public function txtValue(string $token): string
    {
        return self::b64(hash('sha256', $token . '.' . $this->thumbprint, true));
    }

    /** Where the TXT record goes: "_acme-challenge." plus the name without a leading "*.". */
    public static function txtName(string $domain): string
    {
        return '_acme-challenge.' . (str_starts_with($domain, '*.') ? substr($domain, 2) : $domain);
    }

    /** A host name or wildcard in lower case, or null. Used before any name reaches a CSR or a DNS API. */
    public static function cleanName(string $name): ?string
    {
        $n = strtolower(trim($name));
        if (strlen($n) > 253 || !preg_match('/^(\*\.)?([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $n)) { return null; }
        return $n;
    }

    /** DER bytes of a CSR for these names, signed with the certificate key. @throws AcmeException */
    public static function csr(array $names, string $certKeyPem): string
    {
        $names = array_values(array_unique(array_map(static fn ($n) => self::cleanName((string)$n) ?? throw new AcmeException('Not a valid domain name: ' . mb_substr((string)$n, 0, 60)), $names)));
        if (!$names) { throw new AcmeException('No domain names'); }
        $cfg = tempnam(sys_get_temp_dir(), 'swcsr');
        try {
            file_put_contents($cfg, "[req]\ndistinguished_name = dn\nreq_extensions = v3_req\n[dn]\n[v3_req]\nsubjectAltName = " . implode(',', array_map(static fn ($n) => 'DNS:' . $n, $names)) . "\n");
            $key = openssl_pkey_get_private($certKeyPem);
            $cn = current(array_filter($names, static fn ($n) => $n[0] !== '*')) ?: $names[0];
            $csr = $key ? openssl_csr_new(['commonName' => $cn], $key, ['digest_alg' => 'sha256', 'config' => $cfg, 'req_extensions' => 'v3_req']) : false;
            if (!$csr || !openssl_csr_export($csr, $pem)) { throw new AcmeException('Could not create the certificate request'); }
        } finally { @unlink($cfg); }
        $der = base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem) ?? '', true);
        if ($der === false || $der === '') { throw new AcmeException('Could not create the certificate request'); }
        return $der;
    }

    /** @return array{not_before:string,not_after:string,serial:string,fingerprint:string,names:list<string>}|null facts about the first (leaf) certificate of a PEM chain; null if it cannot be read */
    public static function describe(string $chainPem): ?array
    {
        if (!preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $chainPem, $m)) { return null; }
        $x = @openssl_x509_parse($m[0]);
        if (!is_array($x) || empty($x['validTo_time_t'])) { return null; }
        $names = [];
        foreach (explode(',', (string)($x['extensions']['subjectAltName'] ?? '')) as $s) { if (str_starts_with(trim($s), 'DNS:')) { $names[] = substr(trim($s), 4); } }
        return ['not_before' => gmdate('Y-m-d H:i:s', (int)$x['validFrom_time_t']), 'not_after' => gmdate('Y-m-d H:i:s', (int)$x['validTo_time_t']), 'serial' => (string)($x['serialNumberHex'] ?? ''),
            'fingerprint' => (string)openssl_x509_fingerprint($m[0], 'sha256'), 'names' => $names];
    }

    /** Does this private key belong to the leaf certificate of the chain? */
    public static function keyMatches(string $chainPem, string $keyPem): bool
    {
        if (!preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $chainPem, $m)) { return false; }
        $cert = @openssl_x509_read($m[0]); $key = @openssl_pkey_get_private($keyPem);
        return $cert && $key && @openssl_x509_check_private_key($cert, $key);
    }

    // ------------------------------------------------------------------ protocol

    public function directory(): array
    {
        if ($this->dir === null) {
            $r = $this->client()->get($this->directoryUrl, ['accept' => 'application/json', 'max_bytes' => 65536, 'timeout' => 15]);
            $j = $r['status'] === 200 ? json_decode($r['body'], true) : null;
            foreach (['newNonce', 'newAccount', 'newOrder'] as $k) { if (!is_array($j) || !is_string($j[$k] ?? null)) { throw new AcmeException('The ACME service did not answer like an ACME directory (HTTP ' . $r['status'] . ')', '', $r['status']); } }
            $this->dir = $j;
        }
        return $this->dir;
    }

    /** Find or create the account for this key. @return string the account address ("kid") */
    public function ensureAccount(string $email): string
    {
        $payload = ['termsOfServiceAgreed' => true];
        if ($email !== '') { $payload['contact'] = ['mailto:' . $email]; }
        $r = $this->post($this->directory()['newAccount'], $payload, true);
        if (!in_array($r['status'], [200, 201], true) || empty($r['headers']['location'])) { throw $this->error($r, 'Could not register the ACME account'); }
        return $this->kid = (string)$r['headers']['location'];
    }

    /** @return array{url:string,finalize:string,authz:list<string>} */
    public function newOrder(array $names): array
    {
        $ids = array_map(static fn ($n) => ['type' => 'dns', 'value' => $n], array_values($names));
        $r = $this->post($this->directory()['newOrder'], ['identifiers' => $ids]);
        $j = $r['json'];
        if ($r['status'] !== 201 || !is_array($j) || !is_string($j['finalize'] ?? null) || !is_array($j['authorizations'] ?? null) || empty($r['headers']['location'])) { throw $this->error($r, 'The ACME service did not accept the order'); }
        return ['url' => (string)$r['headers']['location'], 'finalize' => $j['finalize'], 'authz' => array_values(array_filter($j['authorizations'], 'is_string'))];
    }

    /** @return array{domain:string,wildcard:bool,status:string,url:string,token:?string,challenge:?string,txt_name:?string,txt_value:?string} */
    public function authorization(string $url): array
    {
        $r = $this->post($url, null);
        $j = $r['json'];
        if ($r['status'] !== 200 || !is_array($j) || !is_string($j['identifier']['value'] ?? null)) { throw $this->error($r, 'Could not read an authorization'); }
        $dns = null;
        foreach (is_array($j['challenges'] ?? null) ? $j['challenges'] : [] as $c) { if (is_array($c) && ($c['type'] ?? '') === 'dns-01' && is_string($c['token'] ?? null) && is_string($c['url'] ?? null)) { $dns = $c; } }
        $domain = (string)$j['identifier']['value']; $wild = !empty($j['wildcard']);
        $token = $dns !== null && preg_match('/^[A-Za-z0-9_-]{16,200}$/D', $dns['token']) ? $dns['token'] : null;
        return ['domain' => $wild ? '*.' . $domain : $domain, 'wildcard' => $wild, 'status' => (string)($j['status'] ?? ''), 'url' => $url, 'token' => $token, 'challenge' => $token !== null ? $dns['url'] : null,
            'txt_name' => self::txtName($domain), 'txt_value' => $token !== null ? $this->txtValue($token) : null];
    }

    /** Tell the service the TXT record is published. */
    public function respond(string $challengeUrl): void
    {
        $r = $this->post($challengeUrl, new \stdClass());
        if (!in_array($r['status'], [200, 202], true)) { throw $this->error($r, 'The ACME service did not accept the challenge response'); }
    }

    /** Poll an authorization or order until its status is one of $want; throws when it becomes "invalid" or time runs out. @return array<string,mixed> */
    public function waitStatus(string $url, array $want, float $deadline): array
    {
        while (true) {
            $r = $this->post($url, null);
            $j = is_array($r['json']) ? $r['json'] : [];
            $s = (string)($j['status'] ?? '');
            if (in_array($s, $want, true)) { return $j + ['_url' => $url]; }
            if ($s === 'invalid') {
                $why = '';
                foreach (is_array($j['challenges'] ?? null) ? $j['challenges'] : [] as $c) { if (is_array($c) && is_string($c['error']['detail'] ?? null)) { $why = ': ' . mb_substr($c['error']['detail'], 0, 300); } }
                if ($why === '' && is_string($j['error']['detail'] ?? null)) { $why = ': ' . mb_substr($j['error']['detail'], 0, 300); }
                throw new AcmeException('The ACME service rejected it' . $why, (string)($j['error']['type'] ?? ''));
            }
            if (!in_array($s, ['pending', 'processing', 'ready'], true) && $s !== '') { throw new AcmeException('Unexpected ACME status "' . mb_substr($s, 0, 30) . '"'); }
            if (microtime(true) >= $deadline) { throw new AcmeException('Timed out waiting for the ACME service (status: ' . ($s ?: 'unknown') . ')'); }
            $wait = max(1, min(10, (int)($r['headers']['retry-after'] ?? 2)));
            (self::$sleeper ?? static fn (int $ms) => usleep($ms * 1000))($wait * 1000);
        }
    }

    /** @return string the address the certificate can be downloaded from */
    public function finalize(array $order, string $csrDer, float $deadline): string
    {
        $r = $this->post($order['finalize'], ['csr' => self::b64($csrDer)]);
        if (!in_array($r['status'], [200, 201], true)) { throw $this->error($r, 'The ACME service did not accept the certificate request'); }
        $j = $this->waitStatus($order['url'], ['valid'], $deadline);
        if (!is_string($j['certificate'] ?? null)) { throw new AcmeException('The order is valid but has no certificate address'); }
        return $j['certificate'];
    }

    /** @return string PEM chain (leaf first) */
    public function certificate(string $url): string
    {
        $r = $this->post($url, null, false, 'application/pem-certificate-chain');
        if ($r['status'] !== 200 || !preg_match('/-----BEGIN CERTIFICATE-----/', $r['body'])) { throw $this->error($r, 'Could not download the certificate'); }
        return $r['body'];
    }

    // ------------------------------------------------------------------ transport

    private function client(): SafeHttpClient { return $this->http ?? SafeHttpClient::default(); }

    /** Signed POST (POST-as-GET when $payload is null). Retries on "badNonce" (servers may refuse a good nonce now and then; up to three more attempts). @return array{status:int,headers:array,body:string,json:mixed} */
    private function post(string $url, mixed $payload, bool $useJwk = false, string $accept = 'application/json'): array
    {
        for ($try = 0; ; $try++) {
            $protected = ['alg' => 'RS256', 'nonce' => $this->takeNonce(), 'url' => $url] + ($useJwk || $this->kid === null ? ['jwk' => $this->jwk] : ['kid' => $this->kid]);
            $p64 = self::b64((string)json_encode($protected, JSON_UNESCAPED_SLASHES));
            $d64 = $payload === null ? '' : self::b64((string)json_encode($payload, JSON_UNESCAPED_SLASHES));
            $key = openssl_pkey_get_private($this->accountKeyPem);
            if (!$key || !openssl_sign($p64 . '.' . $d64, $sig, $key, OPENSSL_ALGO_SHA256)) { throw new AcmeException('Could not sign the request'); }
            $res = $this->client()->request('POST', $url, ['headers' => [self::JOSE], 'body' => (string)json_encode(['protected' => $p64, 'payload' => $d64, 'signature' => self::b64($sig)]), 'accept' => $accept, 'follow' => false, 'max_bytes' => 1048576, 'timeout' => 30]);
            $this->nonce = isset($res['headers']['replay-nonce']) ? (string)$res['headers']['replay-nonce'] : null;
            $json = json_decode($res['body'], true);
            if ($try < 3 && $res['status'] === 400 && is_array($json) && ($json['type'] ?? '') === 'urn:ietf:params:acme:error:badNonce') { continue; }
            return ['status' => (int)$res['status'], 'headers' => $res['headers'], 'body' => $res['body'], 'json' => $json];
        }
    }

    private function takeNonce(): string
    {
        if ($this->nonce !== null) { $n = $this->nonce; $this->nonce = null; return $n; }
        $r = $this->client()->request('HEAD', $this->directory()['newNonce'], ['follow' => false, 'timeout' => 15]);
        $n = (string)($r['headers']['replay-nonce'] ?? '');
        if ($n === '') { throw new AcmeException('The ACME service gave no nonce'); }
        return $n;
    }

    private function error(array $r, string $what): AcmeException
    {
        $j = is_array($r['json'] ?? null) ? $r['json'] : [];
        $detail = is_string($j['detail'] ?? null) ? ': ' . mb_substr(preg_replace('/\s+/', ' ', $j['detail']) ?? '', 0, 300) : '';
        return new AcmeException($what . ' (HTTP ' . $r['status'] . ')' . $detail, is_string($j['type'] ?? null) ? mb_substr($j['type'], 0, 100) : '', (int)$r['status']);
    }
}
