<?php
declare(strict_types=1);

namespace RankMathWebapp\Core;

/** Input validation helpers. Every failure becomes a 400 with a field-level message (never a 500). */
final class Validator
{
    public static function fail(string $field, string $msg): never
    {
        Response::error("Invalid '$field': $msg", 400, [$field => $msg]);
    }

    /** Required/optional string, trimmed, control characters rejected, length-limited (in characters). */
    public static function str(array $in, string $field, int $max, bool $required = false): ?string
    {
        $v = $in[$field] ?? null;
        if ($v === null || (is_string($v) && trim($v) === '')) {
            if ($required) { self::fail($field, 'is required'); }
            return null;
        }
        if (!is_string($v) && !is_int($v) && !is_float($v)) { self::fail($field, 'must be text'); }
        $v = trim((string)$v);
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v) || !mb_check_encoding($v, 'UTF-8')) {
            self::fail($field, 'contains invalid characters');
        }
        if (mb_strlen($v) > $max) { self::fail($field, "must be at most $max characters"); }
        return $v;
    }

    public static function enum(array $in, string $field, array $allowed, ?string $default = null): ?string
    {
        $v = $in[$field] ?? null;
        if ($v === null || $v === '') { return $default; }
        $v = (string)$v;
        if (!in_array($v, $allowed, true)) { self::fail($field, 'must be one of: ' . implode(', ', $allowed)); }
        return $v;
    }

    public static function int(array $in, string $field, int $min, int $max, ?int $default = null, bool $required = false): ?int
    {
        $v = $in[$field] ?? null;
        if ($v === null || $v === '') {
            if ($required) { self::fail($field, 'is required'); }
            return $default;
        }
        if (is_bool($v) || (!is_int($v) && !(is_string($v) && preg_match('/^-?\d{1,18}$/', $v)))) { self::fail($field, 'must be an integer'); }
        $v = (int)$v;
        if ($v < $min || $v > $max) { self::fail($field, "must be between $min and $max"); }
        return $v;
    }

    public static function float(array $in, string $field, float $min, float $max, ?float $default = null, bool $required = false): ?float
    {
        $v = $in[$field] ?? null;
        if ($v === null || $v === '') {
            if ($required) { self::fail($field, 'is required'); }
            return $default;
        }
        if (is_bool($v) || !is_numeric($v)) { self::fail($field, 'must be a number'); }
        $v = (float)$v;
        if (!is_finite($v) || $v < $min || $v > $max) { self::fail($field, "must be between $min and $max"); }
        return $v;
    }

    /** ISO date (YYYY-MM-DD) or null. */
    public static function date(array $in, string $field, bool $required = false): ?string
    {
        $v = $in[$field] ?? null;
        if ($v === null || $v === '') {
            if ($required) { self::fail($field, 'is required'); }
            return null;
        }
        $d = is_string($v) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;
        if (!$d || $d->format('Y-m-d') !== $v) { self::fail($field, 'must be a date in YYYY-MM-DD format'); }
        return $v;
    }

    /** Date or datetime -> 'Y-m-d H:i:s'. */
    public static function datetime(array $in, string $field): ?string
    {
        $v = $in[$field] ?? null;
        if ($v === null || $v === '') { return null; }
        $ts = is_string($v) ? strtotime($v) : false;
        if ($ts === false) { self::fail($field, 'must be a valid date/time'); }
        return gmdate('Y-m-d H:i:s', $ts);
    }

    /** http(s) URL with a host, no credentials, within $max characters. */
    public static function url(array $in, string $field, int $max = 2048, bool $required = true): ?string
    {
        $v = self::str($in, $field, $max, $required);
        if ($v === null) { return null; }
        $p = parse_url($v);
        if ($p === false || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)
            || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
            self::fail($field, 'must be a valid http(s) URL');
        }
        return $v;
    }

    public static function email(array $in, string $field): ?string
    {
        $v = self::str($in, $field, 254);
        if ($v !== null && !filter_var($v, FILTER_VALIDATE_EMAIL)) { self::fail($field, 'must be a valid email address'); }
        return $v;
    }

    public static function phone(array $in, string $field): ?string
    {
        $v = self::str($in, $field, 50);
        if ($v !== null && !preg_match('/^[0-9+().\-\s]{5,50}$/', $v)) { self::fail($field, 'must be a valid phone number'); }
        return $v;
    }

    /** Project scoping: 0 means "no project". */
    public static function projectId(array $in): int
    {
        return self::int($in, 'project_id', 0, 4294967295, 0) ?? 0;
    }

    public static function id(mixed $v): int
    {
        if (!is_string($v) && !is_int($v) || !preg_match('/^\d{1,18}$/', (string)$v) || (int)$v < 1) {
            Response::error('Invalid ID', 400);
        }
        return (int)$v;
    }

    /** Neutralise spreadsheet formula injection for CSV cells (SEC-012). */
    public static function csvCell(mixed $v): string
    {
        $s = (string)($v ?? '');
        return $s !== '' && strpbrk($s[0], "=+-@\t\r") !== false ? "'" . $s : $s;
    }
}
