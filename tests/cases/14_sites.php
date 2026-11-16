<?php
use Sitewright\Core\{Config, ConfigException, Crypto, Jobs, SiteSync};

// ---- Crypto -----------------------------------------------------------------------------------------------------------------
test('crypto: round trip; bound to its context; tamper-evident; refuses to work without a valid key', function () {
    Config::set('app_key', '');
    ok(!Crypto::configured()); throws(ConfigException::class, fn () => Crypto::encrypt('x', 'c'));
    Config::set('app_key', 'not-base64-and-short'); ok(!Crypto::configured());
    Config::set('app_key', Crypto::generateKey()); ok(Crypto::configured());
    $c = Crypto::encrypt('top secret', 'site:1');
    eq(Crypto::decrypt($c, 'site:1'), 'top secret'); ok(!str_contains($c, 'top secret'));
    ok(Crypto::encrypt('top secret', 'site:1') !== $c, 'random nonce');
    throws(ConfigException::class, fn () => Crypto::decrypt($c, 'site:2'));                       // moved to another row
    $raw = base64_decode($c); $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] ^ "\x01";
    throws(ConfigException::class, fn () => Crypto::decrypt(base64_encode($raw), 'site:1'));      // flipped bit
    throws(ConfigException::class, fn () => Crypto::decrypt('AAAA', 'site:1'));
    $old = Config::get('app_key'); Config::set('app_key', Crypto::generateKey());
    throws(ConfigException::class, fn () => Crypto::decrypt($c, 'site:1'));                       // different key
    Config::set('app_key', $old);
});
test('signature: depends on every part (method, route, time, nonce, body, secret)', function () {
    $base = SiteSync::sign('s', 'GET', '/r', 100, 'n', '');
    foreach ([SiteSync::sign('x', 'GET', '/r', 100, 'n', ''), SiteSync::sign('s', 'POST', '/r', 100, 'n', ''), SiteSync::sign('s', 'GET', '/q', 100, 'n', ''),
              SiteSync::sign('s', 'GET', '/r', 101, 'n', ''), SiteSync::sign('s', 'GET', '/r', 100, 'm', ''), SiteSync::sign('s', 'GET', '/r', 100, 'n', 'b')] as $other) { ok($other !== $base); }
    eq(SiteSync::sign('s', 'get', '/r', 100, 'n', ''), $base, 'method case-insensitive');
});

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb();
seedAdmin('root', 'root passphrase long here', 'admin'); seedAdmin('ed', 'editor passphrase long here', 'editor');
$key = Crypto::generateKey();
$srv = startServer(['SW_APP_KEY' => $key]); $base = $srv['base'];
$nokey = startServer();
$as = function (string $u, string $p, string $b) { $h = new TestHttp($b); eq($h->login($u, $p)['status'], 200); return $h; };

