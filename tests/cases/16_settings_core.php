<?php
use Sitewright\Core\{Config, Crypto, Settings};

/** Flatten Config::defaults() to dotted leaf keys (lists count as leaves). */
$leaves = function (array $a, string $p = '') use (&$leaves): array {
    $out = [];
    foreach ($a as $k => $v) {
        $key = $p === '' ? (string)$k : "$p.$k";
        if (is_array($v) && $v !== [] && array_keys($v) !== range(0, count($v) - 1)) { $out = array_merge($out, $leaves($v, $key)); } else { $out[] = $key; }
    }
    return $out;
};

test('settings: every config leaf is editable (registry) or explicitly read-only, never both', function () use ($leaves) {
    $reg = array_keys(Settings::registry()); $ro = Settings::readonlyKeys();
    eq(array_values(array_intersect($reg, $ro)), [], 'a key is in both lists');
    $missing = array_values(array_diff($leaves(Config::defaults()), $reg, $ro));
    eq($missing, [], 'config keys with no registry entry: ' . json_encode($missing));
    $ghost = array_values(array_diff(array_merge($reg, $ro), $leaves(Config::defaults())));
    eq($ghost, [], 'registry keys with no config default: ' . json_encode($ghost));
});
test('settings: defaults come from Config::defaults(), env names exist, secrets are flagged', function () {
    $r = Settings::registry();
    eq($r['limits.crawl_seconds']['default'], Config::defaults()['limits']['crawl_seconds']);
    eq($r['limits.crawl_seconds']['group'], 'crawl'); ok($r['mail.postal_key']['secret'] && $r['alerts.teams_webhook']['secret'] && $r['gemini.api_key']['secret'] && $r['google.client_secret']['secret']);
    eq($r['mail.from']['env'], 'SW_MAIL_FROM'); ok($r['mail.postal_url']['danger'] && $r['google.redirect_uri']['danger'] && !$r['limits.crawl_seconds']['danger']);
    foreach ($r as $k => $d) { ok(in_array($d['apply'], ['live', 'worker_restart', 'fpm_reload'], true), $k); ok($d['label'] !== '' && $d['help'] !== '', "$k needs a label and help"); }
    foreach (['db.pass', 'app_key', 'fetch.dev_allow_cidrs', 'fetch.allow_ports', 'gemini.endpoint', 'session.secure', 'hsts', 'debug', 'base_url', 'trusted_proxies', 'limits.body_bytes'] as $k) { ok(in_array($k, Settings::readonlyKeys(), true), "$k should be read-only"); }
});
test('settings: coerce enforces the registry type exactly', function () {
    eq(Settings::coerce('limits.crawl_seconds', 90), 90);
    foreach ([['limits.crawl_seconds', 'abc'], ['limits.crawl_seconds', '90'], ['limits.crawl_seconds', 9.5], ['limits.crawl_seconds', 5], ['limits.crawl_seconds', 100000], ['enable_geocoding', 'maybe'], ['enable_geocoding', 1], ['log.level', 'loud'],
              ['mail.postal_url', 'http://x.example'], ['mail.postal_url', 'javascript:alert(1)'], ['alerts.email_to', ['not-an-email']], ['alerts.email_to', 'a@b.co'], ['fetch.allow_hosts', [str_repeat('a', 300)]], ['mail.from_name', "x\r\nBcc: y"], ['unknown.key', 1]] as [$k, $v]) {
        throws(InvalidArgumentException::class, fn () => Settings::coerce($k, $v), "$k = " . json_encode($v));
    }
    eq(Settings::coerce('enable_geocoding', true), true); eq(Settings::coerce('log.level', 'warn'), 'warn');
    eq(Settings::coerce('alerts.email_to', ['a@example.test', 'b@example.test']), ['a@example.test', 'b@example.test']);
    eq(Settings::coerce('mail.postal_url', 'https://app.example.test/'), 'https://app.example.test');
});

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb();
// Config::reset() rebuilds from file + environment, so the tests below provide the database and key the way production does: via environment.
$tc = testDbConfig();
foreach (['SW_DB_HOST' => $tc['host'], 'SW_DB_PORT' => (string)$tc['port'], 'SW_DB_NAME' => $tc['name'], 'SW_DB_USER' => $tc['user'], 'SW_DB_PASS' => $tc['pass'], 'SW_DB_PREFIX' => $tc['prefix'], 'SW_APP_KEY' => Crypto::generateKey(), 'SW_LOG_FILE' => '/dev/null'] as $k => $v) { putenv("$k=$v"); }
Config::reset();
$put = function (string $key, mixed $value, bool $secret = false) use ($db) {
    $db->query('REPLACE INTO sw_settings (skey, svalue, is_secret) VALUES (:k, :v, :s)', ['k' => $key, 'v' => $secret ? Crypto::encrypt((string)$value, "setting:$key") : json_encode($value), 's' => $secret ? 1 : 0]);
};
$fileCfg = function (array $a) { $f = tempnam(sys_get_temp_dir(), 'swcfg'); file_put_contents($f, '<?php return ' . var_export($a, true) . ';'); return $f; };

