<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Session authentication (single role: every account in {prefix}users is an administrator).
 * Cookie: HttpOnly, SameSite=Lax, Secure over HTTPS; server-side idle and absolute timeouts;
 * session id regenerated on login; per-session CSRF token.
 */
final class Auth
{
    private static ?array $user = null;
    private static bool $userChecked = false;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = Config::get('session.secure');
        if ($secure === null) {
            $base = (string)Config::get('base_url', '');
            $secure = $base !== '' ? str_starts_with($base, 'https://') : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string)max(3600, (int)Config::get('session.absolute_timeout')));
        session_name((string)Config::get('session.name'));
        session_set_cookie_params([
            'lifetime' => 0, 'path' => App::basePath() ?: '/', 'secure' => (bool)$secure,
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        session_start();
    }

    /** Authenticated user row (id, username, display_name) or null. */
    public static function user(): ?array
    {
        if (self::$userChecked) {
            return self::$user;
        }
        self::$userChecked = true;
        self::startSession();
        if (empty($_SESSION['uid'])) {
            return null;
        }
        $now = time();
        $expired = $now - (int)($_SESSION['created'] ?? 0) > (int)Config::get('session.absolute_timeout')
                || $now - (int)($_SESSION['last'] ?? 0) > (int)Config::get('session.idle_timeout');
        $db = Database::getInstance();
        $row = $expired ? null : $db->fetchOne(
            'SELECT id, username, display_name, password FROM `' . $db->t('users') . '` WHERE id = :id',
            ['id' => (int)$_SESSION['uid']]
        );
        // The password fingerprint ends sessions when the password changes or the account is removed.
        if (!$row || !hash_equals((string)($_SESSION['pwf'] ?? ''), self::fingerprint($row['password']))) {
            self::clearAuth();
            return null;
        }
        $_SESSION['last'] = $now;
        return self::$user = ['id' => (int)$row['id'], 'username' => $row['username'], 'display_name' => $row['display_name']];
    }

    /**
     * Verify credentials (throttled). Returns the user on success, null on bad credentials.
     * @throws ResponseException 429 when throttled
     */
    public static function login(string $username, string $password): ?array
    {
        $win = (int)Config::get('limits.login_window');
        RateLimiter::enforce('login:ip:' . Request::ip(), (int)Config::get('limits.login_ip_attempts'), $win, 'Too many login attempts');
        $userKey = 'login:user:' . hash('sha256', strtolower($username));
        RateLimiter::enforce($userKey, (int)Config::get('limits.login_attempts'), $win, 'Too many login attempts');

        $db = Database::getInstance();
        $row = $db->fetchOne('SELECT id, username, display_name, password FROM `' . $db->t('users') . '` WHERE username = :u', ['u' => $username]);
        // Always run one password_verify so response time does not reveal whether the account exists.
        $hash = $row['password'] ?? self::dummyHash();
        $valid = password_verify($password, $hash) && $row !== null;
        if (!$valid) {
            return null;
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $db->update('users', ['password' => password_hash($password, PASSWORD_DEFAULT)], ['id' => (int)$row['id']]);
        }
        RateLimiter::clear($userKey);

        self::startSession();
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$row['id'];
        $_SESSION['created'] = $_SESSION['last'] = time();
        $_SESSION['pwf'] = self::fingerprint($row['password']);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        self::$userChecked = true;
        return self::$user = ['id' => (int)$row['id'], 'username' => $row['username'], 'display_name' => $row['display_name']];
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
        }
        session_destroy();
        self::$user = null;
        self::$userChecked = true;
    }

    public static function csrfToken(): string
    {
        self::startSession();
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function csrfValid(?string $sent): bool
    {
        self::startSession();
        return $sent !== null && $sent !== '' && isset($_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], $sent);
    }

    /** Test seam: forget per-request caches. */
    public static function resetCache(): void
    {
        self::$user = null;
        self::$userChecked = false;
    }

    private static function clearAuth(): void
    {
        unset($_SESSION['uid'], $_SESSION['created'], $_SESSION['last'], $_SESSION['pwf']);
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    private static function fingerprint(string $passwordHash): string
    {
        return substr(hash('sha256', $passwordHash), 0, 32);
    }

    private static function dummyHash(): string
    {
        static $h = null;
        return $h ??= password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
    }
}
