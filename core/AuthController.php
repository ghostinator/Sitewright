<?php
declare(strict_types=1);

namespace Sitewright\Core;

final class AuthController
{
    /** Public, minimal, no version/time/host details. */
    public function health(array $params): never
    {
        Response::success('ok');
    }

    public function login(array $params): never
    {
        $in = Request::json();
        $username = Validator::str($in, 'username', 60, true);
        $password = $in['password'] ?? null;
        if (!is_string($password) || $password === '' || strlen($password) > 1024) {
            Response::error('Username and password are required', 400);
        }
        $user = Auth::login($username, $password, filter_var($in['remember'] ?? false, FILTER_VALIDATE_BOOLEAN));
        if ($user === null) {
            Response::error('Invalid username or password', 401);
        }
        if (isset($user['mfa_required'])) {
            Response::success('Verification code required', ['mfa_required' => true]);
        }
        Response::success('Logged in', ['user' => $user, 'csrf_token' => Auth::csrfToken()]);
    }

    public function mfa(array $params): never
    {
        $in = Request::json();
        $code = Validator::str($in, 'code', 32, true);
        $user = Auth::completeMfa($code);
        if ($user === null) {
            Response::error('Invalid verification code', 401);
        }
        Response::success('Logged in', ['user' => $user, 'csrf_token' => Auth::csrfToken()]);
    }

    /** Remembered devices of the signed-in person. */
    public function devices(array $params): never
    {
        Response::success('Devices', ['devices' => Auth::devices((int)Auth::user()['id'])]);
    }

    public function forgetDevice(array $params): never
    {
        $n = Auth::forgetDevice((int)Auth::user()['id'], Validator::id($params['id'] ?? null));
        $n === 1 ? Audit::log('device_forgotten') : Response::notFound('Device not found');
        Response::success('Device forgotten');
    }

    public function forgetAllDevices(array $params): never
    {
        Audit::log('devices_forgotten', null, '', (string)Auth::forgetAllDevices((int)Auth::user()['id']) . ' device(s)');
        Response::success('All remembered devices were forgotten');
    }

    public function logout(array $params): never
    {
        Auth::logout();
        Response::success('Logged out');
    }

    public function me(array $params): never
    {
        $u = Auth::user();
        $db = Database::getInstance();
        $mfa = $u ? ($db->fetchOne('SELECT totp_secret IS NOT NULL AS m FROM `' . $db->t('users') . '` WHERE id = :i', ['i' => $u['id']])['m'] ?? 0) : 0;
        Response::success('ok', ['user' => $u, 'mfa_enabled' => (bool)$mfa, 'mfa_required' => Auth::needsMfaEnrolment(), 'csrf_token' => Auth::csrfToken()]);
    }

    // ---------------------------------------------------------------- password reset (public)

    /** Always the same answer, whether or not the address belongs to an account (no account enumeration). */
    public function forgot(array $params): never
    {
        $in = Request::json();
        $email = strtolower(Validator::str($in, 'email', 100, true));
        RateLimiter::enforce('forgot:ip:' . Request::ip(), 5, 900, 'Too many requests');
        RateLimiter::enforce('forgot:email:' . hash('sha256', $email), 3, 3600, 'Too many requests');
        $db = Database::getInstance();
        $u = $db->fetchOne('SELECT id, username, email, disabled FROM `' . $db->t('users') . '` WHERE email = :e', ['e' => $email]);
        if ($u && (int)$u['disabled'] === 0) {
            Accounts::sendLink($u, 'reset');
            Audit::log('password_reset_requested', (int)$u['id'], $u['username']);
        } else {
            Audit::log('password_reset_requested', null, '(unknown)');
        }
        Response::success('If that address belongs to an account, a reset link has been sent.');
    }

    public function reset(array $params): never
    {
        $in = Request::json();
        RateLimiter::enforce('reset:ip:' . Request::ip(), 10, 900, 'Too many attempts');
        $token = Validator::str($in, 'token', 100, true);
        $new = $in['password'] ?? null;
        if (!is_string($new)) { Response::error('A new password is required', 400); }
        try { Accounts::validatePassword($new); } catch (\InvalidArgumentException $e) { Response::error($e->getMessage(), 400); }
        $u = Accounts::consumeToken($token);
        if ($u === null || (int)$u['disabled'] === 1) {
            Response::error('This link is invalid or has expired. Request a new one.', 400);
        }
        $db = Database::getInstance();
        // Changing the password changes the session fingerprint, which ends every existing session of the account.
        // MFA is untouched: a reset never lets someone past the second factor.
        $db->update('users', ['password' => password_hash($new, PASSWORD_DEFAULT)], ['id' => (int)$u['id']]);
        Audit::log('password_reset_completed', (int)$u['id'], $u['username']);
        Mailer::queue((string)$u['email'], 'Your Sitewright password was changed', "The password for {$u['username']} was just changed using a reset link. If this was not you, contact an administrator immediately.\n");
        Response::success('Password changed. You can sign in now.');
    }

