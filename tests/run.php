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

putenv('SW_BASE_URL'); putenv('SW_ALLOWED_HOSTS'); // deterministic config
Sitewright\Core\Config::useFile('/nonexistent/config.php');

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
