<?php
// Regression tests for the findings of the whole-branch review of the Settings project.
use Sitewright\Core\{Config, Crypto, SafeHttpClient, Settings};

$cfgFile = function (array $a) { $f = tempnam(sys_get_temp_dir(), 'swcfg'); file_put_contents($f, '<?php return ' . var_export($a, true) . ';'); chmod($f, 0644); return $f; };

test('review #3: a list in the config file REPLACES the built-in list (it is not merged by position)', function () use ($cfgFile) {
    $f = $cfgFile(['alerts' => ['events' => ['job_failed']], 'dashboard' => ['cards' => ['clicks']], 'limits' => ['crawl_seconds' => 77]]);
    Config::useFile($f); Config::reset();
    eq(Config::get('alerts.events'), ['job_failed']); eq(Config::get('dashboard.cards'), ['clicks']);
    eq(Config::get('limits.crawl_seconds'), 77); eq(Config::get('limits.crawl_delay_ms'), 200, 'sibling keys keep their defaults');
    eq(Settings::effective('alerts.events')['value'], ['job_failed']);
    $g = $cfgFile(['alerts' => ['events' => []]]); Config::useFile($g); Config::reset(); eq(Config::get('alerts.events'), [], 'an empty list in the file really means none');
    unlink($f); unlink($g); Config::useFile('/nonexistent/config.php'); Config::reset(); Config::set('log.file', '/dev/null');
});
test('review #2: the shared fetch client follows changed fetch settings (a long-running worker must not keep an old block list)', function () {
    SafeHttpClient::setDefault(null); Config::set('fetch.block_hosts', []);
    $msg = function () { try { SafeHttpClient::default()->get('https://blocked.example/'); return 'fetched'; } catch (\Sitewright\Core\FetchException $e) { return $e->getMessage(); } };
    $before = $msg(); ok(!str_contains($before, 'not allowed') && !str_contains($before, 'blocked'), "before: $before");
    Config::set('fetch.block_hosts', ['blocked.example']);
    $after = $msg(); ok(str_contains(strtolower($after), 'not allowed') || str_contains(strtolower($after), 'block'), "after: $after");
    Config::set('fetch.block_hosts', []); SafeHttpClient::setDefault(null);
});
test('review #4: sign-in and session limits are dangerous (confirm), have sane floors, and a CLI can undo a bad saved value', function () {
    foreach (['session.idle_timeout', 'session.absolute_timeout', 'limits.login_attempts', 'limits.login_ip_attempts', 'limits.login_window'] as $k) { ok(Settings::def($k)['danger'], "$k should need confirmation"); }
    eq(Settings::prepare(['limits.login_attempts' => 1], true)['status'], 400); eq(Settings::prepare(['limits.login_ip_attempts' => 5], true)['status'], 400);
    $p = Settings::prepare(['limits.login_attempts' => 4], false); eq($p['status'], 409); eq($p['errors']['code'], 'confirm_required');
    eq(Settings::prepare(['limits.login_attempts' => 4], true)['status'], 200);
});

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb();
seedAdmin('root', 'root passphrase long here', 'admin');
$key = Crypto::generateKey();
$put = fn (TestHttp $h, array $c, bool $confirm = true) => $h->api('PUT', '/api/settings', ['changes' => $c, 'confirm' => $confirm]);
$find = function (array $d, string $k) { foreach ($d['groups'] as $g) { foreach ($g['settings'] as $s) { if ($s['key'] === $k) { return $s; } } } return null; };

