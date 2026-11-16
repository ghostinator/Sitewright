<?php
declare(strict_types=1);

/**
 * Used by deploy/sw-set-secrets: given the JSON patch it is about to write to config.php, warn about every key that ALSO has a saved value
 * on the Settings page (the saved value wins over the file, so the file change would silently do nothing).
 *   php bin/settings-check.php '{"mail":{"postal_key":"x"}}'   -> prints one line per shadowed key; exit 0
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/core/autoload.php';

use Sitewright\Core\{Config, Settings};

$flatten = static function (array $a, string $p = '') use (&$flatten): array {
    $out = [];
    foreach ($a as $k => $v) { $key = $p === '' ? (string)$k : "$p.$k"; if (is_array($v) && $v !== [] && array_keys($v) !== range(0, count($v) - 1)) { $out = array_merge($out, $flatten($v, $key)); } else { $out[] = $key; } }
    return $out;
};
$patch = json_decode((string)($argv[1] ?? ''), true);
if (!is_array($patch)) { exit(0); }
try {
    $saved = Config::savedKeys();
} catch (\Throwable) {
    exit(0);   // no database / no settings table: nothing can be shadowed
}
foreach ($flatten($patch) as $key) {
    if (in_array($key, $saved, true)) { echo "WARNING: $key also has a value saved under Settings, which takes precedence over config.php. Reset it there (Settings, Reset) or this change will have no effect.\n"; }
    if (Settings::envLocked($key)) { echo "WARNING: $key is set by the environment variable " . Settings::def($key)['env'] . ", which takes precedence over config.php.\n"; }
}
