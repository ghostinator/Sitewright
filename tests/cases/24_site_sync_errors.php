<?php
use Sitewright\Core\{Config, Crypto, JobFailedException, JobRetryException, SiteSync};

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb(); Config::set('app_key', Crypto::generateKey());
$mkSite = function () use ($db) { $db->query('TRUNCATE sw_sites'); $id = (int)$db->insert('sites', ['name' => 'P', 'base_url' => 'https://p.example', 'secret_enc' => 'x']);
    $db->update('sites', ['secret_enc' => Crypto::encrypt('s3cret', "site:$id")], ['id' => $id]); return $id; };
$wp = fn (int $status, string $code, string $message) => [$status, ['Content-Type' => 'application/json'], json_encode(['code' => $code, 'message' => $message, 'data' => ['status' => $status]])];
$URL = 'https://p.example/?rest_route=/sitewright/v1/status';

foreach ([
    'Bad signature' => [401, 'sitewright_denied', 'Bad signature', ['does not match', 'New secret', 'wp-config']],
    'Stale signature' => [401, 'sitewright_denied', 'Stale signature', ['clock', '5 minutes', 'UTC']],
    'Secret not set' => [403, 'sitewright_unconfigured', 'Secret not set', ['no secret', 'SITEWRIGHT_SECRET']],
] as $label => [$status, $code, $msg, $needles]) {
    test("site sync error: the plugin's \"$label\" is explained in words that point at the real cause", function () use ($db, $mkSite, $wp, $URL, $status, $code, $msg, $needles) {
        $id = $mkSite(); fakeWeb([$URL => $wp($status, $code, $msg)]);
        $e = throws(JobFailedException::class, fn () => SiteSync::handleJob(['site_id' => $id]));
        foreach ($needles as $n) { ok(stripos($e->getMessage(), $n) !== false, "message should mention '$n': " . $e->getMessage()); }
        eq($db->fetchOne('SELECT last_error FROM sw_sites WHERE id = :i', ['i' => $id])['last_error'], mb_substr($e->getMessage(), 0, 250));
    });
}
test('site sync error: a 401/403 that is not from the plugin (firewall, security plugin) says so; a replayed signature is retried', function () use ($mkSite, $URL, $wp) {
    $id = $mkSite(); fakeWeb([$URL => [403, ['Content-Type' => 'text/html'], '<html>Forbidden</html>']]);
    $e = throws(JobFailedException::class, fn () => SiteSync::handleJob(['site_id' => $id]));
    ok(stripos($e->getMessage(), 'firewall') !== false || stripos($e->getMessage(), 'security plugin') !== false, $e->getMessage());
    fakeWeb([$URL => $wp(401, 'sitewright_denied', 'Replay')]);
    throws(JobRetryException::class, fn () => SiteSync::handleJob(['site_id' => $id]));
});
test('a site (or Search Console) that cannot be synced is its own alert, not an app outage: failed sync jobs do not turn the health check red or raise a second job_failed alert', function () use ($db) {
    foreach (['jobs', 'rate_limits'] as $t) { $db->query("TRUNCATE sw_$t"); }
    Config::set('alerts', ['email_to' => [], 'teams_webhook' => 'https://example.webhook.office.com/x', 'telegram_token' => '', 'telegram_chat_id' => '', 'cooldown' => 0, 'login_failures' => 3, 'events' => ['job_failed', 'site_sync_failed']]);
    $c = testDbConfig(); $env = sprintf('SW_DB_HOST=%s SW_DB_NAME=%s SW_DB_USER=%s SW_DB_PASS=%s SW_LOG_FILE=/dev/null', escapeshellarg($c['host']), 'sw_test', escapeshellarg($c['user']), escapeshellarg($c['pass']));
    $run = fn () => (string)shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 2)) . " && $env php bin/healthcheck.php 2>&1; echo \"exit=\$?\"");
    $failed = fn (string $type, int $n) => $db->query("INSERT INTO sw_jobs (type, payload, status, finished_at, error) " . implode(' UNION ALL ', array_fill(0, $n, "SELECT '$type', '{}', 'failed', NOW(), 'x'")));
    @unlink(dirname(__DIR__, 2) . '/storage/health.state');
    $failed('site_sync', 6); $failed('gsc_sync', 6); $out = $run(); ok(str_contains($out, 'exit=0'), 'sync failures are not an outage: ' . $out);
    @unlink(dirname(__DIR__, 2) . '/storage/health.state'); $failed('crawl', 5); $out = $run(); ok(str_contains($out, 'exit=1') && str_contains($out, 'jobs failed'), 'but five failed crawls still are: ' . $out);
    @unlink(dirname(__DIR__, 2) . '/storage/health.state');
    // and a failing sync job raises site_sync_failed (its own alert) but no generic job_failed on top
    $db->query('TRUNCATE sw_jobs'); $db->query('TRUNCATE sw_sites'); $id = (int)$db->insert('sites', ['name' => 'P', 'base_url' => 'https://p.example', 'secret_enc' => 'x']);
    $db->update('sites', ['secret_enc' => Crypto::encrypt('s', "site:$id")], ['id' => $id]);
    fakeWeb(['https://p.example/?rest_route=/sitewright/v1/status' => [401, ['Content-Type' => 'application/json'], '{"message":"Bad signature"}']]);
    \Sitewright\Core\Jobs::enqueue('site_sync', ['site_id' => $id], null, 0, 1); while (\Sitewright\Core\Jobs::runNext()) {}
    $ev = array_map(fn ($r) => json_decode($r['payload'], true)['event'], $db->fetchAll("SELECT payload FROM sw_jobs WHERE type = 'alert' ORDER BY id"));
    eq($ev, ['site_sync_failed']);
});