test('review #1: changing the mail host also stops a password that lives in the CONFIG FILE from being sent to the new host; Reset brings it back', function () use ($cfgFile, $key, $put, $find, $db) {
    $f = $cfgFile(['mail' => ['host' => 'smtp.old.example', 'password' => 'FILE-SMTP-PASS-1', 'from' => 'a@example.test']]);
    $srv = startServer(['SW_APP_KEY' => $key, 'SW_CONFIG_FILE' => $f]); $a = new TestHttp($srv['base']); $a->login('root', 'root passphrase long here');
    $db->query('TRUNCATE sw_settings');
    eq($find($a->api('GET', '/api/settings')['json']['data'], 'mail.password')['value'], ['set' => true]);
    $r = $put($a, ['mail.host' => 'smtp.new.example']); eq($r['status'], 200, $r['body']); eq($r['json']['data']['cleared'], ['mail.password']);
    $s = $find($a->api('GET', '/api/settings')['json']['data'], 'mail.password'); eq($s['value'], ['set' => false], 'the file copy no longer applies'); eq($s['source'], 'saved');
    ok(!str_contains($a->api('GET', '/api/settings')['body'], 'FILE-SMTP-PASS'));
    $mailTest = $a->api('POST', '/api/settings/test/mail', []); ok(!str_contains($mailTest['body'], 'FILE-SMTP-PASS'));
    $a->api('DELETE', '/api/settings/mail.password');
    eq($find($a->api('GET', '/api/settings')['json']['data'], 'mail.password')['value'], ['set' => true], 'resetting removes the marker; the file value is back');
    eq($put($a, ['mail.host' => 'smtp.new.example'])['json']['data']['cleared'], [], 'no change of address, nothing cleared');
    unlink($f);
});
test('review #1: a password supplied by the ENVIRONMENT cannot be left attached to a changed address', function () use ($key, $put, $db) {
    $db->query('TRUNCATE sw_settings');
    $srv = startServer(['SW_APP_KEY' => $key, 'SW_MAIL_PASSWORD' => 'ENV-SMTP-PASS', 'SW_MAIL_HOST' => '']); $a = new TestHttp($srv['base']); $a->login('root', 'root passphrase long here');
    $r = $put($a, ['mail.host' => 'smtp.new.example']); eq($r['status'], 409); ok(str_contains($r['json']['message'], 'SW_MAIL_PASSWORD'), $r['body']); ok(!str_contains($r['body'], 'ENV-SMTP-PASS'));
    eq($put($a, ['mail.host' => 'smtp.new.example', 'mail.password' => 'x'])['status'], 409, 'cannot override a locked secret either');
});
test('review (minor, promoted): an empty file value is not offered for promotion, and promoting never answers 500', function () use ($cfgFile, $key, $find) {
    $f = $cfgFile(['gemini' => ['api_key' => '']]);
    $srv = startServer(['SW_APP_KEY' => $key, 'SW_CONFIG_FILE' => $f]); $a = new TestHttp($srv['base']); $a->login('root', 'root passphrase long here');
    $s = $find($a->api('GET', '/api/settings')['json']['data'], 'gemini.api_key'); eq($s['source'], 'default'); eq($s['value'], ['set' => false]);
    $r = $a->api('POST', '/api/settings/gemini.api_key/promote', []); ok($r['status'] >= 400 && $r['status'] < 500, 'status ' . $r['status']);
    $r = $a->api('POST', '/api/settings/log.level/promote', []); ok($r['status'] >= 400 && $r['status'] < 500, 'nothing to promote: ' . $r['status']);
    unlink($f);
});
test('review #4: bin/settings.php lists saved values and resets one without the web UI (break-glass)', function () use ($db) {
    $db->query('TRUNCATE sw_settings'); $db->query("INSERT INTO sw_settings (skey, svalue) VALUES ('limits.login_attempts', '3'), ('log.level', '\"warn\"')");
    $c = testDbConfig(); $env = sprintf('SW_DB_HOST=%s SW_DB_NAME=%s SW_DB_USER=%s SW_DB_PASS=%s SW_CONFIG_FILE=/nonexistent', escapeshellarg($c['host']), 'sw_test', escapeshellarg($c['user']), escapeshellarg($c['pass']));
    $run = fn (string $a) => (string)shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 2)) . " && $env php bin/settings.php $a 2>&1");
    $l = $run('list'); ok(str_contains($l, 'limits.login_attempts') && str_contains($l, 'log.level'), $l);
    ok(str_contains($run('reset limits.login_attempts'), 'Reset limits.login_attempts')); eq((int)$db->fetchOne("SELECT COUNT(*) n FROM sw_settings WHERE skey='limits.login_attempts'")['n'], 0);
    ok(str_contains($run('reset nonsense'), 'Unknown')); ok(str_contains($run('reset-all'), 'Removed 1')); eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_settings')['n'], 0);
});
