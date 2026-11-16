<?php
declare(strict_types=1);

/**
 * Prints docs/SETTINGS_REFERENCE.md from the settings registry (the single source of truth). A test fails if the committed file differs.
 *   php bin/settings-doc.php > docs/SETTINGS_REFERENCE.md
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/core/autoload.php';

use Sitewright\Core\Settings;

$fmt = static function (mixed $v): string {
    if (is_bool($v)) { return $v ? 'on' : 'off'; }
    if (is_array($v)) { return $v === [] ? '(empty)' : implode(', ', array_map('strval', $v)); }
    return $v === null || $v === '' ? '(empty)' : (string)$v;
};
$cell = static fn (string $s): string => str_replace(['|', "\n"], ['\\|', ' '], $s);

echo "# Settings reference\n\n";
echo "Generated from the settings registry by `php bin/settings-doc.php`; do not edit by hand. Everything here can be changed on the **Settings** page (admins only).\n";
echo "Precedence: environment variable > saved in Settings > `config/config.php` > built-in default. A setting with an environment variable is locked while that variable is set.\n\n";
$byGroup = [];
foreach (Settings::registry() as $key => $d) { $byGroup[$d['group']][$key] = $d; }
foreach (Settings::GROUPS as $id => [$label, $desc]) {
    if (empty($byGroup[$id])) { continue; }
    echo "## $label\n\n$desc\n\n| Setting | Key | Default | Allowed | Environment variable | Notes |\n|---|---|---|---|---|---|\n";
    foreach ($byGroup[$id] as $key => $d) {
        $allowed = match (true) {
            $d['enum'] !== null && in_array($d['type'], ['enum', 'list'], true) => implode(' / ', $d['enum']),
            $d['min'] !== null || $d['max'] !== null => ($d['min'] ?? '') . ' to ' . ($d['max'] ?? '') . ($d['unit'] !== '' ? ' ' . $d['unit'] : ''),
            default => $d['type'] . ($d['unit'] !== '' ? ' (' . $d['unit'] . ')' : ''),
        };
        $notes = trim($d['help'] . ($d['secret'] ? ' Secret: write-only, stored encrypted.' : '') . ($d['danger'] ? ' Needs confirmation: ' . implode(' ', $d['danger_effects']) : ''));
        echo '| ' . $cell($d['label']) . ' | `' . $key . '` | ' . $cell($d['secret'] ? '(secret)' : $fmt($d['default'])) . ' | ' . $cell($allowed) . ' | ' . ($d['env'] ? '`' . $d['env'] . '`' : '') . ' | ' . $cell($notes) . " |\n";
    }
    echo "\n";
}
echo "## Set on the server only (read-only in the app)\n\nThese cannot be changed from the Settings page: they are needed to start the app, or they control the request guard.\n\n";
foreach (Settings::readonlyKeys() as $k) { echo "* `$k`\n"; }
