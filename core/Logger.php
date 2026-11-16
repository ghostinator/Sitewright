<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Structured logging: one JSON object per line ({"ts","level","event","request_id",...}). Where it goes is configuration, not code:
 * log.file = a path (append, one write per line), "stderr", or "" (PHP's error_log: php-fpm log / Apache error log).
 * Fields whose names look like secrets are masked and long strings are cut, so a log line can never leak a key or a body.
 */
final class Logger
{
    private const LEVELS = ['debug' => 10, 'info' => 20, 'warn' => 30, 'error' => 40];
    private const SECRET_KEY = '/pass|secret|token|key|authorization|cookie|code|credential/i';

    public static function debug(string $event, array $ctx = []): void { self::log('debug', $event, $ctx); }
    public static function info(string $event, array $ctx = []): void  { self::log('info', $event, $ctx); }
    public static function warn(string $event, array $ctx = []): void  { self::log('warn', $event, $ctx); }
    public static function error(string $event, array $ctx = []): void { self::log('error', $event, $ctx); }

    public static function log(string $level, string $event, array $ctx = []): void
    {
        try {
            $min = self::LEVELS[strtolower((string)Config::get('log.level', 'info'))] ?? 20;
            if ((self::LEVELS[$level] ?? 20) < $min) { return; }
            $line = json_encode(
                ['ts' => gmdate('Y-m-d\TH:i:s\Z'), 'level' => $level, 'event' => $event, 'request_id' => PHP_SAPI === 'cli' ? 'cli' : App::requestId()] + self::clean($ctx),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
            if ($line === false) { return; }
            $dest = (string)Config::get('log.file', '');
            if ($dest === 'stderr') {
                @file_put_contents('php://stderr', $line . "\n");
            } elseif ($dest !== '') {
                @file_put_contents($dest, $line . "\n", FILE_APPEND | LOCK_EX);
            } else {
                error_log($line);
            }
        } catch (\Throwable) {
            // logging must never take the application down
        }
    }

    /** @return array<string,mixed> */
    private static function clean(array $ctx): array
    {
        $out = [];
        foreach ($ctx as $k => $v) {
            $k = (string)$k;
            if (preg_match(self::SECRET_KEY, $k)) { $out[$k] = '[masked]'; continue; }
            if (is_string($v)) { $v = mb_substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '', 0, 300); }
            elseif (!is_scalar($v) && $v !== null) { $v = mb_substr((string)json_encode($v, JSON_INVALID_UTF8_SUBSTITUTE), 0, 300); }
            $out[$k] = $v;
        }
        return $out;
    }
}
