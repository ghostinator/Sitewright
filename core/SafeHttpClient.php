<?php
declare(strict_types=1);

namespace RankMathWebapp\Core;

/**
 * The ONLY code in this application allowed to open an outbound HTTP connection.
 *
 * Guarantees (see docs/NETWORK_AND_PRIVACY.md):
 *  - http/https only; no credentials in URLs; ports limited to an allow-list (default 80/443)
 *  - hostname is resolved by us and EVERY address must be public (no loopback, RFC1918, link-local,
 *    metadata, CGNAT, reserved, IPv4-mapped/NAT64/6to4 wrappers of those, ...)
 *  - the connection is pinned to the validated addresses (CURLOPT_RESOLVE) so DNS cannot change after validation
 *  - redirects are followed manually (max 5) and every hop is fully re-validated
 *  - connect/total timeouts, a per-operation deadline, and a hard response size cap enforced while streaming
 *  - TLS verification always on
 *
 * Tests inject a resolver and a transport so no network access is needed.
 */
final class SafeHttpClient
{
    private const V4_BLOCK = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    private static ?self $default = null;

    /** @var callable fn(string $host): string[] */
    private $resolver;
    /** @var callable fn(array $request): array{status:int,headers:array<string,string>,body:string} */
    private $transport;
    private array $cfg;

    public function __construct(?callable $resolver = null, ?callable $transport = null, ?array $cfg = null)
    {
        $this->cfg = $cfg ?? (array)Config::get('fetch');
        $this->resolver = $resolver ?? [self::class, 'systemResolve'];
        $this->transport = $transport ?? [$this, 'curlTransport'];
    }

    public static function default(): self
    {
        return self::$default ??= new self();
    }

    /** Test seam. */
    public static function setDefault(?self $client): void
    {
        self::$default = $client;
    }

    // ------------------------------------------------------------------ public API

    /**
     * @param array{max_bytes?:int,timeout?:float,deadline?:float,headers?:string[],follow?:bool,accept?:string} $opts
     * @return array{final_url:string,status:int,headers:array<string,string>,body:string,bytes:int,elapsed_ms:int,redirects:string[],content_type:string}
     */
    public function get(string $url, array $opts = []): array
    {
        return $this->request('GET', $url, $opts);
    }

    /**
     * @param array{body?:string,max_bytes?:int,timeout?:float,deadline?:float,headers?:string[],follow?:bool,accept?:string} $opts
     */
    public function request(string $method, string $url, array $opts = []): array
    {
        $method = strtoupper($method);
        $started = microtime(true);
        $maxBytes = (int)($opts['max_bytes'] ?? $this->cfg['max_html_bytes']);
        $maxRedirects = (int)$this->cfg['max_redirects'];
        $follow = ($opts['follow'] ?? true) && in_array($method, ['GET', 'HEAD'], true);
        $redirects = [];
        $current = $url;

        for ($hop = 0; ; $hop++) {
            $target = $this->validate($current);

            $timeout = (float)($opts['timeout'] ?? $this->cfg['timeout']);
            if (isset($opts['deadline'])) {
                $timeout = min($timeout, $opts['deadline'] - microtime(true));
                if ($timeout <= 0.05) {
                    throw new FetchTimeoutException('Time budget exceeded');
                }
            }

            $resp = ($this->transport)([
                'method' => $method, 'url' => $current, 'scheme' => $target['scheme'], 'host' => $target['host'],
                'port' => $target['port'], 'ips' => $target['ips'], 'literal' => $target['literal'],
                'headers' => $opts['headers'] ?? [], 'accept' => $opts['accept'] ?? 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5',
                'body' => $opts['body'] ?? null,
                'connect_timeout' => (float)min((float)$this->cfg['connect_timeout'], $timeout),
                'timeout' => $timeout, 'max_bytes' => $maxBytes,
                'user_agent' => $this->userAgent(),
            ]);

            $status = (int)$resp['status'];
            $headers = array_change_key_case((array)$resp['headers'], CASE_LOWER);

            if ($follow && in_array($status, [301, 302, 303, 307, 308], true) && !empty($headers['location'])) {
                if ($hop >= $maxRedirects) {
                    throw new TooManyRedirectsException('Too many redirects');
                }
                $next = Url::resolve($current, (string)$headers['location']);
                if ($next === null) {
                    throw new UrlRejectedException('Invalid redirect target');
                }
                $redirects[] = $current;
                $current = $next;
                continue;
            }

            $body = (string)$resp['body'];
            return [
                'final_url' => $current, 'status' => $status, 'headers' => $headers, 'body' => $body,
                'bytes' => strlen($body), 'elapsed_ms' => (int)round((microtime(true) - $started) * 1000),
                'redirects' => $redirects,
                'content_type' => strtolower(trim(explode(';', (string)($headers['content-type'] ?? ''))[0])),
            ];
        }
    }

    // ------------------------------------------------------------------ validation

