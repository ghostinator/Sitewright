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

    /** Cookie that holds "selector.secret" of a remembered device, and the longest a device may stay remembered however often it is used. */
    public const REMEMBER_COOKIE = 'sw_remember';
    private const REMEMBER_CEILING_DAYS = 180;

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
            return self::restoreRemembered();
        }
        $now = time();
        $expired = $now - (int)($_SESSION['created'] ?? 0) > (int)Config::get('session.absolute_timeout')
                || $now - (int)($_SESSION['last'] ?? 0) > (int)Config::get('session.idle_timeout');
        $db = Database::getInstance();
        $row = $expired ? null : $db->fetchOne(
            'SELECT id, username, display_name, password, totp_secret, role, disabled FROM `' . $db->t('users') . '` WHERE id = :id',
            ['id' => (int)$_SESSION['uid']]
        );
        // The password fingerprint ends sessions when the password changes or the account is removed.
        if (!$row || (int)$row['disabled'] === 1 || !hash_equals((string)($_SESSION['pwf'] ?? ''), self::fingerprint($row))) {
            self::clearAuth();
            return null;
        }
        $_SESSION['last'] = $now;
        return self::$user = ['id' => (int)$row['id'], 'username' => $row['username'], 'display_name' => $row['display_name'], 'role' => $row['role'], 'mfa' => ($row['totp_secret'] ?? '') !== ''];
    }

    /**
     * Verify credentials (throttled). Returns the user on success, ['mfa_required' => true] when a one-time code must follow
     * (the session is then only "pending": Auth::user() stays null), or null on bad credentials.
     * @throws ResponseException 429 when throttled
     */
    public static function login(string $username, string $password, bool $remember = false): ?array
    {
        $win = (int)Config::get('limits.login_window');
        RateLimiter::enforce('login:ip:' . Request::ip(), (int)Config::get('limits.login_ip_attempts'), $win, 'Too many login attempts');
        $userKey = 'login:user:' . hash('sha256', strtolower($username));
        RateLimiter::enforce($userKey, (int)Config::get('limits.login_attempts'), $win, 'Too many login attempts');

        $db = Database::getInstance();
        $row = $db->fetchOne('SELECT id, username, display_name, password, totp_secret, role, disabled FROM `' . $db->t('users') . '` WHERE username = :u', ['u' => $username]);
        // Always run one password_verify so response time does not reveal whether the account exists.
        $hash = $row['password'] ?? self::dummyHash();
        $valid = password_verify($password, $hash) && $row !== null;
        if ($valid && (int)$row['disabled'] === 1) {
            Audit::log('login_disabled_account', (int)$row['id'], $row['username']);
            $valid = false;
        }
        if (!$valid) {
            // An unknown name is recorded as such: people sometimes type their password into the username box.
            Audit::log('login_failed', $row ? (int)$row['id'] : null, $row ? $row['username'] : '(unknown)');
            $n = (int)Config::get('alerts.login_failures');
            Alerts::watch('login_failures', $n, 600, 'login_failures', "$n failed sign-in attempts within 10 minutes (latest from " . Request::ip() . ').');
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
            $_SESSION['remember_pending'] = $remember;   // honoured only after the code is accepted
            $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
            Audit::log('login_password_ok', (int)$row['id'], $row['username'], 'second factor required');
            return ['mfa_required' => true];
        }
        return self::establish($row, 'login_ok', $remember);
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
        $row = $db->fetchOne('SELECT id, username, display_name, password, totp_secret, totp_last_step, role, disabled FROM `' . $db->t('users') . '` WHERE id = :id', ['id' => $uid]);
        if (!$row || (int)$row['disabled'] === 1 || ($row['totp_secret'] ?? '') === '') {
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
        $remember = !empty($_SESSION['remember_pending']);
        return self::establish($row, $how, $remember);
    }

    /** Create the authenticated session (fresh id, fresh CSRF token). */
    private static function establish(array $row, string $auditAction, bool $remember = false): array
    {
        self::startSession();
        session_regenerate_id(true);
        unset($_SESSION['mfa_uid'], $_SESSION['mfa_until'], $_SESSION['remember_pending']);
        $_SESSION['uid'] = (int)$row['id'];
        $_SESSION['created'] = $_SESSION['last'] = time();
        $_SESSION['pwf'] = self::fingerprint($row);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        self::$userChecked = true;
        Audit::log($auditAction, (int)$row['id'], $row['username']);
        if ($remember) { self::issueRemembered($row); }
        return self::$user = ['id' => (int)$row['id'], 'username' => $row['username'], 'display_name' => $row['display_name'], 'role' => $row['role'], 'mfa' => ($row['totp_secret'] ?? '') !== ''];
    }

    public static function logout(): void
    {
        self::startSession();
        if (!empty($_SESSION['uid'])) { Audit::log('logout'); }
        self::forgetThisDevice();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
        }
        session_destroy();
        self::$user = null;
        self::$userChecked = true;
    }

    /** Administrators must have two-step verification when the setting is on; until they do, only the account/MFA routes work. */
    public static function needsMfaEnrolment(): bool
    {
        $u = self::user();
        return $u !== null && $u['role'] === 'admin' && !$u['mfa'] && (bool)Config::get('security.require_mfa');
    }

    /** True when the signed-in user has at least this role (viewer < editor < admin). */
    public static function can(string $min): bool
    {
        $u = self::user();
        return $u !== null && (Accounts::ROLES[$u['role']] ?? 0) >= (Accounts::ROLES[$min] ?? 99);
    }

    public static function isAdmin(): bool
    {
        return self::can('admin');
    }

    /**
     * After the signed-in user's own password/MFA change: the new fingerprint would end this very session, so adopt it here (and issue a
     * fresh session id); every OTHER session of the account ends because its stored fingerprint no longer matches.
     */
    public static function adoptCredentialChange(): void
    {
        $db = Database::getInstance();
        $row = $db->fetchOne('SELECT password, totp_secret FROM `' . $db->t('users') . '` WHERE id = :id', ['id' => (int)($_SESSION['uid'] ?? 0)]);
        if ($row) {
            session_regenerate_id(true);
            $_SESSION['pwf'] = self::fingerprint($row);
            self::forgetAllDevices((int)$_SESSION['uid']);   // a credential change ends every remembered device, this one included
            self::clearRememberCookie();
        }
    }

    // ------------------------------------------------------------------ "keep me signed in"

    /** Remember this browser: a random secret in an HttpOnly cookie; only its SHA-256 is stored. Never for a password-only (MFA pending) sign-in. */
    private static function issueRemembered(array $row): void
    {
        $days = (int)Config::get('session.remember_days');
        if ($days <= 0) { return; }
        $selector = bin2hex(random_bytes(12)); $secret = bin2hex(random_bytes(32));
        $db = Database::getInstance();
        $db->insert('remember_tokens', ['user_id' => (int)$row['id'], 'selector' => $selector, 'validator_hash' => hash('sha256', $secret), 'pwf' => self::fingerprint($row),
            'user_agent' => mb_substr((string)Request::header('user-agent'), 0, 200), 'expires_at' => gmdate('Y-m-d H:i:s', time() + $days * 86400)]);
        self::setRememberCookie($selector . '.' . $secret, time() + $days * 86400);
    }

    /** No PHP session but a valid remember cookie: sign the person back in (fresh session id and CSRF token). Any doubt deletes the token. */
    private static function restoreRemembered(): ?array
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? null;
        if (!is_string($cookie)) { return null; }
        $days = (int)Config::get('session.remember_days');
        if ($days <= 0 || !preg_match('/^([a-f0-9]{24})\.([a-f0-9]{64})$/D', $cookie, $m)) { self::clearRememberCookie(); return null; }
        $db = Database::getInstance(); $t = $db->t('remember_tokens');
        $tok = $db->fetchOne("SELECT * FROM `$t` WHERE selector = :s", ['s' => $m[1]]);
        if (!$tok) { self::clearRememberCookie(); return null; }
        $drop = static function () use ($db, $t, $tok): ?array { $db->query("DELETE FROM `$t` WHERE id = :i", ['i' => (int)$tok['id']]); self::clearRememberCookie(); return null; };
        if (strtotime($tok['expires_at'] . ' UTC') < time() || strtotime($tok['created_at'] . ' UTC') < time() - self::REMEMBER_CEILING_DAYS * 86400) { return $drop(); }
        if (!hash_equals((string)$tok['validator_hash'], hash('sha256', $m[2]))) { return $drop(); }   // a wrong secret gets exactly one try
        $row = $db->fetchOne('SELECT id, username, display_name, password, totp_secret, role, disabled FROM `' . $db->t('users') . '` WHERE id = :i', ['i' => (int)$tok['user_id']]);
        if (!$row || (int)$row['disabled'] === 1 || !hash_equals((string)$tok['pwf'], self::fingerprint($row))) { return $drop(); }
        $user = self::establish($row, 'login_remembered');
        if (strtotime($tok['last_used_at'] . ' UTC') < time() - 12 * 3600) {   // sliding expiry, written at most twice a day
            $until = min(time() + $days * 86400, strtotime($tok['created_at'] . ' UTC') + self::REMEMBER_CEILING_DAYS * 86400);
            $db->query("UPDATE `$t` SET expires_at = :e, last_used_at = :n WHERE id = :i", ['e' => gmdate('Y-m-d H:i:s', $until), 'n' => gmdate('Y-m-d H:i:s'), 'i' => (int)$tok['id']]);
            self::setRememberCookie($cookie, $until);
        }
        return $user;
    }

    private static function forgetThisDevice(): void
    {
        $c = $_COOKIE[self::REMEMBER_COOKIE] ?? null;
        if (is_string($c) && preg_match('/^([a-f0-9]{24})\./', $c, $m)) {
            $db = Database::getInstance();
            $db->query('DELETE FROM `' . $db->t('remember_tokens') . '` WHERE selector = :s', ['s' => $m[1]]);
        }
        self::clearRememberCookie();
    }

    public static function forgetAllDevices(int $userId): int
    {
        $db = Database::getInstance();
        return $db->query('DELETE FROM `' . $db->t('remember_tokens') . '` WHERE user_id = :u', ['u' => $userId])->rowCount();
    }

    /** The signed-in person's remembered devices (never the secret). @return array<int,array<string,mixed>> */
    public static function devices(int $userId): array
    {
        $db = Database::getInstance();
        $mine = null;
        if (is_string($_COOKIE[self::REMEMBER_COOKIE] ?? null) && preg_match('/^([a-f0-9]{24})\./', $_COOKIE[self::REMEMBER_COOKIE], $m)) { $mine = $m[1]; }
        $out = [];
        foreach ($db->fetchAll('SELECT id, selector, user_agent, created_at, last_used_at, expires_at FROM `' . $db->t('remember_tokens') . '` WHERE user_id = :u AND expires_at > NOW() ORDER BY last_used_at DESC', ['u' => $userId]) as $r) {
            $out[] = ['id' => (int)$r['id'], 'browser' => $r['user_agent'] !== '' ? $r['user_agent'] : 'Unknown browser', 'created_at' => $r['created_at'], 'last_used_at' => $r['last_used_at'], 'expires_at' => $r['expires_at'], 'current' => $mine !== null && $mine === $r['selector']];
        }
        return $out;
    }

    /** @return int rows removed (0 when the id is not this person's) */
    public static function forgetDevice(int $userId, int $id): int
    {
        $db = Database::getInstance();
        return $db->query('DELETE FROM `' . $db->t('remember_tokens') . '` WHERE id = :i AND user_id = :u', ['i' => $id, 'u' => $userId])->rowCount();
    }

    /** Hourly housekeeping. @return int rows deleted */
    public static function purgeRemembered(): int
    {
        $db = Database::getInstance();
        return $db->query('DELETE FROM `' . $db->t('remember_tokens') . '` WHERE expires_at < NOW() OR created_at < NOW() - INTERVAL ' . self::REMEMBER_CEILING_DAYS . ' DAY')->rowCount();
    }

    private static function setRememberCookie(string $value, int $expires): void
    {
        if (PHP_SAPI === 'cli') { $_COOKIE[self::REMEMBER_COOKIE] = $value; return; }
        $secure = Config::get('session.secure');
        if ($secure === null) {
            $base = (string)Config::get('base_url', '');
            $secure = $base !== '' ? str_starts_with($base, 'https://') : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        }
        setcookie(self::REMEMBER_COOKIE, $value, ['expires' => $expires, 'path' => App::basePath() ?: '/', 'secure' => (bool)$secure, 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE[self::REMEMBER_COOKIE] = $value;
    }

    private static function clearRememberCookie(): void
    {
        unset($_COOKIE[self::REMEMBER_COOKIE]);
        if (PHP_SAPI === 'cli') { return; }
        setcookie(self::REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => App::basePath() ?: '/', 'httponly' => true, 'samesite' => 'Lax']);
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