test('sites API: needs app_key; secret shown once, never listed, stored encrypted; duplicates and bad URLs refused', function () use ($as, $base, $nokey, $db) {
    $a0 = $as('root', 'root passphrase long here', $nokey['base']);
    eq($a0->api('POST', '/api/sites', ['name' => 'x', 'base_url' => 'https://blog.example.com'])['status'], 409, 'without app_key');
    $a = $as('root', 'root passphrase long here', $base);
    $r = $a->api('POST', '/api/sites', ['name' => 'Blog', 'base_url' => 'https://blog.example.com/']);
    eq($r['status'], 201, $r['body']); $secret = $r['json']['data']['secret']; $id = $r['json']['data']['id'];
    ok(preg_match('/^[a-f0-9]{64}$/D', $secret) === 1);
    $row = $db->fetchOne('SELECT * FROM sw_sites WHERE id = :i', ['i' => $id]);
    eq($row['base_url'], 'https://blog.example.com'); ok(!str_contains(json_encode($row), $secret), 'plaintext secret is not in the database');
    $list = $a->api('GET', '/api/sites'); ok(!str_contains($list['body'], $secret) && !str_contains($list['body'], 'secret_enc'), 'secret never listed');
    eq($list['json']['data']['sites'][0]['name'], 'Blog'); eq($list['json']['data']['encryption_ready'], true);
    eq($a->api('POST', '/api/sites', ['name' => 'Again', 'base_url' => 'https://blog.example.com'])['status'], 409);
    foreach (['ftp://x.example', 'https://u:p@x.example', 'https://x.example/?a=1', 'javascript:alert(1)', 'https://127.0.0.1'] as $bad) {
        $s = $a->api('POST', '/api/sites', ['name' => 'Bad', 'base_url' => $bad])['status']; ok($s === 400 || $s === 201 && str_contains($bad, '127.0.0.1'), "$bad => $s");
    }
});
test('sites API: rotate gives a new secret; sync queues a job; delete keeps data; editors are refused everywhere', function () use ($as, $base, $db) {
    $a = $as('root', 'root passphrase long here', $base); $e = $as('ed', 'editor passphrase long here', $base);
    $id = (int)$db->fetchOne("SELECT id FROM sw_sites WHERE name='Blog'")['id'];
    $before = $db->fetchOne('SELECT secret_enc FROM sw_sites WHERE id = :i', ['i' => $id])['secret_enc'];
    $r = $a->api('POST', "/api/sites/$id/rotate-secret"); eq($r['status'], 200); ok(preg_match('/^[a-f0-9]{64}$/D', $r['json']['data']['secret']) === 1);
    ok($db->fetchOne('SELECT secret_enc FROM sw_sites WHERE id = :i', ['i' => $id])['secret_enc'] !== $before);
    $s = $a->api('POST', "/api/sites/$id/sync"); eq($s['status'], 202);
    eq($db->fetchOne("SELECT type FROM sw_jobs WHERE id = :i", ['i' => $s['json']['data']['job_id']])['type'], 'site_sync');
    foreach ([['GET', '/api/sites'], ['POST', '/api/sites'], ['GET', '/api/sites/plugin'], ['POST', "/api/sites/$id/sync"], ['DELETE', "/api/sites/$id"]] as [$m, $p]) { eq($e->api($m, $p, $m === 'GET' ? null : [])['status'], 403, "$m $p"); }
    $p = $a->api('GET', '/api/sites/plugin'); eq($p['status'], 200); ok(str_contains($p['body'], 'Plugin Name: Sitewright Agent') && str_contains($p['headers']['content-disposition'], 'attachment'));
    $db->query("INSERT INTO sw_404_monitor (project_id, uri) VALUES ($id, '/keep-me')");
    eq($a->api('DELETE', "/api/sites/$id")['status'], 200); eq((int)$db->fetchOne("SELECT COUNT(*) n FROM sw_404_monitor WHERE uri='/keep-me'")['n'], 1);
});
test('scheduler: due sites get exactly one queued sync; rule changes mark sites for an immediate push', function () use ($db) {
    $db->query('TRUNCATE sw_jobs'); $db->query('TRUNCATE sw_sites');
    Config::set('app_key', Crypto::generateKey());
    $db->query("INSERT INTO sw_sites (name, base_url, secret_enc, last_sync_at, last_status) VALUES ('fresh','https://a.example','x', NOW(), 'ok'), ('stale','https://b.example','x', NOW() - INTERVAL 1 HOUR, 'ok'), ('never','https://c.example','x', NULL, 'never')");
    eq(SiteSync::scheduleDue(), 2, 'stale + never');
    eq(SiteSync::scheduleDue(), 0, 'no duplicates while one is queued');
    $db->query("UPDATE sw_jobs SET status = 'done'"); $db->query("UPDATE sw_sites SET last_sync_at = NOW()");
    eq(SiteSync::scheduleDue(), 0);
    SiteSync::markDirty(); eq(SiteSync::scheduleDue(), 3, 'all marked');
    $db->query("UPDATE sw_jobs SET status='done'"); $db->query('UPDATE sw_sites SET sync_requested = 0, last_sync_at = NOW()');
    call(Sitewright\Modules\Redirections\RedirectionsController::class, 'add', ['method' => 'POST', 'body' => ['source_url' => '/s-a', 'target_url' => '/s-b']]);
    eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_sites WHERE sync_requested = 1')['n'], 3, 'adding a rule marks sites dirty');
});
test('sync job: unknown site fails cleanly; a secret that cannot be decrypted reports a readable error', function () use ($db) {
    throws(Sitewright\Core\JobFailedException::class, fn () => SiteSync::handleJob(['site_id' => 999999]));
    $db->query('TRUNCATE sw_sites'); $db->query('TRUNCATE sw_jobs');
    $id = (int)$db->insert('sites', ['name' => 'S', 'base_url' => 'https://s.example', 'secret_enc' => Crypto::encrypt('a', 'site:999')]);   // encrypted for a different row id
    try { SiteSync::handleJob(['site_id' => $id]); ok(false, 'should fail'); } catch (Sitewright\Core\JobFailedException $e) { ok(str_contains($e->getMessage(), 'cannot be decrypted') || str_contains($e->getMessage(), 'unreadable'), $e->getMessage()); }
    eq($db->fetchOne('SELECT last_status FROM sw_sites WHERE id = :i', ['i' => $id])['last_status'], 'error');
});
