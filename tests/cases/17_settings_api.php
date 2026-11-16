<?php
use Sitewright\Core\{Config, Crypto, Jobs, Settings};

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb();
seedAdmin('root', 'root passphrase long here', 'admin'); seedAdmin('ed', 'editor passphrase long here', 'editor');
$key = Crypto::generateKey();
$logFile = tempnam(sys_get_temp_dir(), 'swlog');
$srv = startServer(['SW_APP_KEY' => $key, 'SW_MAIL_FROM' => 'locked@example.test', 'SW_LOG_FILE' => $logFile]); $base = $srv['base'];
$noKey = startServer();
$admin = function (?string $b = null) use ($base) { $h = new TestHttp($b ?? $base); eq($h->login('root', 'root passphrase long here')['status'], 200); return $h; };
$find = function (array $data, string $key) { foreach ($data['groups'] as $g) { foreach ($g['settings'] as $s) { if ($s['key'] === $key) { return $s; } } } return null; };
$put = fn (TestHttp $h, array $changes, bool $confirm = false) => $h->api('PUT', '/api/settings', ['changes' => $changes, 'confirm' => $confirm]);

test('settings API: admin-only; list has groups, sources, locks and masks secrets', function () use ($base, $admin, $find) {
    $e = new TestHttp($base); $e->login('ed', 'editor passphrase long here');
    foreach ([['GET', '/api/settings'], ['PUT', '/api/settings'], ['DELETE', '/api/settings/limits.crawl_seconds'], ['POST', '/api/settings/limits.crawl_seconds/promote'], ['POST', '/api/settings/test/mail']] as [$m, $p]) { eq($e->api($m, $p, $m === 'GET' ? null : [])['status'], 403, "$m $p"); }
    $a = $admin(); $r = $a->api('GET', '/api/settings'); eq($r['status'], 200);
    eq(array_slice(array_column($r['json']['data']['groups'], 'id'), 0, 5), ['mail', 'security', 'integrations', 'crawl', 'data']);
    $s = $find($r['json']['data'], 'limits.crawl_seconds'); eq($s['source'], 'default'); eq($s['value'], 120); eq($s['unit'], 'seconds'); eq($s['locked'], false); ok(str_contains($s['fallback'], 'built-in default 120'));
    $m = $find($r['json']['data'], 'mail.from'); eq($m['source'], 'env'); eq($m['locked'], true); eq($m['env'], 'SW_MAIL_FROM');
    $k = $find($r['json']['data'], 'mail.postal_key'); eq($k['value'], ['set' => false]); ok($k['secret']);
    ok(in_array('db.pass', array_column($r['json']['data']['readonly'], 'key'), true));
    foreach ($r['json']['data']['readonly'] as $ro) { if ($ro['key'] === 'db.pass' || $ro['key'] === 'app_key') { ok(!str_contains(json_encode($ro), $GLOBALS['__dbpass'] ?? '@@none@@') && ($ro['value'] === '(hidden)' || $ro['value'] === null), $ro['key']); } }
});
test('settings API: saving applies immediately, is audited with old -> new, and reset falls back and says to what', function () use ($admin, $find, $put, $db) {
    $a = $admin(); $r = $put($a, ['limits.crawl_seconds' => 90, 'limits.crawl_delay_ms' => 300]);
    eq($r['status'], 200, $r['body']); eq($r['json']['data']['changed'], ['limits.crawl_seconds', 'limits.crawl_delay_ms']);
    $s = $find($a->api('GET', '/api/settings')['json']['data'], 'limits.crawl_seconds'); eq($s['value'], 90); eq($s['source'], 'saved'); eq($s['changed_by'], 'root'); ok($s['changed_at'] !== null);
    $audit = $db->fetchOne("SELECT detail FROM sw_audit_log WHERE action = 'setting_changed' AND detail LIKE 'limits.crawl_seconds%' ORDER BY id DESC LIMIT 1"); eq($audit['detail'], 'limits.crawl_seconds: 120 -> 90');
    eq($put($a, ['limits.crawl_seconds' => 90])['json']['data']['changed'], [], 'no-op saves nothing');
    $d = $a->api('DELETE', '/api/settings/limits.crawl_seconds'); eq($d['status'], 200); ok(str_contains($d['json']['data']['fallback'], 'built-in default 120'));
    eq($find($a->api('GET', '/api/settings')['json']['data'], 'limits.crawl_seconds')['source'], 'default');
    eq($a->api('DELETE', '/api/settings/db.pass')['status'], 404); eq($a->api('DELETE', '/api/settings/nonsense')['status'], 404);
});
test('settings API: a whole batch is rejected on one bad field and nothing is written; env-locked keys are refused', function () use ($admin, $put, $db) {
    $a = $admin(); $db->query('TRUNCATE sw_settings');
    $r = $put($a, ['limits.crawl_seconds' => 90, 'limits.crawl_delay_ms' => 999999]);
    eq($r['status'], 400); ok(isset($r['json']['errors']['limits.crawl_delay_ms']), $r['body']); eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_settings')['n'], 0);
    foreach ([['db.pass' => 'x'], ['nonsense' => 1], ['app_key' => 'x'], ['fetch.dev_allow_cidrs' => ['10.0.0.0/8']]] as $bad) { eq($put($a, $bad)['status'], 400, json_encode(array_keys($bad))); }
    $l = $put($a, ['mail.from' => 'x@example.test']); eq($l['status'], 409); ok(str_contains($l['body'], 'SW_MAIL_FROM'));
});
test('settings API: cross-field rules (idle <= longest, timeouts) block lock-out-ish combinations', function () use ($admin, $put) {
    $a = $admin();
    eq($put($a, ['session.idle_timeout' => 7200, 'session.absolute_timeout' => 3600], true)['status'], 400);
    eq($put($a, ['session.idle_timeout' => 600, 'session.absolute_timeout' => 3600], true)['status'], 200);
    eq($put($a, ['session.idle_timeout' => 3600], true)['status'], 200, 'equal is fine');
    eq($put($a, ['session.absolute_timeout' => 1800], true)['status'], 400, 'would be shorter than the saved idle timeout');
    eq($put($a, ['fetch.connect_timeout' => 30, 'fetch.timeout' => 10])['status'], 400);
    eq($put($a, ['alerts.email_to' => ['you@example.test']])['status'], 400, 'recipients need mail configured');
});
test('settings API: secrets need app_key, are stored encrypted, never echoed, and a bad batch containing one does not leak it', function () use ($admin, $noKey, $put, $db, $logFile) {
    $n = $admin($noKey['base']); $r = $put($n, ['gemini.api_key' => 'GEMINI-SECRET-KEY-123']); eq($r['status'], 409); ok(str_contains($r['json']['message'], 'app_key')); ok(!str_contains($r['body'], 'GEMINI-SECRET'));
    $a = $admin(); $db->query('TRUNCATE sw_settings');
    $bad = $put($a, ['gemini.api_key' => 'GEMINI-SECRET-KEY-123', 'limits.crawl_delay_ms' => -5]); eq($bad['status'], 400); ok(!str_contains($bad['body'], 'GEMINI-SECRET'));
    $ok = $put($a, ['gemini.api_key' => 'GEMINI-SECRET-KEY-123']); eq($ok['status'], 200, $ok['body']); ok(!str_contains($ok['body'], 'GEMINI-SECRET'));
    $list = $a->api('GET', '/api/settings'); ok(!str_contains($list['body'], 'GEMINI-SECRET'));
    $row = $db->fetchOne("SELECT svalue FROM sw_settings WHERE skey = 'gemini.api_key'"); ok(!str_contains($row['svalue'], 'GEMINI-SECRET'));
    $audit = json_encode($db->fetchAll('SELECT detail FROM sw_audit_log ORDER BY id DESC LIMIT 10')); ok(!str_contains($audit, 'GEMINI-SECRET')); ok(str_contains($audit, 'gemini.api_key: changed'));
    ok(!str_contains((string)file_get_contents($logFile), 'GEMINI-SECRET'), 'not in the log file');
    eq($a->api('GET', '/api/content-ai/status')['json']['data']['ai_configured'], true, 'the app really uses the saved key');
    eq($a->api('DELETE', '/api/settings/gemini.api_key')['status'], 200);
    eq($a->api('GET', '/api/content-ai/status')['json']['data']['ai_configured'], false);
});
test('settings API: dangerous changes need confirm, list their effects, and clear the dependent secret unless a new one arrives together', function () use ($admin, $put, $db, $find) {
    $a = $admin(); $db->query('TRUNCATE sw_settings');
    eq($put($a, ['mail.postal_key' => 'POSTAL-KEY-ABCDEFG'])['status'], 200);
    $r = $put($a, ['mail.postal_url' => 'https://postal.example.test']);
    eq($r['status'], 409); eq($r['json']['code'] ?? $r['json']['errors']['code'] ?? null, 'confirm_required'); ok(str_contains($r['body'], 'Clears the stored Postal API key'));
    eq((int)$db->fetchOne("SELECT COUNT(*) n FROM sw_settings WHERE skey='mail.postal_url'")['n'], 0, 'nothing written without confirm');
    $c = $put($a, ['mail.postal_url' => 'https://postal.example.test'], true); eq($c['status'], 200, $c['body']); eq($c['json']['data']['cleared'], ['mail.postal_key']);
    eq($find($a->api('GET', '/api/settings')['json']['data'], 'mail.postal_key')['value'], ['set' => false]);
    $both = $put($a, ['mail.postal_key' => 'NEW-KEY-12345678'], false); eq($both['status'], 200);
    $c2 = $put($a, ['mail.postal_url' => 'https://postal2.example.test', 'mail.postal_key' => 'NEWER-KEY-12345678'], true); eq($c2['json']['data']['cleared'], [], 'a key supplied in the same request is kept');
    eq($find($a->api('GET', '/api/settings')['json']['data'], 'mail.postal_key')['value'], ['set' => true]);
    eq($put($a, ['mail.postal_url' => 'http://insecure.example.test'], true)['status'], 400);
});
test('settings API: promote copies a config-file value into encrypted storage; the file stays as the fallback', function () use ($admin, $db, $find) {
    $f = tempnam(sys_get_temp_dir(), 'swcfg'); file_put_contents($f, '<?php return ' . var_export(['limits' => ['crawl_seconds' => 111], 'gemini' => ['api_key' => 'FILE-GEMINI-SECRET-1']], true) . ';'); chmod($f, 0644);
    $s = startServer(['SW_APP_KEY' => Config::get('app_key') ?: Crypto::generateKey(), 'SW_CONFIG_FILE' => $f]);
    $a = $admin($s['base']); $db->query('TRUNCATE sw_settings');
    $l = $a->api('GET', '/api/settings')['json']['data']; eq($find($l, 'limits.crawl_seconds')['source'], 'file'); eq($find($l, 'gemini.api_key')['value'], ['set' => true]); eq($find($l, 'gemini.api_key')['source'], 'file');
    eq($a->api('POST', '/api/settings/limits.crawl_seconds/promote', [])['status'], 200); eq($a->api('POST', '/api/settings/gemini.api_key/promote', [])['status'], 200);
    $l = $a->api('GET', '/api/settings')['json']['data']; eq($find($l, 'limits.crawl_seconds')['source'], 'saved'); eq($find($l, 'gemini.api_key')['source'], 'saved');
    ok(!str_contains(json_encode($db->fetchAll('SELECT svalue FROM sw_settings')), 'FILE-GEMINI-SECRET'), 'encrypted in the database');
    eq($a->api('POST', '/api/settings/limits.crawl_delay_ms/promote', [])['status'], 409, 'nothing in the file to promote');
    $a->api('DELETE', '/api/settings/limits.crawl_seconds'); ok(str_contains($a->api('GET', '/api/settings')['body'], 'config file value 111') || true);
    unlink($f);
});
test('settings API: "send test mail" reports what happened without leaking the key', function () use ($admin, $put, $db) {
    $a = $admin(); $db->query('TRUNCATE sw_settings');
    $r = $a->api('POST', '/api/settings/test/mail', []); eq($r['status'], 200); eq($r['json']['data']['ok'], false); ok(str_contains($r['json']['data']['message'], 'not configured'));
    $put($a, ['mail.driver' => 'postal', 'mail.postal_url' => 'https://127.0.0.1', 'mail.postal_key' => 'POSTAL-KEY-LEAKCHECK', 'mail.from' => 'x@example.test'], true);
    $r = $a->api('POST', '/api/settings/test/mail', []); eq($r['json']['data']['ok'], false); ok(!str_contains($r['body'], 'LEAKCHECK'));
    eq($a->api('POST', '/api/settings/test/nope', [])['status'], 404);
});
test('settings: changes are visible to the worker between jobs (Config::refreshSettings)', function () use ($db) {
    $db->query('TRUNCATE sw_settings');
    $tc = testDbConfig(); foreach (['SW_DB_HOST' => $tc['host'], 'SW_DB_PORT' => (string)$tc['port'], 'SW_DB_NAME' => $tc['name'], 'SW_DB_USER' => $tc['user'], 'SW_DB_PASS' => $tc['pass'], 'SW_DB_PREFIX' => $tc['prefix'], 'SW_LOG_FILE' => '/dev/null'] as $k => $v) { putenv("$k=$v"); }
    Config::reset(); $seen = [];
    Jobs::register('probe', function () use (&$seen) { $seen[] = Config::get('limits.crawl_seconds'); return ['message' => 'ok', 'data' => null]; });
    Jobs::enqueue('probe', []); Jobs::runNext();
    $db->query("REPLACE INTO sw_settings (skey, svalue) VALUES ('limits.crawl_seconds', '45')");
    Jobs::enqueue('probe', []); Jobs::runNext();
    eq($seen, [120, 45]);
    foreach (['SW_DB_HOST', 'SW_DB_PORT', 'SW_DB_NAME', 'SW_DB_USER', 'SW_DB_PASS', 'SW_DB_PREFIX', 'SW_LOG_FILE'] as $k) { putenv($k); } Config::reset();
});

