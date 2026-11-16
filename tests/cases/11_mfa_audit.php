<?php
use Sitewright\Core\{Audit, Totp};

// ---- TOTP primitives (no DB) ----------------------------------------------------------------------------------------------
test('TOTP: RFC 6238 Appendix B vectors (SHA-1, secret "12345678901234567890", 8 digits)', function () {
    foreach ([59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130'] as $t => $code) {
        eq(Totp::code('12345678901234567890', intdiv($t, 30), 8), $code, "t=$t");
    }
});
test('TOTP: base32 encode/decode round trip (RFC 4648 vectors), lenient on case/spaces, strict on junk', function () {
    foreach (['' => '', 'f' => 'MY', 'fo' => 'MZXQ', 'foo' => 'MZXW6', 'foob' => 'MZXW6YQ', 'fooba' => 'MZXW6YTB', 'foobar' => 'MZXW6YTBOI'] as $raw => $b32) {
        eq(Totp::base32Encode($raw), $b32);
        if ($raw !== '') { eq(Totp::base32Decode($b32), $raw); }
    }
    eq(Totp::base32Decode('mzxw 6ytb-oi=='), 'foobar');
    foreach (['', '1', 'MZXW6YT0', 'MZ XW!', "MZXW6\n<"] as $bad) { eq(Totp::base32Decode($bad), null, json_encode($bad)); }
    $s = Totp::generateSecret(); eq(strlen($s), 32); eq(strlen((string)Totp::base32Decode($s)), 20);
});
test('TOTP verify: ±1 step accepted, ±2 refused, replay and older steps refused, junk refused', function () {
    $secret = Totp::base32Encode('12345678901234567890'); $key = '12345678901234567890'; $now = 1700000000; $cur = intdiv($now, 30);
    $c = fn (int $s) => Totp::code($key, $s);
    eq(Totp::verify($secret, $c($cur), $now, 0), $cur);
    eq(Totp::verify($secret, $c($cur - 1), $now, 0), $cur - 1);
    eq(Totp::verify($secret, $c($cur + 1), $now, 0), $cur + 1);
    eq(Totp::verify($secret, $c($cur + 2), $now, 0), null); eq(Totp::verify($secret, $c($cur - 2), $now, 0), null);
    eq(Totp::verify($secret, $c($cur), $now, $cur), null, 'same step again = replay');
    eq(Totp::verify($secret, $c($cur - 1), $now, $cur), null, 'older than the last accepted step');
    eq(Totp::verify($secret, $c($cur + 1), $now, $cur), $cur + 1, 'a newer step is fine');
    foreach (['', '12345', '1234567', 'abcdef', '12345a', "123456\n"] as $bad) { eq(Totp::verify($secret, $bad, $now, 0), null, json_encode($bad)); }
    eq(Totp::verify('not base32!', $c($cur), $now, 0), null);
    eq(Totp::verify($secret, substr($c($cur), 0, 3) . ' ' . substr($c($cur), 3), $now, 0), $cur, 'spaces in the typed code are tolerated');
});
test('TOTP: recovery codes are unique, 10 chars, normalised before hashing; otpauth URI is well formed', function () {
    $codes = Totp::newRecoveryCodes(8); eq(count(array_unique($codes)), 8);
    foreach ($codes as $c) { ok(preg_match('/^[a-z2-9]{5}-[a-z2-9]{5}$/D', $c) === 1); eq(Totp::recoveryHash($c), Totp::recoveryHash(strtoupper(str_replace('-', ' ', $c)))); }
    $u = Totp::uri('Sitewright', 'a b@x', 'ABC234');
    ok(str_starts_with($u, 'otpauth://totp/Sitewright:a%20b%40x?secret=ABC234&issuer=Sitewright'));
});

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb();
seedAdmin('plain', 'plain user passphrase');
seedAdmin('mfa', 'mfa user passphrase');
$secret = Totp::generateSecret(); $key = (string)Totp::base32Decode($secret);
$codes = Totp::newRecoveryCodes(3);
$uid = (int)$db->fetchOne("SELECT id FROM sw_users WHERE username='mfa'")['id'];
$db->update('users', ['totp_secret' => $secret], ['id' => $uid]);
foreach ($codes as $c) { $db->insert('recovery_codes', ['user_id' => $uid, 'code_hash' => Totp::recoveryHash($c)]); }
$srv = startServer(); $base = $srv['base'];
$srvProxy = startServer(['SW_TRUSTED_PROXIES' => '127.0.0.0/8']);
$step = fn (int $delta = 0) => Totp::code($key, intdiv(time(), 30) + $delta);
$pending = function () use ($base): TestHttp {
    $h = new TestHttp($base);
    $r = $h->login('mfa', 'mfa user passphrase');
    eq($r['status'], 200); eq($r['json']['data'], ['mfa_required' => true], 'no user and no new csrf token before the code');
    return $h;
};
$mfa = fn (TestHttp $h, string $code) => $h->api('POST', '/api/auth/mfa', ['code' => $code]);
$sid = fn (TestHttp $h) => $h->cookies['sw_session'] ?? '';

test('MFA: an account without a secret signs in exactly as before', function () use ($base) {
    $h = new TestHttp($base); $r = $h->login('plain', 'plain user passphrase');
    eq($r['status'], 200); eq($r['json']['data']['user']['username'], 'plain'); ok(!empty($r['json']['data']['csrf_token']));
});
test('MFA: password alone grants nothing: pending session cannot reach any protected endpoint', function () use ($pending) {
    $h = $pending();
    foreach (['/api/auth/me', '/api/dashboard/stats', '/api/redirections'] as $p) { eq($h->api('GET', $p)['status'], 401, $p); }
});
test('MFA: wrong code is 401; correct code signs in with a NEW session id and CSRF token', function () use ($pending, $mfa, $step, $sid) {
    $h = $pending(); $before = $sid($h); $oldCsrf = $h->csrf;
    eq($mfa($h, '000000')['status'], 401);
    eq($mfa($h, 'not a code')['status'], 401);
    $r = $mfa($h, $step());
    eq($r['status'], 200); eq($r['json']['data']['user']['username'], 'mfa');
    ok($sid($h) !== $before, 'session id regenerated at the second step');
    $h->csrf = $r['json']['data']['csrf_token']; ok($h->csrf !== $oldCsrf);
    eq($h->api('GET', '/api/auth/me')['status'], 200);
});
test('MFA: a code cannot be replayed, and an earlier step is refused after a later one was used', function () use ($pending, $mfa, $step) {
    global $db; $db->update('users', ['totp_last_step' => 0], ['username' => 'mfa']);
    $c = $step(0);
    eq($mfa($pending(), $c)['status'], 200);
    eq($mfa($pending(), $c)['status'], 401, 'same code again');
    $next = $step(1);
    eq($mfa($pending(), $next)['status'], 200, 'next step is accepted');
    eq($mfa($pending(), $step(-1))['status'], 401, 'previous step after a later one');
});
test('MFA: /api/auth/mfa with no pending sign-in is 401 "Sign in again"', function () use ($base) {
    $h = new TestHttp($base); $h->bootstrap();
    $r = $h->api('POST', '/api/auth/mfa', ['code' => '123456']);
    eq($r['status'], 401); ok(str_contains($r['json']['message'], 'Sign in again'));
});
test('MFA: recovery code works once, is stored only as a hash, and is single-use', function () use ($pending, $mfa, $codes, $db) {
    $c = $codes[0];
    ok($db->fetchOne('SELECT COUNT(*) n FROM sw_recovery_codes WHERE code_hash = :h', ['h' => $c])['n'] == 0, 'plaintext is not stored');
    eq($mfa($pending(), $c)['status'], 200);
    eq($mfa($pending(), $c)['status'], 401, 'second use refused');
    eq($mfa($pending(), strtoupper(str_replace('-', ' ', $codes[1])))['status'], 200, 'case/spacing tolerated');
});
test('MFA: guessing is throttled per ACCOUNT, so discarding the session does not reset it', function () use ($pending, $mfa, $step) {
    $statuses = [];
    for ($i = 0; $i < 8; $i++) { $statuses[] = $mfa($pending(), sprintf('%06d', 100000 + $i))['status']; }
    ok(in_array(429, $statuses, true), 'got ' . json_encode($statuses));
    eq($mfa($pending(), $step(1))['status'], 429, 'even the right code is refused while throttled');
});
test('MFA: changing the secret ends existing sessions', function () use ($base, $db) {
    $h = new TestHttp($base); $h->login('plain', 'plain user passphrase');
    eq($h->api('GET', '/api/auth/me')['status'], 200);
    $db->update('users', ['totp_secret' => Totp::generateSecret()], ['username' => 'plain']);
    eq($h->api('GET', '/api/auth/me')['status'], 401);
    $db->query("UPDATE sw_users SET totp_secret = NULL WHERE username = 'plain'");
});

// ---- audit log -------------------------------------------------------------------------------------------------------------
test('audit: sign-ins, failures, MFA steps and logout are recorded; no secrets; unknown usernames are not stored', function () use ($base, $db, $pending, $mfa, $codes) {
    $db->query('TRUNCATE sw_audit_log'); $db->query('TRUNCATE sw_rate_limits');
    $typedPassword = 'hunter2-typed-in-the-wrong-box';
    $h = new TestHttp($base); $h->login($typedPassword, 'whatever-password-1');
    $h = new TestHttp($base); $h->login('plain', 'wrong password here');
    $h = new TestHttp($base); $h->login('plain', 'plain user passphrase'); $h->api('POST', '/api/auth/logout');
    $mfa($pending(), '000000');
    $rows = $db->fetchAll('SELECT username, action, ip, detail FROM sw_audit_log ORDER BY id');
    $acts = array_column($rows, 'action');
    foreach (['login_failed', 'login_ok', 'logout', 'login_password_ok', 'mfa_failed'] as $a) { ok(in_array($a, $acts, true), "missing $a in " . json_encode($acts)); }
    eq($rows[0]['username'], '(unknown)'); eq($rows[1]['username'], 'plain');
    $dump = json_encode($rows);
    foreach ([$typedPassword, 'whatever-password-1', 'wrong password here', 'plain user passphrase', 'mfa user passphrase', '000000'] as $secret) { ok(!str_contains($dump, $secret), "$secret leaked into the audit log"); }
    eq($rows[0]['ip'], '127.0.0.1');
});
test('audit: X-Forwarded-For is believed only from a trusted proxy (end to end)', function () use ($base, $srvProxy, $db) {
    $db->query('TRUNCATE sw_audit_log'); $db->query('TRUNCATE sw_rate_limits');
    $go = function (string $b) { $h = new TestHttp($b); $h->bootstrap(); $h->req('POST', '/api/auth/login', ['username' => 'plain', 'password' => 'bad password 12'], ['Content-Type' => 'application/json', 'X-CSRF-Token' => (string)$h->csrf, 'X-Forwarded-For' => '198.51.100.4']); };
    $go($base); $go($srvProxy['base']);
    $ips = array_column($db->fetchAll('SELECT ip FROM sw_audit_log ORDER BY id'), 'ip');
    eq($ips, ['127.0.0.1', '198.51.100.4']);
});
test('audit: destructive actions (delete rule, clear 404 log) are attributed to the signed-in user', function () use ($base, $db) {
    $db->query('TRUNCATE sw_audit_log'); $db->query('TRUNCATE sw_rate_limits');
    $h = new TestHttp($base); eq($h->login('plain', 'plain user passphrase')['status'], 200);
    $r = $h->api('POST', '/api/redirections', ['source_url' => '/audit-old', 'target_url' => '/audit-new', 'redirect_type' => 301]);
    eq($r['status'], 201, $r['body']);
    $id = (int)$db->fetchOne("SELECT id FROM sw_redirections WHERE source_url = '/audit-old'")['id'];
    eq($h->api('DELETE', "/api/redirections/$id")['status'], 200);
    eq($h->api('POST', '/api/404-monitor/clear', ['confirm' => true])['status'], 200);
    $rows = $db->fetchAll("SELECT username, action, detail FROM sw_audit_log WHERE action IN ('redirect_deleted','404_log_cleared') ORDER BY id");
    eq(array_column($rows, 'action'), ['redirect_deleted', '404_log_cleared']); eq($rows[0]['username'], 'plain'); eq($rows[0]['detail'], "id $id");
});
test('audit: retention purge removes only old rows; writes never throw', function () use ($db) {
    $db->query('TRUNCATE sw_audit_log');
    $db->query("INSERT INTO sw_audit_log (created_at, username, action) VALUES (NOW() - INTERVAL 400 DAY, 'old', 'x'), (NOW() - INTERVAL 10 DAY, 'new', 'y')");
    eq(Audit::purge(365), 1); eq($db->fetchOne('SELECT username FROM sw_audit_log')['username'], 'new');
    Audit::log(str_repeat('a', 500), null, str_repeat('b', 500), "multi\nline\x00detail" . str_repeat('c', 500));
    $r = $db->fetchOne('SELECT action, username, detail FROM sw_audit_log ORDER BY id DESC LIMIT 1');
    eq(strlen($r['action']), 40); eq(strlen($r['username']), 60); ok(strlen($r['detail']) <= 255 && !preg_match('/[\x00-\x1F]/', $r['detail']));
});

// ---- CLI ---------------------------------------------------------------------------------------------------------------------
test('bin/user.php mfa-enable / mfa-disable; list shows MFA state; bin/audit.php and purge-audit run', function () use ($db) {
    $c = testDbConfig();
    $env = sprintf('SW_DB_HOST=%s SW_DB_NAME=%s SW_DB_USER=%s SW_DB_PASS=%s', escapeshellarg($c['host']), 'sw_test', escapeshellarg($c['user']), escapeshellarg($c['pass']));
    $run = fn (string $args) => (string)shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 2)) . " && $env php bin/$args 2>&1; echo \"exit=\$?\"");
    $out = $run('user.php mfa-enable plain --no-confirm');
    ok(str_contains($out, 'otpauth://totp/Sitewright:plain?secret=') && str_contains($out, 'exit=0'), $out);
    preg_match_all('/^  ([a-z2-9]{5}-[a-z2-9]{5})$/m', $out, $m); eq(count($m[1]), 8, 'eight recovery codes printed');
    ok($db->fetchOne("SELECT totp_secret FROM sw_users WHERE username='plain'")['totp_secret'] !== null);
    eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_recovery_codes WHERE user_id = (SELECT id FROM sw_users WHERE username="plain")')['n'], 8);
    ok(str_contains($run('user.php list'), "plain\tplain@example.test") && str_contains($run('user.php list'), "\tmfa\tadmin"));
    ok(str_contains($run('user.php mfa-enable ghost --no-confirm'), 'exit=1'));
    ok(str_contains($run('user.php mfa-enable plain'), 'exit=2'), 'without a terminal and without --no-confirm it refuses (no accidental lock-out)');
    ok(str_contains($run('user.php mfa-disable plain'), 'MFA disabled'));
    eq($db->fetchOne("SELECT totp_secret FROM sw_users WHERE username='plain'")['totp_secret'], null);
    ok(str_contains($run('audit.php 5'), 'mfa_disabled') && str_contains($run('purge-audit.php'), 'Purged'));
});
