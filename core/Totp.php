<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** RFC 6238 time-based one-time passwords (HMAC-SHA1, 30 s step) with RFC 4648 base32 secrets. No dependencies. */
final class Totp
{
    public const STEP = 30;
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function base32Encode(string $bin): string
    {
        if ($bin === '') { return ''; }    // str_split('') is [''] before PHP 8.2
        $bits = '';
        foreach (str_split($bin) as $c) { $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT); }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) { $out .= self::B32[bindec(str_pad($chunk, 5, '0'))]; }
        return $out;
    }

    /** Strict decoder: case-insensitive, ignores spaces/hyphens/padding, returns null on any other character. */
    public static function base32Decode(string $s): ?string
    {
        $s = strtoupper(rtrim(str_replace([' ', '-'], '', $s), '='));
        if ($s === '' || !preg_match('/^[A-Z2-7]+$/D', $s)) { return null; }
        $bits = '';
        foreach (str_split($s) as $c) { $bits .= str_pad(decbin(strpos(self::B32, $c)), 5, '0', STR_PAD_LEFT); }
        $out = '';
        foreach (str_split($bits, 8) as $byte) { if (strlen($byte) === 8) { $out .= chr(bindec($byte)); } }
        return $out;
    }

    /** Code for a time step. $key is the raw (decoded) secret. */
    public static function code(string $key, int $step, int $digits = 6): string
    {
        $h = hash_hmac('sha1', pack('J', $step), $key, true);
        $o = ord($h[19]) & 0x0F;
        $n = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        return str_pad((string)($n % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Matching time step (> $lastStep, within ±$window steps of $now) or null. Returning the step lets the caller store it, so a
     * code that was already accepted can never be used again, and a code from an earlier step than the last one cannot either.
     */
    public static function verify(string $secretB32, string $input, int $now, int $lastStep, int $window = 1): ?int
    {
        $input = str_replace(' ', '', $input);
        $key = self::base32Decode($secretB32);
        if ($key === null || !preg_match('/^\d{6}$/D', $input)) { return null; }
        $cur = intdiv($now, self::STEP);
        $hit = null;
        for ($s = $cur - $window; $s <= $cur + $window; $s++) {      // every step is compared, so timing does not reveal which matched
            if (hash_equals(self::code($key, $s), $input) && $s > $lastStep) { $hit = $s; }
        }
        return $hit;
    }

    public static function uri(string $issuer, string $account, string $secretB32): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . $secretB32 . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    /** @return string[] human-readable single-use codes, "xxxxx-xxxxx" */
    public static function newRecoveryCodes(int $n = 8): array
    {
        $alpha = 'abcdefghjkmnpqrstuvwxyz23456789';
        $codes = [];
        while (count($codes) < $n) {
            $c = '';
            for ($i = 0; $i < 10; $i++) { $c .= $alpha[random_int(0, strlen($alpha) - 1)]; }
            $codes[$c] = substr($c, 0, 5) . '-' . substr($c, 5);
        }
        return array_values($codes);
    }

    public static function normalizeRecovery(string $code): string
    {
        return strtolower(str_replace([' ', '-'], '', $code));
    }

    public static function recoveryHash(string $code): string
    {
        return hash('sha256', self::normalizeRecovery($code));
    }
}
