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
    /** Seconds the "password OK, code pending" state lasts. */
    private const MFA_WINDOW = 300;

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
            'SELECT id, username, display_name, password, totp_secret FROM `' . $db->t('users') . '` WHERE id = :id',
            ['id' => (int)$_SESSION['uid']]
        );
        // The password fingerprint ends sessions when the password changes or the account is removed.
        if (!$row || !hash_equals((string)($_SESSION['pwf'] ?? ''), self::fingerprint($row))) {
            self::clearAuth();
            return null;
        }
        $_SESSION['last'] = $now;
        return self::$user = ['id' => (int)$row['id'], 'username' => $row['username'], 'display_name' => $row['display_name']];
    }

    /**
     * Verify credentials (throttled). Returns the user on success, ['mfa_required' => true] when a one-time code must follow
     * (the session is then only "pending": Auth::user() stays null), or null on bad credentials.
     * @throws ResponseException 429 when throttled
     */
    public static function login(string $username, string $password): ?array
    {
        $win = (int)Config::get('limits.login_window');
        RateLimiter::enforce('login:ip:' . Request::ip(), (int)Config::get('limits.login_ip_attempts'), $win, 'Too many login attempts');
        $userKey = 'login:user:' . hash('sha256', strtolower($username));
        RateLimiter::enforce($userKey, (int)Config::get('limits.login_attempts'), $win, 'Too many login attempts');

        $db = Database::getInstance();
        $row = $db->fetchOne('SELECT id, username, display_name, password, totp_secret FROM `' . $db->t('users') . '` WHERE username = :u', ['u' => $username]);
        // Always run one password_verify so response time does not reveal whether the account exists.
        $hash = $row['password'] ?? self::dummyHash();
        $valid = password_verify($password, $hash) && $row !== null;
        if (!$valid) {
            // An unknown name is recorded as such: people sometimes type their password into the username box.
            Audit::log('login_failed', $row ? (int)$row['id'] : null, $row ? $row['username'] : '(unknown)');
            return null;
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $db->update('users', ['password' => password_hash($password, PASSWORD_DEFAULT)], ['id' => (int)$row['id']]);
        }
        RateLimiter::clear($userKey);

        if ($row['totp_secret'] !== null && $row['totp_secret'] !== '') {
            self::startSession();
            session_regenerate_id(true);
            unset($_SESSION['uid'], $_SESSION['created'], $_SESSION['last'], $_SESSION['pwf']);   // password alone grants nothing
            $_SESSION['mfa_uid'] = (int)$row['id'];
            $_SESSION['mfa_until'] = time() + self::MFA_WINDOW;
            $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
            Audit::log('login_password_ok', (int)$row['id'], $row['username'], 'second factor required');
            return ['mfa_required' => true];
        }
        return self::establish($row, 'login_ok');
    }

    /**
     * Second step: a 6-digit code from the authenticator app, or a single-use recovery code.
     * @return array|null user on success, null for a wrong code
     * @throws ResponseException 401 when no sign-in is pending or it expired, 429 when throttled
     */
    public static function completeMfa(string $input): ?array
    {
        self::startSession();
        $uid = (int)($_SESSION['mfa_uid'] ?? 0);
        if ($uid === 0 || time() > (int)($_SESSION['mfa_until'] ?? 0)) {
            unset($_SESSION['mfa_uid'], $_SESSION['mfa_until']);
            Response::error('Sign in again', 401);
        }
        // Per ACCOUNT (an attacker can discard their session and start over) and per IP.
        $win = (int)Config::get('limits.login_window');
        RateLimiter::enforce('mfa:ip:' . Request::ip(), (int)Config::get('limits.login_ip_attempts'), $win, 'Too many verification attempts');
        RateLimiter::enforce('mfa:user:' . $uid, (int)Config::get('limits.login_attempts'), $win, 'Too many verification attempts');

        $db = Database::getInstance();
        $row = $db->fetchOne('SELECT id, username, display_name, password, totp_secret, totp_last_step FROM `' . $db->t('users') . '` WHERE id = :id', ['id' => $uid]);
        if (!$row || ($row['totp_secret'] ?? '') === '') {
            unset($_SESSION['mfa_uid'], $_SESSION['mfa_until']);
            Response::error('Sign in again', 401);
        }

        $ok = false; $how = 'mfa_ok';
        $digits = str_replace(' ', '', $input);
        if (preg_match('/^\d{6}$/D', $digits)) {
            $step = Totp::verify((string)$row['totp_secret'], $digits, time(), (int)$row['totp_last_step']);
            // The conditional UPDATE makes "use each step once" safe even for two simultaneous requests.
            $ok = $step !== null && $db->update('users', ['totp_last_step' => $step], ['id' => $uid, 'totp_last_step' => (int)$row['totp_last_step']]) === 1;
        } elseif (preg_match('/^[a-z2-9]{10}$/D', Totp::normalizeRecovery($input))) {
            $ok = $db->query(
                'UPDATE `' . $db->t('recovery_codes') . '` SET used_at = NOW() WHERE user_id = :u AND code_hash = :h AND used_at IS NULL',
                ['u' => $uid, 'h' => Totp::recoveryHash($input)]
            )->rowCount() === 1;
            $how = 'recovery_code_used';
        }
        if (!$ok) {
            Audit::log('mfa_failed', $uid, $row['username']);
            return null;
        }
        RateLimiter::clear('mfa:user:' . $uid);
        return self::establish($row, $how);
    }

    /** Create the authenticated session (fresh id, fresh CSRF token). */
    private static function establish(array $row, string $auditAction): array
    {
        self::startSession();
        session_regenerate_id(true);
        unset($_SESSION['mfa_uid'], $_SESSION['mfa_until']);
        $_SESSION['uid'] = (int)$row['id'];
        $_SESSION['created'] = $_SESSION['last'] = time();
        $_SESSION['pwf'] = self::fingerprint($row);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        self::$userChecked = true;
        Audit::log($auditAction, (int)$row['id'], $row['username']);
        return self::$user = ['id' => (int)$row['id'], 'username' => $row['username'], 'display_name' => $row['display_name']];
    }

    public static function logout(): void
    {
        self::startSession();
        if (!empty($_SESSION['uid'])) { Audit::log('logout'); }
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
        unset($_SESSION['uid'], $_SESSION['created'], $_SESSION['last'], $_SESSION['pwf'], $_SESSION['mfa_uid'], $_SESSION['mfa_until']);
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    /** Changes when the password or the MFA secret changes, which ends every session of that account. */
    private static function fingerprint(array $row): string
    {
        return substr(hash('sha256', $row['password'] . '|' . ($row['totp_secret'] ?? '')), 0, 32);
    }

    private static function dummyHash(): string
    {
        static $h = null;
        return $h ??= password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
    }
}
