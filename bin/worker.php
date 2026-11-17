<?php
declare(strict_types=1);

/**
 * Background worker: runs queued jobs (crawls, image batches, e-mail, alerts) and hourly housekeeping.
 *   php bin/worker.php                run until stopped (systemd: Restart=always)
 *   php bin/worker.php --once         drain the queue, then exit (cron / tests)
 *   php bin/worker.php --max-jobs=N   exit after N jobs (lets systemd recycle the process)
 * Run it as the same user and under the same egress firewall as the web server: crawls fetch third-party sites.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/core/autoload.php';

use Sitewright\Core\{Jobs, Logger};

$opt = getopt('', ['once', 'max-jobs::', 'sleep::']);
$once = isset($opt['once']); $max = (int)($opt['max-jobs'] ?? 0); $sleep = max(1, (int)($opt['sleep'] ?? 2));
$stop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $sig) { pcntl_signal($sig, static function () use (&$stop): void { $stop = true; }); }
}
Logger::info('worker_start', ['once' => $once]);
$done = 0; $lastMaint = 0; $lastSched = 0;
while (!$stop) {
    try {
        Jobs::heartbeat();
        if (!$once && time() - $lastSched >= 60) { $lastSched = time(); \Sitewright\Core\SiteSync::scheduleDue(); \Sitewright\Core\Gsc::scheduleDue(); \Sitewright\Core\Watchlist::scheduleDue(); \Sitewright\Core\SiteAudit::scheduleDue(); }
        if (!$once && time() - $lastMaint > 3600) { $lastMaint = time(); Logger::info('maintenance', Jobs::maintenance()); }
        if (Jobs::runNext()) {
            $done++;
            if ($max > 0 && $done >= $max) { break; }
            continue;
        }
    } catch (\Throwable $e) {
        Logger::error('worker_error', ['exception' => get_class($e), 'error' => $e->getMessage()]);
        if ($once) { exit(1); }
    }
    if ($once) { break; }
    sleep($sleep);
}
Logger::info('worker_stop', ['jobs' => $done]);
