<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Layered configuration: built-in defaults < config/config.php < SW_* environment variables.
 * config/config.php is git-ignored; see config/config.example.php.
 */
final class Config
{
    /** Default Gemini model; override with SW_GEMINI_MODEL / config. Listed as Stable on Google's model page on 2026-11-15; models are retired regularly, so set SW_GEMINI_MODEL if it stops working. */
    public const DEFAULT_GEMINI_MODEL = 'gemini-3.6-flash';

    private static ?array $data = null;
    private static ?string $file = null;

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'debug'            => false,
            'base_url'         => '',
            'allowed_hosts'    => [],
            'contact'          => '',
            'enable_geocoding' => false,
            'retention_days_404' => 90,
            'hsts'             => false,
            'trusted_proxies'  => [],
            'app_key'          => '',
            'google'           => ['client_id' => '', 'client_secret' => '', 'redirect_uri' => ''],
            'sites'            => ['sync_minutes' => 10],
            'log' => ['file' => '', 'level' => 'info', 'requests' => true],
            'mail' => [
                'driver' => 'smtp', 'postal_url' => '', 'postal_key' => '',
                'host' => '', 'port' => 587, 'encryption' => 'starttls', 'username' => '', 'password' => '',
                'from' => '', 'from_name' => 'Sitewright', 'verify_tls' => true, 'timeout' => 10,
            ],
            'alerts' => ['email_to' => [], 'teams_webhook' => '', 'telegram_token' => '', 'telegram_chat_id' => '', 'cooldown' => 300, 'login_failures' => 10],
            'retention_days_audit' => 365,
            'db' => [
                'host' => '127.0.0.1', 'port' => 3306, 'name' => 'sitewright_webapp',
                'user' => '', 'pass' => '', 'prefix' => 'sw_',
            ],
            'gemini' => [
                'api_key'  => '',
                'model'    => self::DEFAULT_GEMINI_MODEL,
                'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models',
            ],
            'session' => [
                'name' => 'sw_session', 'idle_timeout' => 1800, 'absolute_timeout' => 28800, 'secure' => null,
            ],
            'limits' => [
                'body_bytes' => 1048576, 'max_pages' => 500, 'bulk_images' => 50, 'import_rows' => 5000,
                'login_attempts' => 5, 'login_ip_attempts' => 30, 'login_window' => 900,
                'expensive_per_min' => 10, 'crawl_seconds' => 120, 'crawl_delay_ms' => 200,
                'jobs_per_user' => 5, 'job_stale_seconds' => 600, 'job_retention_days' => 14,
            ],
            'fetch' => [
                'allow_hosts' => [], 'block_hosts' => [], 'allow_ports' => [80, 443],
                'dev_allow_cidrs' => [],
                'connect_timeout' => 5, 'timeout' => 15, 'dns_timeout' => 2,
                'max_html_bytes' => 5242880, 'max_image_bytes' => 10485760, 'max_redirects' => 5,
            ],
        ];
    }

    /** Test seam: point at a different config file (or null to use the default). */
    public static function useFile(?string $file): void
    {
        self::$file = $file;
        self::$data = null;
    }

    public static function reset(): void
    {
        self::$data = null;
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        if (self::$data === null) {
            self::$data = self::build();
        }
        return self::$data;
    }

    public static function get(string $path, mixed $default = null): mixed
    {
        $node = self::all();
        foreach (explode('.', $path) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    /** Test seam: override a value at runtime (dot path). */
    public static function set(string $path, mixed $value): void
    {
        $data = self::all();
        $ref = &$data;
        foreach (explode('.', $path) as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = $ref[$part] ?? [];
            }
            $ref = &$ref[$part];
        }
        $ref = $value;
        unset($ref);
        self::$data = $data;
    }

    public static function root(): string
    {
        return dirname(__DIR__);
    }

    private static function build(): array
    {
        $cfg = self::defaults();

        $file = self::$file ?? ((($e = getenv('SW_CONFIG_FILE')) !== false && $e !== '') ? $e : self::root() . '/config/config.php');
        if (is_file($file)) {
            $loaded = (static function (string $f) { return require $f; })($file);
            if (is_array($loaded)) {
                $cfg = array_replace_recursive($cfg, $loaded);
            }
        }

        $env = static fn (string $k): ?string => (($v = getenv($k)) === false || $v === '') ? null : $v;
        $list = static fn (string $v): array => array_values(array_filter(array_map('trim', explode(',', $v)), 'strlen'));

        foreach (['host' => 'SW_DB_HOST', 'name' => 'SW_DB_NAME', 'user' => 'SW_DB_USER', 'pass' => 'SW_DB_PASS', 'prefix' => 'SW_DB_PREFIX'] as $k => $e) {
            if (($v = $env($e)) !== null) { $cfg['db'][$k] = $v; }
        }
        if (($v = $env('SW_DB_PORT')) !== null) { $cfg['db']['port'] = (int)$v; }
        if (($v = $env('SW_GEMINI_API_KEY')) !== null) { $cfg['gemini']['api_key'] = $v; }
        if (($v = $env('SW_GEMINI_MODEL')) !== null) { $cfg['gemini']['model'] = $v; }
        if (($v = $env('SW_BASE_URL')) !== null) { $cfg['base_url'] = rtrim($v, '/'); }
        if (($v = $env('SW_ALLOWED_HOSTS')) !== null) { $cfg['allowed_hosts'] = $list($v); }
        if (($v = $env('SW_CONTACT')) !== null) { $cfg['contact'] = $v; }
        if (($v = $env('SW_ENABLE_GEOCODING')) !== null) { $cfg['enable_geocoding'] = filter_var($v, FILTER_VALIDATE_BOOLEAN); }
        if (($v = $env('SW_DEBUG')) !== null) { $cfg['debug'] = filter_var($v, FILTER_VALIDATE_BOOLEAN); }
        foreach (['file' => 'SW_LOG_FILE', 'level' => 'SW_LOG_LEVEL'] as $k => $e) { if (($v = $env($e)) !== null) { $cfg['log'][$k] = $v; } }
        foreach (['host' => 'SW_MAIL_HOST', 'encryption' => 'SW_MAIL_ENCRYPTION', 'username' => 'SW_MAIL_USERNAME', 'password' => 'SW_MAIL_PASSWORD', 'from' => 'SW_MAIL_FROM', 'from_name' => 'SW_MAIL_FROM_NAME'] as $k => $e) { if (($v = $env($e)) !== null) { $cfg['mail'][$k] = $v; } }
        foreach (['driver' => 'SW_MAIL_DRIVER', 'postal_url' => 'SW_MAIL_POSTAL_URL', 'postal_key' => 'SW_MAIL_POSTAL_KEY'] as $k => $e) { if (($v = $env($e)) !== null) { $cfg['mail'][$k] = $v; } }
        if (($v = $env('SW_MAIL_PORT')) !== null) { $cfg['mail']['port'] = (int)$v; }
        if (($v = $env('SW_MAIL_VERIFY_TLS')) !== null) { $cfg['mail']['verify_tls'] = filter_var($v, FILTER_VALIDATE_BOOLEAN); }
        if (($v = $env('SW_ALERT_EMAIL_TO')) !== null) { $cfg['alerts']['email_to'] = $list($v); }
        foreach (['teams_webhook' => 'SW_ALERT_TEAMS_WEBHOOK', 'telegram_token' => 'SW_ALERT_TELEGRAM_TOKEN', 'telegram_chat_id' => 'SW_ALERT_TELEGRAM_CHAT_ID'] as $k => $e) { if (($v = $env($e)) !== null) { $cfg['alerts'][$k] = $v; } }
        foreach (['client_id' => 'SW_GOOGLE_CLIENT_ID', 'client_secret' => 'SW_GOOGLE_CLIENT_SECRET', 'redirect_uri' => 'SW_GOOGLE_REDIRECT_URI'] as $k => $e) { if (($v = $env($e)) !== null) { $cfg['google'][$k] = $v; } }
        if (($v = $env('SW_APP_KEY')) !== null) { $cfg['app_key'] = $v; }
        if (($v = $env('SW_TRUSTED_PROXIES')) !== null) { $cfg['trusted_proxies'] = $list($v); }
        if (($v = $env('SW_HSTS')) !== null) { $cfg['hsts'] = filter_var($v, FILTER_VALIDATE_BOOLEAN); }
        // Outbound controls are deliberately separate from SW_ALLOWED_HOSTS (inbound Host validation).
        if (($v = $env('SW_FETCH_ALLOW_HOSTS')) !== null) { $cfg['fetch']['allow_hosts'] = $list($v); }
        if (($v = $env('SW_FETCH_BLOCK_HOSTS')) !== null) { $cfg['fetch']['block_hosts'] = $list($v); }
        if (($v = $env('SW_FETCH_DEV_ALLOW_CIDRS')) !== null) { $cfg['fetch']['dev_allow_cidrs'] = $list($v); }

        $cfg['debug']            = (bool)$cfg['debug'];
        $cfg['enable_geocoding'] = (bool)$cfg['enable_geocoding'];
        $cfg['db']['port']       = (int)$cfg['db']['port'];

        $cfg['mail']['port'] = (int)$cfg['mail']['port'];
        $cfg['mail']['verify_tls'] = (bool)$cfg['mail']['verify_tls'];
        if (!in_array($cfg['mail']['driver'], ['smtp', 'postal'], true)) { throw new ConfigException('mail.driver must be smtp or postal'); }
        if ($cfg['mail']['postal_url'] !== '') { $cfg['mail']['postal_url'] = rtrim((string)$cfg['mail']['postal_url'], '/'); }
        if (!in_array($cfg['mail']['encryption'], ['starttls', 'tls', 'none'], true)) {
            throw new ConfigException('mail.encryption must be starttls, tls or none');
        }
        $cfg['alerts']['email_to'] = array_values(array_map('strval', (array)$cfg['alerts']['email_to']));
        // Trusted proxies decide whose X-Forwarded-For is believed, so a bad entry must stop the app, never be skipped.
        $cfg['trusted_proxies'] = array_values(array_map('strval', (array)$cfg['trusted_proxies']));
        foreach ($cfg['trusted_proxies'] as $cidr) {
            self::assertTrustedProxy($cidr);
        }

        // Identifiers end up inside SQL text, so they are validated once, here.
        if (!preg_match('/^[A-Za-z0-9_]{0,16}$/D', (string)$cfg['db']['prefix'])
            || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', (string)$cfg['db']['name'])) {
            throw new ConfigException('Invalid database name or prefix in configuration');
        }
        return $cfg;
    }

    /** An address or CIDR; the "trust everything" ranges (/0) are refused because they let any client forge its IP. */
    private static function assertTrustedProxy(string $cidr): void
    {
        $net = $cidr; $bits = null;
        if (str_contains($cidr, '/')) { [$net, $bits] = explode('/', $cidr, 2); }
        $bin = @inet_pton($net);
        $max = $bin === false ? 0 : strlen($bin) * 8;
        if ($bin === false || ($bits !== null && (!ctype_digit($bits) || (int)$bits > $max))) {
            throw new ConfigException("Invalid trusted_proxies entry: $cidr");
        }
        if ($bits !== null && (int)$bits === 0) {
            throw new ConfigException("trusted_proxies entry $cidr would trust every client; list your proxy addresses instead");
        }
    }

    /** Hostnames accepted in the inbound Host header (lower-case, no port). */
    public static function allowedHosts(): array
    {
        $hosts = array_map('strtolower', (array)self::get('allowed_hosts', []));
        $base = (string)self::get('base_url', '');
        if ($base !== '' && ($h = parse_url($base, PHP_URL_HOST))) {
            $hosts[] = strtolower($h);
        }
        return $hosts ?: ['localhost', '127.0.0.1', '[::1]'];
    }

    public static function hostAllowed(string $hostHeader): bool
    {
        return in_array(self::hostOnly($hostHeader), self::allowedHosts(), true);
    }

    /** "Example.com:8080" -> "example.com"; "[::1]:80" -> "[::1]". */
    public static function hostOnly(string $hostHeader): string
    {
        $h = strtolower(trim($hostHeader));
        if ($h !== '' && $h[0] === '[') {
            $end = strpos($h, ']');
            return $end === false ? $h : substr($h, 0, $end + 1);
        }
        $colon = strrpos($h, ':');
        return $colon === false ? $h : substr($h, 0, $colon);
    }
}
