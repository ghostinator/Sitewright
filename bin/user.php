<?php
declare(strict_types=1);

/**
 * Manage administrator accounts (CLI only).
 *   php bin/user.php list
 *   php bin/user.php create <username> <email>
 *   php bin/user.php passwd <username>
 * Password: SW_USER_PASSWORD env, --password-file=PATH, or an interactive prompt (typed input is visible).
 * Changing a password ends that user's existing sessions. Uses the runtime database account (INSERT/UPDATE are enough).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/core/autoload.php';

use Sitewright\Core\{Database, Installer};

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
        foreach ($db->fetchAll('SELECT id, username, email, created_at FROM `' . $db->t('users') . '` ORDER BY id') as $u) {
            echo "{$u['id']}\t{$u['username']}\t{$u['email']}\t{$u['created_at']}\n";
        }
    } elseif ($cmd === 'create' && isset($args[1], $args[2])) {
        $a = Installer::validateAdmin(['username' => $args[1], 'email' => $args[2], 'password' => $password()]);
        $db->insert('users', ['username' => $a['username'], 'email' => $a['email'], 'password' => password_hash($a['password'], PASSWORD_DEFAULT), 'display_name' => $a['username']]);
        echo "Created {$a['username']}\n";
    } elseif ($cmd === 'passwd' && isset($args[1])) {
        $pw = $password();
        Installer::validateAdmin(['username' => $args[1], 'email' => 'x@example.test', 'password' => $pw]);
        $n = $db->update('users', ['password' => password_hash($pw, PASSWORD_DEFAULT)], ['username' => $args[1]]);
        if ($n === 0) { fwrite(STDERR, "No such user (or password unchanged).\n"); exit(1); }
        echo "Password updated; existing sessions for {$args[1]} are now invalid.\n";
    } else {
        fwrite(STDERR, "Usage: php bin/user.php list | create <username> <email> | passwd <username>\n");
        exit(2);
    }
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n"); exit(1);
} catch (\Throwable $e) {
    error_log('user.php: ' . $e->getMessage());
    fwrite(STDERR, "Failed (see PHP error log; duplicate username/email or database problem).\n"); exit(1);
}
