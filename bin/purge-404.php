<?php
declare(strict_types=1);

/** Delete 404-log entries older than retention_days_404 (default 90). Suitable for cron. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/core/autoload.php';

echo 'Purged ' . (new RankMathWebapp\Modules\Monitor\MonitorController())->purgeExpired() . " rows\n";