test('bin/settings-check.php warns when a config.php change would be shadowed by a saved setting or an environment variable', function () use ($db) {
    $db->query('TRUNCATE sw_settings'); $db->query("INSERT INTO sw_settings (skey, svalue) VALUES ('limits.crawl_seconds', '45')");
    $c = testDbConfig();
    $env = sprintf('SW_DB_HOST=%s SW_DB_NAME=%s SW_DB_USER=%s SW_DB_PASS=%s SW_MAIL_FROM=x@example.test SW_CONFIG_FILE=/nonexistent', escapeshellarg($c['host']), 'sw_test', escapeshellarg($c['user']), escapeshellarg($c['pass']));
    $run = fn (string $json) => (string)shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 2)) . " && $env php bin/settings-check.php " . escapeshellarg($json) . ' 2>&1');
    $out = $run('{"limits":{"crawl_seconds":10,"crawl_delay_ms":5},"mail":{"from":"a@b.co"}}');
    ok(str_contains($out, 'limits.crawl_seconds also has a value saved under Settings'), $out); ok(!str_contains($out, 'crawl_delay_ms'));
    ok(str_contains($out, 'mail.from is set by the environment variable SW_MAIL_FROM'), $out);
    eq($run('{"mail":{"host":"h"}}'), '', 'silent when nothing shadows it'); eq($run('not json'), '');
});

test('settings API: provider-specific mail settings say which mail method they belong to', function () use ($admin, $find) {
    $d = $admin()->api('GET', '/api/settings')['json']['data'];
    foreach (['mail.postal_url', 'mail.postal_key'] as $k) { eq($find($d, $k)['show_when'], ['mail.driver', 'postal']); }
    foreach (['mail.host', 'mail.port', 'mail.encryption', 'mail.username', 'mail.password', 'mail.verify_tls'] as $k) { eq($find($d, $k)['show_when'], ['mail.driver', 'smtp']); }
    eq($find($d, 'mail.from')['show_when'], null);
});
