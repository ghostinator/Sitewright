<?php
declare(strict_types=1);

/** Send a test alert to every configured channel right now and print the result per channel. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/core/autoload.php';

use Sitewright\Core\Alerts;

$channels = Alerts::channels();
if ($channels === []) { fwrite(STDERR, "No alert channel is configured (see docs/ALERTS_AND_LOGGING.md).\n"); exit(2); }
$res = Alerts::sendNow('test', 'This is a test alert from Sitewright (' . gmdate('c') . ').', 'info');
foreach ($res as $ch => $r) { echo str_pad($ch, 10) . $r . "\n"; }
exit(in_array('ok', $res, true) && count(array_unique($res)) === 1 ? 0 : 1);
