<?php
declare(strict_types=1);

/**
 * Operational health probe for cron / a systemd timer / Uptime Kuma ("push" or command monitor). Exit 0 = healthy, 1 = unhealthy.
 * Alerts on a state CHANGE only (healthy -> unhealthy, and back), sent directly (not via the job queue, which may be what is broken).
 *   * * * * *  www-data  php /srv/sitewright/bin/healthcheck.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/core/autoload.php';

use Sitewright\Core\{Alerts, Config, Database, Health, Jobs};

$problems = [];
try {
    $db = Database::getInstance();
    $db->fetchOne('SELECT 1');
    $age = Jobs::workerAge();
    $t = $db->t('jobs');
    $stuck = (int)$db->fetchOne("SELECT COUNT(*) n FROM `$t` WHERE status = 'queued' AND run_after < NOW() - INTERVAL 300 SECOND")['n'];
    if ($stuck > 0 && ($age === null || $age > 180)) { $problems[] = "worker is not running: $stuck job(s) waiting for more than 5 minutes"; }
    elseif ($stuck > 0) { $problems[] = "queue backlog: $stuck job(s) waiting for more than 5 minutes"; }
    // A site or Search Console that cannot be synced is its own alert (site_sync_failed / gsc_sync_failed) and is the owner's to fix: it is not an outage of this app.
    $failed = (int)$db->fetchOne("SELECT COUNT(*) n FROM `$t` WHERE status = 'failed' AND finished_at > NOW() - INTERVAL 1 HOUR AND type NOT IN ('mail','alert','site_sync','gsc_sync','site_profile','site_expiry')")['n'];
    if ($failed >= 5) { $problems[] = "$failed jobs failed in the last hour"; }
} catch (\Throwable $e) {
    $problems[] = 'database unreachable';
}
$free = @disk_free_space(Config::root() . '/storage'); $total = @disk_total_space(Config::root() . '/storage');
if ($free !== false && $total > 0 && $free / $total < 0.05) { $problems[] = 'less than 5% disk space free'; }

// Nightly backup (deploy/sitewright-backup.timer): only checked where SW_BACKUP_DIR is set. Pre-deploy copies with other names do not count.
$bdir = (string)getenv('SW_BACKUP_DIR');
if ($bdir !== '') {
    $newest = 0;
    foreach (glob(rtrim($bdir, '/') . '/sitewright-*.sql.gz') ?: [] as $f) { $newest = max($newest, (int)@filemtime($f)); }
    if ($newest === 0) { $problems[] = 'no database backup found in the backup directory'; }
    elseif (time() - $newest > 36 * 3600) { $problems[] = 'newest database backup is ' . intdiv(time() - $newest, 3600) . ' hours old'; }
}

$t = Health::record($problems === [])['transition'];
if ($t === 'down') {
    echo 'alert: ' . json_encode(Alerts::notifyDirect('health_down', implode('; ', $problems), 'error')) . "\n";
} elseif ($t === 'recovered') {
    echo 'alert: ' . json_encode(Alerts::notifyDirect('health_recovered', 'All checks are passing again.', 'info')) . "\n";
}
echo $problems === [] ? "ok\n" : 'PROBLEMS: ' . implode('; ', $problems) . "\n";
exit($problems === [] ? 0 : 1);
