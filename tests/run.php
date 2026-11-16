<?php
declare(strict_types=1);

// Plain-PHP test runner (no Composer needed). Usage: php tests/run.php [file-substring]
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) { return false; }
    throw new ErrorException($str, 0, $no, $file, $line);
});

require __DIR__ . '/../core/autoload.php';
require __DIR__ . '/lib.php';

// Hermetic: a test run must never see the machine's real configuration or secrets (on a configured server it would otherwise send real
// e-mail / alerts). Remove every SW_* variable except the test database settings, and pin the config file to a path that does not exist.
foreach (array_keys(getenv()) as $name) { if (str_starts_with((string)$name, 'SW_') && !str_starts_with((string)$name, 'SW_TEST_')) { putenv((string)$name); } }
putenv('SW_CONFIG_FILE=/nonexistent/config.php');
Sitewright\Core\Config::useFile('/nonexistent/config.php');
Sitewright\Core\Config::set('log.file', '/dev/null'); // keep test output readable (tests that check logging set their own file)

$filter = $argv[1] ?? null;
foreach (glob(__DIR__ . '/cases/*.php') ?: [] as $file) {
    if ($filter !== null && !str_contains($file, $filter)) { continue; }
    echo '# ' . basename($file) . "\n";
    require $file;
}

$t = $GLOBALS['__t'];
echo "\nPASS: {$t['pass']}  FAIL: {$t['fail']}\n";
if ($t['fail']) { echo "Failed: " . implode('; ', $t['failures']) . "\n"; }
exit($t['fail'] ? 1 : 0);