test('settings precedence: env > saved > file > default, with the source reported', function () use ($db, $put, $fileCfg) {
    $db->query('TRUNCATE sw_settings');
    $c = testDbConfig();
    $f = $fileCfg(['limits' => ['crawl_seconds' => 111], 'db' => ['host' => $c['host'], 'port' => $c['port'], 'name' => $c['name'], 'user' => $c['user'], 'pass' => $c['pass'], 'prefix' => $c['prefix']], 'app_key' => getenv('SW_APP_KEY')]);
    Config::useFile($f); putenv('SW_ALLOWED_HOSTS');
    eq(Config::get('limits.crawl_seconds'), 111); eq(Settings::effective('limits.crawl_seconds')['source'], 'file');
    $put('limits.crawl_seconds', 222); Config::reset();
    eq(Config::get('limits.crawl_seconds'), 222, 'saved beats file'); eq(Settings::effective('limits.crawl_seconds')['source'], 'saved');
    eq(Settings::effective('limits.crawl_seconds')['fallback'], 'config file value 111');
    eq(Settings::effective('limits.crawl_delay_ms')['source'], 'default'); eq(Settings::effective('limits.crawl_delay_ms')['fallback'], 'built-in default 200');
    putenv('SW_MAIL_FROM=env@example.test'); $put('mail.from', 'saved@example.test'); Config::reset();
    eq(Config::get('mail.from'), 'env@example.test', 'env beats saved'); $e = Settings::effective('mail.from'); eq($e['source'], 'env'); ok($e['locked']);
    putenv('SW_MAIL_FROM'); Config::reset(); eq(Config::get('mail.from'), 'saved@example.test');
    unlink($f); Config::useFile('/nonexistent/config.php');
});
test('settings: secrets are encrypted at rest and effective() never returns their value', function () use ($db, $put) {
    $db->query('TRUNCATE sw_settings'); $put('mail.postal_key', 'POSTAL-SECRET-123', true); Config::reset();
    $row = $db->fetchOne("SELECT svalue, is_secret FROM sw_settings WHERE skey='mail.postal_key'");
    ok(!str_contains($row['svalue'], 'POSTAL-SECRET') && (int)$row['is_secret'] === 1);
    eq(Config::get('mail.postal_key'), 'POSTAL-SECRET-123', 'the app can use it'); eq(Settings::effective('mail.postal_key')['value'], ['set' => true]);
    ok(!str_contains(json_encode(Settings::effective('mail.postal_key')), 'POSTAL-SECRET'));
    $old = getenv('SW_APP_KEY'); putenv('SW_APP_KEY=' . Crypto::generateKey()); Config::reset();
    eq(Config::get('mail.postal_key'), '', 'a secret that cannot be decrypted is ignored, not fatal');
    putenv('SW_APP_KEY=' . $old); Config::reset();
});
test('settings: corrupt, invalid or unknown saved rows are ignored; a missing table or no database still boots', function () use ($db, $put) {
    $db->query('TRUNCATE sw_settings');
    $db->query("INSERT INTO sw_settings (skey, svalue) VALUES ('limits.crawl_seconds', 'not json'), ('limits.crawl_delay_ms', '999999'), ('nonsense.key', '1'), ('db.pass', '\"x\"'), ('log.level', '\"warn\"')");
    Config::reset();
    eq(Config::get('limits.crawl_seconds'), 120); eq(Config::get('limits.crawl_delay_ms'), 200, 'out-of-range ignored'); eq(Config::get('log.level'), 'warn', 'valid ones still apply');
    ok(Config::get('db.pass') !== 'x', 'read-only keys can never be overridden from the table');
    $db->query('DROP TABLE sw_settings'); Config::reset();
    eq(Config::get('limits.crawl_seconds'), 120, 'missing table');
    $db->query('CREATE TABLE sw_settings (skey VARCHAR(80) NOT NULL, svalue TEXT NOT NULL, is_secret TINYINT(1) NOT NULL DEFAULT 0, updated_by BIGINT UNSIGNED NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (skey)) ENGINE=InnoDB');
});
test('settings: refresh applies a newly saved value and drops a removed one without losing other runtime overrides', function () use ($db, $put) {
    $db->query('TRUNCATE sw_settings'); Config::reset(); Config::set('contact', 'runtime-override');
    $put('limits.crawl_seconds', 77); Config::refreshSettings(); eq(Config::get('limits.crawl_seconds'), 77); eq(Config::get('contact'), 'runtime-override');
    $db->query('TRUNCATE sw_settings'); Config::refreshSettings(); eq(Config::get('limits.crawl_seconds'), 120);
});

test('settings: cleanup of the test environment', function () {
    foreach (['SW_DB_HOST', 'SW_DB_PORT', 'SW_DB_NAME', 'SW_DB_USER', 'SW_DB_PASS', 'SW_DB_PREFIX', 'SW_APP_KEY', 'SW_LOG_FILE'] as $k) { putenv($k); }
    Config::reset(); ok(true);
});
