<?php
declare(strict_types=1);

/**
 * CLI installer: creates the schema and the first administrator, writes config/config.php (0600).
 *
 *   php bin/install.php --db-host=127.0.0.1 --db-name=sitewright --db-user=sw_admin --admin-user=admin --admin-email=you@example.com [--create-db] [--db-prefix=sw_]
 *
 * Secrets (never pass them as command-line arguments, they would show up in `ps`):
 *   SW_DB_PASS / --db-pass-file=PATH            database password
 *   SW_ADMIN_PASSWORD / --admin-password-file=PATH   first admin password (min 12 chars)
 * Anything missing is asked for interactively when run from a terminal (typed input is visible).
 * Use a DB account that may CREATE/ALTER tables for this step; run the web app with a SELECT/INSERT/UPDATE/DELETE-only account.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (version_compare(PHP_VERSION, '8.1.0', '<')) { fwrite(STDERR, 'PHP 8.1 or newer is required (found ' . PHP_VERSION . ").\n"); exit(1); }
foreach (['pdo_mysql', 'curl', 'mbstring', 'dom', 'json'] as $ext) {
    if (!extension_loaded($ext)) { fwrite(STDERR, "Missing required PHP extension: $ext\n"); exit(1); }
}
require dirname(__DIR__) . '/core/autoload.php';

use Sitewright\Core\Installer;

$opt = getopt('', ['db-host::', 'db-port::', 'db-name::', 'db-user::', 'db-prefix::', 'db-pass-file::', 'admin-user::', 'admin-email::', 'admin-password-file::', 'create-db']);
$tty = function_exists('stream_isatty') && stream_isatty(STDIN);
$ask = static function (string $label, ?string $default = null) use ($tty): string {
    if (!$tty) { fwrite(STDERR, "Missing required value: $label\n"); exit(2); }
    echo $label . ($default !== null ? " [$default]" : '') . ': ';
    $v = trim((string)fgets(STDIN));
    return $v === '' && $default !== null ? $default : $v;
};
$fromFile = static function (?string $path): ?string {
    if ($path === null || $path === false) { return null; }
    $c = is_readable($path) ? file_get_contents($path) : false;
    if ($c === false) { fwrite(STDERR, "Cannot read $path\n"); exit(2); }
    return rtrim($c, "\r\n");
};
$val = static fn (string $k, string $env) => $opt[$k] ?? (getenv($env) !== false && getenv($env) !== '' ? getenv($env) : null);

$dbPass = $fromFile($opt['db-pass-file'] ?? null) ?? (getenv('SW_DB_PASS') !== false ? getenv('SW_DB_PASS') : null) ?? $ask('Database password (visible)', '');
$adminPass = $fromFile($opt['admin-password-file'] ?? null) ?? (getenv('SW_ADMIN_PASSWORD') ?: null) ?? $ask('Admin password (min 12 chars, visible)');

try {
    $result = Installer::run([
        'create_db' => isset($opt['create-db']),
        'db' => [
            'host' => $val('db-host', 'SW_DB_HOST') ?? $ask('Database host', '127.0.0.1'),
            'port' => $val('db-port', 'SW_DB_PORT') ?? '3306',
            'name' => $val('db-name', 'SW_DB_NAME') ?? $ask('Database name', 'sitewright_webapp'),
            'user' => $val('db-user', 'SW_DB_USER') ?? $ask('Database user'),
            'pass' => $dbPass,
            'prefix' => $val('db-prefix', 'SW_DB_PREFIX') ?? 'sw_',
        ],
        'admin' => [
            'username' => $opt['admin-user'] ?? $ask('Admin username'),
            'email' => $opt['admin-email'] ?? $ask('Admin email'),
            'password' => $adminPass,
        ],
    ]);
} catch (\InvalidArgumentException | \RuntimeException $e) {
    fwrite(STDERR, 'Install failed: ' . $e->getMessage() . "\n");
    exit(1);
} catch (\Throwable $e) {
    error_log('install: ' . $e->getMessage());
    fwrite(STDERR, "Install failed (database error - check host/credentials/privileges; details in the PHP error log).\n");
    exit(1);
}
echo "Installed. Migrations applied: " . (implode(', ', $result['applied']) ?: 'none') . "\nConfig written to {$result['config']} (mode 0600).\n"
   . "Next: point your web server's document root at public/, set SW_BASE_URL / SW_ALLOWED_HOSTS, then sign in.\n";