    /**
     * Full URL + DNS validation. Throws UrlRejectedException / BlockedAddressException / FetchException(dns).
     * @return array{scheme:string,host:string,port:int,ips:string[],literal:bool}
     */
    public function validate(string $url): array
    {
        if ($url === '' || strlen($url) > 2048) {
            throw new UrlRejectedException('URL is empty or too long');
        }
        if (preg_match('/[\x00-\x20\x7F\\\\]/', $url)) {
            throw new UrlRejectedException('URL contains invalid characters');
        }
        $p = parse_url($url);
        if ($p === false || !isset($p['scheme'], $p['host'])) {
            throw new UrlRejectedException('URL is not valid');
        }
        $scheme = strtolower($p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new UrlRejectedException('Only http and https URLs are allowed');
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw new UrlRejectedException('URLs with credentials are not allowed');
        }
        $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, array_map('intval', (array)$this->cfg['allow_ports']), true)) {
            throw new UrlRejectedException('Port is not allowed');
        }

        $host = strtolower(rtrim($p['host'], '.'));
        $literal = false;
        if ($host !== '' && $host[0] === '[') {
            $ip = substr($host, 1, -1);
            if (substr($host, -1) !== ']' || @inet_pton($ip) === false || !str_contains($ip, ':')) {
                throw new UrlRejectedException('URL host is not valid');
            }
            $ips = [$ip]; $literal = true; $host = $ip;
        } else {
            $host = $this->normaliseHostname($host);
            if (preg_match('/^(?:0x[0-9a-f]*|[0-9]+)(?:\.(?:0x[0-9a-f]*|[0-9]+))*$/i', $host)) {
                // All-numeric host: only strict dotted-quad is accepted. Decimal, octal, hex and short forms
                // (2130706433, 0177.0.0.1, 0x7f.1, 127.1) are interpreted differently by resolvers and curl.
                if (!preg_match('/^(?:(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)$/D', $host)) {
                    throw new UrlRejectedException('Numeric host formats are not allowed');
                }
                $ips = [$host]; $literal = true;
            } else {
                $this->checkHostLists($host);
                $ips = array_values(array_unique(array_filter(array_map('strval', (array)($this->resolver)($host)))));
                if (!$ips) {
                    throw new FetchException('Host could not be resolved');
                }
            }
        }

        $dev = (array)($this->cfg['dev_allow_cidrs'] ?? []);
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip, $dev)) {
                throw new BlockedAddressException('URL resolves to a network address that is not allowed');
            }
        }
        if ($literal) { $this->checkHostLists($host); }

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'ips' => $ips, 'literal' => $literal];
    }

    private function normaliseHostname(string $host): string
    {
        if ($host === '' || strlen($host) > 253) {
            throw new UrlRejectedException('URL host is not valid');
        }
        if (preg_match('/[^\x20-\x7E]/', $host)) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;
            if ($ascii === false || $ascii === '') {
                throw new UrlRejectedException('URL host is not valid');
            }
            $host = strtolower($ascii);
        }
        foreach (explode('.', $host) as $label) {
            if (!preg_match('/^[a-z0-9_]([a-z0-9_\-]{0,61}[a-z0-9_])?$/D', $label)) {
                throw new UrlRejectedException('URL host is not valid');
            }
        }
        return $host;
    }

    private function checkHostLists(string $host): void
    {
        $match = static function (string $host, array $list): bool {
            foreach ($list as $entry) {
                $entry = strtolower(ltrim(trim((string)$entry), '.'));
                if ($entry !== '' && ($host === $entry || str_ends_with($host, '.' . $entry))) { return true; }
            }
            return false;
        };
        if ($match($host, (array)$this->cfg['block_hosts'])) {
            throw new BlockedAddressException('Host is blocked by configuration');
        }
        $allow = (array)$this->cfg['allow_hosts'];
        // An allow-list narrows destinations; it never bypasses the IP checks.
        if ($allow && !$match($host, $allow)) {
            throw new BlockedAddressException('Host is not on the configured allow-list');
        }
    }

    // ------------------------------------------------------------------ address classification

    /** True only for globally routable unicast addresses. Dev CIDRs (default empty) are exempted explicitly. */
    public static function isPublicIp(string $ip, array $devAllow = []): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) { return false; }
        foreach ($devAllow as $cidr) {
            if (self::inCidr($bin, (string)$cidr)) { return true; }
        }
        if (strlen($bin) === 4) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
            foreach (self::V4_BLOCK as $cidr) {
                if (self::inCidr($bin, $cidr)) { return false; }
            }
            return true;
        }

        $embedded = static fn (string $b4): bool => self::isPublicIp((string)inet_ntop($b4), $devAllow);
        $zeros10 = str_repeat("\0", 10);
        if (substr($bin, 0, 12) === $zeros10 . "\xff\xff") { return $embedded(substr($bin, 12)); }        // ::ffff:0:0/96 (IPv4-mapped)
        if (substr($bin, 0, 12) === str_repeat("\0", 12)) { return false; }                               // ::/96 (unspecified, loopback, IPv4-compatible)
        if (substr($bin, 0, 12) === "\x00\x64\xff\x9b" . str_repeat("\0", 8)) { return $embedded(substr($bin, 12)); } // 64:ff9b::/96 (NAT64)
        if (substr($bin, 0, 6) === "\x00\x64\xff\x9b\x00\x01") { return false; }                         // 64:ff9b:1::/48 (local-use NAT64)
        if (substr($bin, 0, 2) === "\x20\x02") { return $embedded(substr($bin, 2, 4)); }                  // 2002::/16 (6to4)
        if ((ord($bin[0]) & 0xE0) !== 0x20) { return false; }                                             // only 2000::/3 is global unicast
        if (substr($bin, 0, 2) === "\x20\x01" && ord($bin[2]) < 0x02) { return false; }                  // 2001::/23 incl. Teredo 2001::/32
        if (substr($bin, 0, 4) === "\x20\x01\x0d\xb8") { return false; }                                  // 2001:db8::/32 documentation
        if (ord($bin[0]) === 0x3f && ord($bin[1]) === 0xff && (ord($bin[2]) & 0xF0) === 0) { return false; } // 3fff::/20 documentation
        return true;
    }

    public static function inCidr(string $bin, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) { $cidr .= '/' . (str_contains($cidr, ':') ? '128' : '32'); }
        [$net, $bits] = explode('/', $cidr, 2);
        $nb = @inet_pton($net);
        if ($nb === false || strlen($nb) !== strlen($bin) || !ctype_digit($bits) || (int)$bits > strlen($nb) * 8) { return false; }
        $bits = (int)$bits;
        $full = intdiv($bits, 8);
        if ($full > 0 && substr($bin, 0, $full) !== substr($nb, 0, $full)) { return false; }
        $rem = $bits % 8;
        if ($rem === 0) { return true; }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($bin[$full]) & $mask) === (ord($nb[$full]) & $mask);
    }

    // ------------------------------------------------------------------ defaults

    /** @return string[] */
    public static function systemResolve(string $host): array
    {
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $r) {
            if (!empty($r['ip'])) { $ips[] = $r['ip']; }
            if (!empty($r['ipv6'])) { $ips[] = $r['ipv6']; }
        }
        // Also consult the system resolver (hosts file, search domains). Union => any private answer blocks the URL.
        $v4 = @gethostbynamel($host);
        foreach (is_array($v4) ? $v4 : [] as $ip) { $ips[] = $ip; }
        return array_values(array_unique($ips));
    }

    private function userAgent(): string
    {
        $contact = trim((string)Config::get('contact', ''));
        return 'RankMathWebapp-SEO/1.0' . ($contact !== '' ? " (+$contact)" : '');
    }

    /** @param array<string,mixed> $r */
    private function curlTransport(array $r): array
    {
        $headers = [];
        $body = '';
        $tooLarge = false;
        $max = (int)$r['max_bytes'];

        $ch = curl_init($r['url']);
        $opts = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
            CURLOPT_CONNECTTIMEOUT_MS => (int)max(100, $r['connect_timeout'] * 1000),
            CURLOPT_TIMEOUT_MS     => (int)max(200, $r['timeout'] * 1000),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => $r['user_agent'],
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXFILESIZE    => $max,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_HTTPHEADER     => array_merge(['Accept: ' . $r['accept']], $r['headers']),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $len = strlen($line);
                $line = trim($line);
                if (stripos($line, 'HTTP/') === 0) { $headers = []; }
                elseif (($pos = strpos($line, ':')) !== false) {
                    $k = strtolower(trim(substr($line, 0, $pos)));
                    $v = trim(substr($line, $pos + 1));
                    $headers[$k] = isset($headers[$k]) ? $headers[$k] . ', ' . $v : $v;
                }
                return $len;
            },
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$body, &$tooLarge, $max): int {
                if (strlen($body) + strlen($chunk) > $max) { $tooLarge = true; return -1; }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $opts[CURLOPT_PROTOCOLS_STR] = 'http,https';
            $opts[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
        } else {
            $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if ($r['method'] === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = (string)$r['body'];
        } elseif ($r['method'] === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
        } else {
            $opts[CURLOPT_HTTPGET] = true;
        }
        if (!$r['literal']) {
            // Pin host:port to the addresses we validated. Hostname stays in the URL so SNI and certificate checks work.
            $addrs = array_map(static fn (string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip, $r['ips']);
            $opts[CURLOPT_RESOLVE] = [$r['host'] . ':' . $r['port'] . ':' . implode(',', $addrs)];
        }
        curl_setopt_array($ch, $opts);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($tooLarge || $errno === 63 /* CURLE_FILESIZE_EXCEEDED */) {
            throw new ResponseTooLargeException('Response exceeded the size limit');
        }
        if ($ok === false || $errno !== 0) {
            error_log("SafeHttpClient: curl error $errno: $err");
            if ($errno === 28) { throw new FetchTimeoutException('The request timed out'); }
            if (in_array($errno, [35, 51, 58, 59, 60, 64, 66, 77, 80, 82, 83], true)) { throw new FetchException('TLS/certificate verification failed'); }
            throw new FetchException('Could not fetch the URL');
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}