    // ---------------------------------------------------------------- own account (signed in)

    public function changePassword(array $params): never
    {
        $in = Request::json();
        $u = Auth::user();
        RateLimiter::enforce('chpw:' . $u['id'], 5, 900, 'Too many attempts');
        $current = $in['current_password'] ?? null; $new = $in['new_password'] ?? null;
        if (!is_string($current) || !is_string($new)) { Response::error('Current and new password are required', 400); }
        try { Accounts::validatePassword($new); } catch (\InvalidArgumentException $e) { Response::error($e->getMessage(), 400); }
        $db = Database::getInstance();
        $row = $db->fetchOne('SELECT password FROM `' . $db->t('users') . '` WHERE id = :i', ['i' => $u['id']]);
        if (!$row || !password_verify($current, $row['password'])) { Response::error('Current password is incorrect', 403); }
        $db->update('users', ['password' => password_hash($new, PASSWORD_DEFAULT)], ['id' => $u['id']]);
        Auth::adoptCredentialChange();
        Audit::log('password_changed', $u['id'], $u['username'], 'self-service');
        Response::success('Password changed; other sessions were signed out.', ['csrf_token' => Auth::csrfToken()]);
    }

    /** Step 1 of MFA enrolment: needs the current password; returns a fresh secret that is NOT active until a code proves it works. */
    public function mfaSetup(array $params): never
    {
        $u = Auth::user();
        $this->requirePassword($u['id'], $in = Request::json());
        $db = Database::getInstance();
        if ($db->fetchOne('SELECT totp_secret FROM `' . $db->t('users') . '` WHERE id = :i', ['i' => $u['id']])['totp_secret'] !== null) { Response::error('MFA is already enabled; disable it first to re-enrol', 409); }
        $secret = Totp::generateSecret();
        $_SESSION['mfa_enrol'] = ['secret' => $secret, 'until' => time() + 600];
        Response::success('Scan or enter this secret in your authenticator app, then confirm with a code.', ['secret' => $secret, 'uri' => Totp::uri('Sitewright', $u['username'], $secret)]);
    }

    public function mfaEnable(array $params): never
    {
        $u = Auth::user();
        $in = Request::json();
        RateLimiter::enforce('mfa-enrol:' . $u['id'], 10, 900, 'Too many attempts');
        $p = $_SESSION['mfa_enrol'] ?? null;
        if (!is_array($p) || time() > (int)$p['until']) { Response::error('Start again: the set-up expired', 400); }
        if (Totp::verify((string)$p['secret'], Validator::str($in, 'code', 12, true), time(), 0) === null) { Response::error('That code is not valid. Check the time on your phone and try again.', 400); }
        $db = Database::getInstance();
        $codes = Totp::newRecoveryCodes();
        $db->update('users', ['totp_secret' => $p['secret'], 'totp_last_step' => 0], ['id' => $u['id']]);
        $db->delete('recovery_codes', ['user_id' => $u['id']]);
        foreach ($codes as $c) { $db->insert('recovery_codes', ['user_id' => $u['id'], 'code_hash' => Totp::recoveryHash($c)]); }
        unset($_SESSION['mfa_enrol']);
        Auth::adoptCredentialChange();
        Audit::log('mfa_enabled', $u['id'], $u['username'], 'self-service');
        Response::success('MFA is on. Save these recovery codes now; they are not shown again.', ['recovery_codes' => $codes, 'csrf_token' => Auth::csrfToken()]);
    }

    public function mfaDisable(array $params): never
    {
        $u = Auth::user();
        $this->requirePassword($u['id'], Request::json());
        $db = Database::getInstance();
        $db->query('UPDATE `' . $db->t('users') . '` SET totp_secret = NULL, totp_last_step = 0 WHERE id = :i', ['i' => $u['id']]);
        $db->delete('recovery_codes', ['user_id' => $u['id']]);
        Auth::adoptCredentialChange();
        Audit::log('mfa_disabled', $u['id'], $u['username'], 'self-service');
        Alerts::notify('mfa_disabled', "MFA was switched off for {$u['username']}.", 'warning', $u['username']);
        Response::success('MFA is off.', ['csrf_token' => Auth::csrfToken()]);
    }

    private function requirePassword(int $userId, array $in): void
    {
        RateLimiter::enforce('reauth:' . $userId, 5, 900, 'Too many attempts');
        $pw = $in['password'] ?? null;
        $db = Database::getInstance();
        $row = $db->fetchOne('SELECT password FROM `' . $db->t('users') . '` WHERE id = :i', ['i' => $userId]);
        if (!is_string($pw) || !$row || !password_verify($pw, $row['password'])) { Response::error('Password is incorrect', 403); }
    }
}
