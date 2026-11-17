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
