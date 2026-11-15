<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** Account rules shared by the HTTP controllers and the CLI. */
final class Accounts
{
    public const ROLES = ['viewer' => 1, 'editor' => 2, 'admin' => 3];
    private const RESET_TTL_MINUTES = 60;

    public static function validRole(string $r): bool
    {
        return isset(self::ROLES[$r]);
    }

    /** @throws \InvalidArgumentException */
    public static function validatePassword(string $p): void
    {
        if (strlen($p) < 12 || strlen($p) > 1024) { throw new \InvalidArgumentException('Password must be 12-1024 characters'); }
    }

    public static function enabledAdminCount(): int
    {
        $db = Database::getInstance();
        return (int)$db->fetchOne("SELECT COUNT(*) n FROM `{$db->t('users')}` WHERE role = 'admin' AND disabled = 0")['n'];
    }

    /** Create a reset/invite token. Only its SHA-256 is stored; earlier unused tokens for the user stop working. @return string the token */
    public static function createToken(int $userId, string $purpose = 'reset'): string
    {
        $db = Database::getInstance();
        $t = $db->t('password_resets');
        $db->query("UPDATE `$t` SET used_at = NOW() WHERE user_id = :u AND used_at IS NULL", ['u' => $userId]);
        $token = bin2hex(random_bytes(32));
        $ttl = $purpose === 'invite' ? 7 * 24 * 60 : self::RESET_TTL_MINUTES;
        $db->query("INSERT INTO `$t` (user_id, token_hash, purpose, expires_at) VALUES (:u, :h, :p, NOW() + INTERVAL :m MINUTE)", ['u' => $userId, 'h' => hash('sha256', $token), 'p' => $purpose, 'm' => $ttl]);
        return $token;
    }

    /** The link is built from the configured base_url only, never from the (client-controlled) Host header. */
    public static function link(string $token): ?string
    {
        $base = rtrim((string)Config::get('base_url', ''), '/');
        return $base === '' ? null : $base . '/reset?token=' . $token;
    }

    /** Queue the reset/invite e-mail. @return bool false when it cannot be sent (no base_url / mail not configured) */
    public static function sendLink(array $user, string $purpose = 'reset'): bool
    {
        $token = self::createToken((int)$user['id'], $purpose);
        $link = self::link($token);
        if ($link === null || !Mailer::configured()) {
            Logger::warn('reset_mail_unavailable', ['purpose' => $purpose, 'base_url_set' => $link !== null, 'mail_configured' => Mailer::configured()]);
            return false;
        }
        $minutes = $purpose === 'invite' ? '7 days' : '60 minutes';
        $lead = $purpose === 'invite' ? 'An account was created for you on Sitewright. Choose your password here' : 'Someone (hopefully you) asked to reset your Sitewright password. Choose a new one here';
        Mailer::queue((string)$user['email'], $purpose === 'invite' ? 'Your Sitewright account' : 'Reset your Sitewright password',
            "$lead:\n\n$link\n\nThe link works once and expires in $minutes. If you did not ask for this, ignore this message; your password stays unchanged.\n");
        return true;
    }

    /** @return array<string,mixed>|null the user whose valid, unused token this is, and marks it used (atomic) */
    public static function consumeToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) { return null; }
        $db = Database::getInstance();
        $t = $db->t('password_resets');
        $hash = hash('sha256', $token);
        $won = $db->query("UPDATE `$t` SET used_at = NOW() WHERE token_hash = :h AND used_at IS NULL AND expires_at > NOW()", ['h' => $hash])->rowCount();
        if ($won !== 1) { return null; }
        return $db->fetchOne("SELECT u.id, u.username, u.email, u.disabled FROM `$t` r JOIN `{$db->t('users')}` u ON u.id = r.user_id WHERE r.token_hash = :h", ['h' => $hash]);
    }
}
