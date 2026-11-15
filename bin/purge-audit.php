<?php
declare(strict_types=1);

/** Delete audit_log rows older than retention_days_audit (default 365). Suitable for cron. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/core/autoload.php';

echo 'Purged ' . Sitewright\Core\Audit::purge() . " audit rows\n";
