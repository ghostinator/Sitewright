<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Encryption at rest for secrets the app must be able to read back (site signing secrets, OAuth refresh tokens): libsodium
 * XChaCha20-Poly1305 with a random 24-byte nonce. The ciphertext is bound to a context string (e.g. "site:5"), so a value copied
 * into another row fails to decrypt. The key is config `app_key` / SW_APP_KEY: 32 random bytes, base64. Losing or changing it makes
 * stored secrets unreadable (they must be re-entered), which is the point of keeping it out of the database.
 */
final class Crypto
{
    public static function configured(): bool
    {
        return self::rawKey() !== null;
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
    }

    public static function encrypt(string $plain, string $context): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        return base64_encode($nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, $context, $nonce, self::key()));
    }

    /** @throws ConfigException when the key is missing/wrong or the data was tampered with or moved */
    public static function decrypt(string $blob, string $context): string
    {
        $raw = base64_decode($blob, true);
        $n = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if ($raw === false || strlen($raw) <= $n) { throw new ConfigException('Stored secret is unreadable'); }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $n), $context, substr($raw, 0, $n), self::key());
        if ($plain === false) { throw new ConfigException('Stored secret cannot be decrypted (app_key changed?)'); }
        return $plain;
    }

    private static function rawKey(): ?string
    {
        $k = base64_decode((string)Config::get('app_key', ''), true);
        return $k !== false && strlen($k) === SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES ? $k : null;
    }

    private static function key(): string
    {
        return self::rawKey() ?? throw new ConfigException('app_key is not set (run php bin/gen-key.php and put the value in config as app_key / SW_APP_KEY)');
    }
}
