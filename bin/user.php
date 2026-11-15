<?php
declare(strict_types=1);

/**
 * Manage administrator accounts (CLI only).
 *   php bin/user.php list
 *   php bin/user.php create <username> <email> [--role=admin|editor|viewer]   (default admin: this is the operator's tool)
 *   php bin/user.php passwd <username>
 *   php bin/user.php mfa-enable <username> [--no-confirm]   TOTP: prints the secret, an otpauth:// URI and 8 recovery codes;
 *                                                            enabled only after you type a valid code (unless --no-confirm)
 *   php bin/user.php mfa-disable <username>
 * Password: SW_USER_PASSWORD env, --password-file=PATH, or an interactive prompt (typed input is visible).
 * Changing a password ends that user's existing sessions. Uses the runtime database account (INSERT/UPDATE are enough).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/core/autoload.php';

use Sitewright\Core\{Accounts, Audit, Database, Installer, Totp};

$args = array_values(array_filter(array_slice($argv, 1), static fn ($a) => !str_starts_with($a, '--')));
$opt = getopt('', ['password-file::']);
$cmd = $args[0] ?? '';
$password = static function () use ($opt): string {
    if (!empty($opt['password-file'])) { return rtrim((string)@file_get_contents($opt['password-file']), "\r\n"); }
    if (($e = getenv('SW_USER_PASSWORD')) !== false && $e !== '') { return $e; }
    if (function_exists('stream_isatty') && stream_isatty(STDIN)) { echo 'New password (min 12 chars, visible): '; return trim((string)fgets(STDIN)); }
    fwrite(STDERR, "Provide SW_USER_PASSWORD, --password-file or run from a terminal.\n");
    exit(2);
};

try {
    $db = Database::getInstance();
    if ($cmd === 'list') {
        foreach ($db->fetchAll('SELECT id, username, email, created_at, role, disabled, totp_secret IS NOT NULL AS mfa FROM `' . $db->t('users') . '` ORDER BY id') as $u) {
            echo "{$u['id']}\t{$u['username']}\t{$u['email']}\t{$u['created_at']}\t" . ($u['mfa'] ? 'mfa' : 'no-mfa') . "\t{$u['role']}" . ($u['disabled'] ? "\tdisabled" : '') . "\n";
        }
    } elseif ($cmd === 'create' && isset($args[1], $args[2])) {
        $a = Installer::validateAdmin(['username' => $args[1], 'email' => $args[2], 'password' => $password()]);
        $role = 'admin';
        foreach ($argv as $x) { if (str_starts_with($x, '--role=')) { $role = substr($x, 7); } }
        if (!Accounts::validRole($role)) { throw new \InvalidArgumentException('Role must be admin, editor or viewer'); }
        $db->insert('users', ['username' => $a['username'], 'email' => $a['email'], 'password' => password_hash($a['password'], PASSWORD_DEFAULT), 'display_name' => $a['username'], 'role' => $role]);
        Audit::log('user_created', null, '(cli)', $a['username']);
        echo "Created {$a['username']}\n";
    } elseif ($cmd === 'passwd' && isset($args[1])) {
        $pw = $password();
        Installer::validateAdmin(['username' => $args[1], 'email' => 'x@example.test', 'password' => $pw]);
        $n = $db->update('users', ['password' => password_hash($pw, PASSWORD_DEFAULT)], ['username' => $args[1]]);
        if ($n === 0) { fwrite(STDERR, "No such user (or password unchanged).\n"); exit(1); }
        Audit::log('password_changed', null, '(cli)', $args[1]);
        echo "Password updated; existing sessions for {$args[1]} are now invalid.\n";
    } elseif ($cmd === 'mfa-enable' && isset($args[1])) {
        $u = $db->fetchOne('SELECT id, username FROM `' . $db->t('users') . '` WHERE username = :u', ['u' => $args[1]]);
        if (!$u) { fwrite(STDERR, "No such user.\n"); exit(1); }
        $secret = Totp::generateSecret();
        $issuer = 'Sitewright';
        echo "Secret (base32): $secret\nAdd this URI to an authenticator app (or enter the secret by hand):\n" . Totp::uri($issuer, $u['username'], $secret) . "\n";
        if (!in_array('--no-confirm', $argv, true)) {
            if (!(function_exists('stream_isatty') && stream_isatty(STDIN))) { fwrite(STDERR, "Run from a terminal so a code can be confirmed (or pass --no-confirm).\n"); exit(2); }
            echo 'Current 6-digit code from the app: ';
            if (Totp::verify($secret, trim((string)fgets(STDIN)), time(), 0) === null) { fwrite(STDERR, "Code did not match; MFA NOT enabled.\n"); exit(1); }
        }
        $codes = Totp::newRecoveryCodes();
        $db->update('users', ['totp_secret' => $secret, 'totp_last_step' => 0], ['id' => (int)$u['id']]);
        $db->delete('recovery_codes', ['user_id' => (int)$u['id']]);
        foreach ($codes as $c) { $db->insert('recovery_codes', ['user_id' => (int)$u['id'], 'code_hash' => Totp::recoveryHash($c)]); }
        Audit::log('mfa_enabled', (int)$u['id'], $u['username'], 'via cli');
        echo "MFA enabled; existing sessions of {$u['username']} are now invalid.\nRecovery codes (each works once; store them offline, they are not shown again):\n  " . implode("\n  ", $codes) . "\n";
    } elseif ($cmd === 'mfa-disable' && isset($args[1])) {
        $u = $db->fetchOne('SELECT id, username FROM `' . $db->t('users') . '` WHERE username = :u', ['u' => $args[1]]);
        if (!$u) { fwrite(STDERR, "No such user.\n"); exit(1); }
        $db->query('UPDATE `' . $db->t('users') . '` SET totp_secret = NULL, totp_last_step = 0 WHERE id = :id', ['id' => (int)$u['id']]);
        $db->delete('recovery_codes', ['user_id' => (int)$u['id']]);
        Audit::log('mfa_disabled', (int)$u['id'], $u['username'], 'via cli');
        echo "MFA disabled for {$u['username']}.\n";
    } else {
        fwrite(STDERR, "Usage: php bin/user.php list | create <username> <email> | passwd <username> | mfa-enable <username> | mfa-disable <username>\n");
        exit(2);
    }
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n"); exit(1);
} catch (\Throwable $e) {
    error_log('user.php: ' . $e->getMessage());
    fwrite(STDERR, "Failed (see PHP error log; duplicate username/email or database problem).\n"); exit(1);
}
