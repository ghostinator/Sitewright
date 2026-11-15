<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** Thin, test-friendly accessor for the current request. */
final class Request
{
    private static ?array $fake = null;
    private static ?array $jsonCache = null;

    /** Test seam. Keys: method, query, body (array|string|null), headers, files, ip. */
    public static function fake(array $o): void
    {
        self::$fake = $o + ['method' => 'GET', 'query' => [], 'body' => null, 'headers' => [], 'files' => [], 'ip' => '203.0.113.9'];
        self::$jsonCache = null;
    }

    public static function reset(): void
    {
        self::$fake = null;
        self::$jsonCache = null;
    }

    public static function method(): string
    {
        return strtoupper(self::$fake['method'] ?? $_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function header(string $name): ?string
    {
        if (self::$fake !== null) {
            foreach (self::$fake['headers'] as $k => $v) {
                if (strcasecmp($k, $name) === 0) { return (string)$v; }
            }
            return null;
        }
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (strcasecmp($name, 'Content-Type') === 0) { $key = 'CONTENT_TYPE'; }
        return isset($_SERVER[$key]) ? (string)$_SERVER[$key] : null;
    }

    public static function query(string $key, mixed $default = null): mixed
    {
        $q = self::$fake['query'] ?? $_GET;
        return array_key_exists($key, $q) && !is_array($q[$key]) ? $q[$key] : $default;
    }

    /** Integer query parameter, clamped to [$min,$max]; non-numeric input falls back to $default. */
    public static function queryInt(string $key, int $default, int $min, int $max): int
    {
        $v = self::query($key);
        if ($v === null || !is_scalar($v) || !preg_match('/^-?\d{1,12}$/D', (string)$v)) {
            return max($min, min($max, $default));
        }
        return max($min, min($max, (int)$v));
    }

    public static function file(string $name): ?array
    {
        $files = self::$fake['files'] ?? $_FILES;
        return isset($files[$name]) && is_array($files[$name]) ? $files[$name] : null;
    }

    /**
     * Client address. REMOTE_ADDR unless it is a configured trusted proxy; then X-Forwarded-For is read right to left and the
     * first address that is not itself a trusted proxy wins (a client can only add entries on the left, never on the right).
     */
    public static function ip(): string
    {
        $remote = (string)(self::$fake['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $trusted = (array)Config::get('trusted_proxies', []);
        if ($trusted === [] || !self::inList($remote, $trusted)) {
            return $remote;
        }
        $xff = self::header('X-Forwarded-For');
        if ($xff === null || $xff === '') {
            return $remote;
        }
        $hops = array_map('trim', explode(',', $xff));
        for ($i = count($hops) - 1; $i >= 0; $i--) {
            if (@inet_pton($hops[$i]) === false) {
                return $remote;               // garbage in the chain: fall back to the proxy address, never to client-supplied text
            }
            if (!self::inList($hops[$i], $trusted) || $i === 0) {
                return $hops[$i];
            }
        }
        return $remote;
    }

    private static function inList(string $ip, array $cidrs): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) { return false; }
        if (strlen($bin) === 16 && str_starts_with($bin, "\0\0\0\0\0\0\0\0\0\0\xff\xff")) { $bin = substr($bin, 12); } // ::ffff:a.b.c.d
        foreach ($cidrs as $c) {
            if (SafeHttpClient::inCidr($bin, (string)$c)) { return true; }
        }
        return false;
    }

    /** Parsed JSON object body ([] when empty). Rejects oversized or malformed bodies with 413/400. */
    public static function json(): array
    {
        if (self::$jsonCache !== null) {
            return self::$jsonCache;
        }
        $max = (int)Config::get('limits.body_bytes', 1048576);
        if (self::$fake !== null) {
            $raw = is_array(self::$fake['body']) ? json_encode(self::$fake['body']) : (string)(self::$fake['body'] ?? '');
        } else {
            $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
            if ($len > $max) {
                Response::error('Request body too large', 413);
            }
            $raw = (string)stream_get_contents(fopen('php://input', 'rb'), $max + 1);
        }
        if (strlen($raw) > $max) {
            Response::error('Request body too large', 413);
        }
        if (trim($raw) === '') {
            return self::$jsonCache = [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            Response::error('Request body must be a JSON object', 400);
        }
        return self::$jsonCache = $data;
    }
}
