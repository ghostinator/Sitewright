<?php
declare(strict_types=1);

/** Print the newest audit-log rows: php bin/audit.php [count=50] */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/core/autoload.php';

use Sitewright\Core\Database;

$n = max(1, min(1000, (int)($argv[1] ?? 50)));
$db = Database::getInstance();
foreach ($db->fetchAll('SELECT created_at, username, action, ip, detail FROM `' . $db->t('audit_log') . '` ORDER BY id DESC LIMIT :n', ['n' => $n]) as $r) {
    echo implode("\t", [$r['created_at'], $r['username'] ?: '-', $r['action'], $r['ip'], $r['detail']]), "\n";
}
