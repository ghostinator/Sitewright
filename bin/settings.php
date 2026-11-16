<?php
declare(strict_types=1);

/**
 * Break-glass for the Settings page, usable when nobody can sign in (for example a sign-in limit set too strictly).
 *   php bin/settings.php list              saved settings (secrets are never printed)
 *   php bin/settings.php reset <key>       remove ONE saved value (the setting falls back to config file / built-in default)
 *   php bin/settings.php reset-all         remove every saved value
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/core/autoload.php';

use Sitewright\Core\{Audit, Database, Settings};

$db = Database::getInstance();
$t = '`' . $db->t('settings') . '`';
$cmd = $argv[1] ?? '';
if ($cmd === 'list') {
    foreach ($db->fetchAll("SELECT skey, svalue, is_secret, updated_at FROM $t ORDER BY skey") as $r) {
        echo $r['skey'] . "\t" . ((int)$r['is_secret'] === 1 ? ($r['svalue'] === '' ? '(cleared)' : '(secret)') : $r['svalue']) . "\t" . $r['updated_at'] . "\n";
    }
} elseif ($cmd === 'reset' && isset($argv[2])) {
    if (Settings::def($argv[2]) === null) { fwrite(STDERR, "Unknown setting {$argv[2]}\n"); exit(1); }
    Settings::reset($argv[2], 0);
    Audit::log('setting_reset', null, '(cli)', $argv[2]);
    echo "Reset {$argv[2]}\n";
} elseif ($cmd === 'reset-all') {
    $n = $db->query("DELETE FROM $t")->rowCount();
    Audit::log('settings_reset_all', null, '(cli)', "$n rows");
    echo "Removed $n saved settings\n";
} else {
    fwrite(STDERR, "Usage: php bin/settings.php list | reset <key> | reset-all\n");
    exit(2);
}
