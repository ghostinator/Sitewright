<?php
use Sitewright\Core\{Config, Jobs, Settings, Totp};

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb();
seedAdmin('rem', 'remember passphrase long', 'editor'); seedAdmin('other', 'other passphrase long here', 'editor'); seedAdmin('mfa2', 'mfa two passphrase long', 'editor');
$secret = Totp::generateSecret(); $key = (string)Totp::base32Decode($secret); $db->update('users', ['totp_secret' => $secret], ['username' => 'mfa2']);
$srv = startServer(); $base = $srv['base']; $off = startServer(['SW_SESSION_REMEMBER_DAYS' => '0']);
$uid = fn (string $n) => (int)$db->fetchOne('SELECT id FROM sw_users WHERE username = :n', ['n' => $n])['id'];
$signin = function (string $user, string $pass, bool $remember, ?string $b = null) use ($base) {
    $h = new TestHttp($b ?? $base); $h->bootstrap();
    $r = $h->req('POST', '/api/auth/login', ['username' => $user, 'password' => $pass, 'remember' => $remember], ['Content-Type' => 'application/json', 'X-CSRF-Token' => (string)$h->csrf]);
    if ($r['status'] === 200 && isset($r['json']['data']['csrf_token'])) { $h->csrf = $r['json']['data']['csrf_token']; }
    return [$h, $r];
};
$lapse = function (TestHttp $h) { unset($h->cookies['sw_session']); };   // the PHP session is gone (idle timeout, browser restart); only the remember cookie is left
$me = fn (TestHttp $h) => $h->req('GET', '/api/auth/me', null, ['Accept' => 'application/json']);
$who = function (TestHttp $h) use ($me) { $r = $me($h); return $r['status'] === 200 ? ($r['json']['data']['user']['username'] ?? null) : null; };
$tokens = fn () => $db->fetchAll('SELECT * FROM sw_remember_tokens ORDER BY id');

test('without "keep me signed in" a lapsed session means signed out, exactly as before; no token is made', function () use ($signin, $lapse, $me, $who, $tokens, $db) {
    $db->query('TRUNCATE sw_remember_tokens'); [$h, $r] = $signin('rem', 'remember passphrase long', false); eq($r['status'], 200);
    ok(!isset($h->cookies['sw_remember']), 'no cookie'); eq(count($tokens()), 0);
    eq($who($h), 'rem'); $lapse($h); eq($who($h), null);
});
test('keep me signed in: a long-lived HttpOnly cookie; only a hash of its secret is stored; a lapsed session is restored silently with a new session id', function () use ($signin, $lapse, $me, $who, $tokens, $db) {
    $db->query('TRUNCATE sw_remember_tokens'); [$h, $r] = $signin('rem', 'remember passphrase long', true); eq($r['status'], 200);
    $line = implode("\n", array_filter($r['raw_set_cookie'], fn ($c) => str_starts_with($c, 'sw_remember=')));
    ok($line !== '', 'cookie set'); ok(stripos($line, 'HttpOnly') !== false && stripos($line, 'SameSite=Lax') !== false, $line);
    preg_match('/Max-Age=(\d+)/i', $line, $m); ok(abs((int)$m[1] - 30 * 86400) < 120, 'about 30 days: ' . ($m[1] ?? '?'));
    [$sel, $val] = explode('.', $h->cookies['sw_remember']); ok(strlen($sel) === 24 && strlen($val) === 64 && ctype_xdigit($sel . $val), 'selector and validator are long random hex');
    $t = $tokens(); eq(count($t), 1); eq($t[0]['selector'], $sel); eq($t[0]['validator_hash'], hash('sha256', $val)); ok(!str_contains(json_encode($t), $val), 'the secret half is not stored');
    $old = $h->cookies['sw_session']; $lapse($h); $r2 = $me($h); eq($who($h), 'rem', 'restored (and again, now with a live session)'); ok(!empty($h->cookies['sw_session']) && $h->cookies['sw_session'] !== $old, 'a fresh session id');
    ok(!empty($r2['json']['data']['csrf_token']), 'and a fresh CSRF token');
    $a = $db->fetchOne("SELECT COUNT(*) n FROM sw_audit_log WHERE action = 'login_remembered'"); ok((int)$a['n'] >= 1, 'recorded in the audit log');
});
test('it keeps going: each use pushes the expiry out (at most twice a day), up to a fixed ceiling since the token was made', function () use ($signin, $lapse, $me, $who, $db) {
    $db->query('TRUNCATE sw_remember_tokens'); [$h] = $signin('rem', 'remember passphrase long', true);
    $db->query('UPDATE sw_remember_tokens SET expires_at = NOW() + INTERVAL 5 DAY, last_used_at = NOW() - INTERVAL 13 HOUR'); $lapse($h); eq($me($h)['status'], 200);
    $d = (int)$db->fetchOne('SELECT TIMESTAMPDIFF(DAY, NOW(), expires_at) d FROM sw_remember_tokens')['d']; ok($d >= 29, "extended to about 30 days from now, got $d");
    $db->query('UPDATE sw_remember_tokens SET expires_at = NOW() + INTERVAL 5 DAY, last_used_at = NOW() - INTERVAL 1 HOUR'); $lapse($h); $me($h);
    ok((int)$db->fetchOne('SELECT TIMESTAMPDIFF(DAY, NOW(), expires_at) d FROM sw_remember_tokens')['d'] <= 5, 'used again within hours: not rewritten every time');
    $db->query('UPDATE sw_remember_tokens SET created_at = NOW() - INTERVAL 181 DAY'); $lapse($h); eq($who($h), null, 'older than the ceiling: sign in again'); eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_remember_tokens')['n'], 0);
});
test('an expired, tampered or unknown cookie signs nobody in, and is cleared; a wrong secret also deletes the token it targeted', function () use ($signin, $lapse, $me, $who, $db, $base) {
    $db->query('TRUNCATE sw_remember_tokens'); [$h] = $signin('rem', 'remember passphrase long', true); [$sel, $val] = explode('.', $h->cookies['sw_remember']);
    $lapse($h); $h->cookies['sw_remember'] = $sel . '.' . str_repeat('0', 64); $r = $me($h); eq($r['status'], 401);
    ok(count(array_filter($r['raw_set_cookie'], fn ($c) => str_starts_with($c, 'sw_remember=') && (stripos($c, 'Max-Age=0') !== false || stripos($c, '1970') !== false))) >= 1, 'cookie cleared');
    eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_remember_tokens')['n'], 0, 'a wrong secret kills the token (guessing gets one try)');
    [$h2] = $signin('rem', 'remember passphrase long', true); $db->query('UPDATE sw_remember_tokens SET expires_at = NOW() - INTERVAL 1 MINUTE'); $lapse($h2); eq($who($h2), null);
    foreach (['garbage', 'x.y', str_repeat('a', 24) . '.' . str_repeat('b', 64), '../../etc.passwd'] as $c) { $h3 = new TestHttp($base); $h3->cookies['sw_remember'] = $c; eq($who($h3), null, $c); }
});
test('a password change, a disabled account or a deleted account ends every remembered device', function () use ($signin, $lapse, $me, $who, $db, $uid) {
    foreach (['password', 'disabled', 'deleted'] as $case) {
        $db->query('TRUNCATE sw_remember_tokens'); [$h] = $signin('other', 'other passphrase long here', true); $lapse($h); eq($me($h)['status'], 200, "$case: works before");
        if ($case === 'password') { $db->update('users', ['password' => password_hash('a new passphrase long', PASSWORD_DEFAULT)], ['username' => 'other']); }
        if ($case === 'disabled') { $db->update('users', ['password' => password_hash('other passphrase long here', PASSWORD_DEFAULT), 'disabled' => 1], ['username' => 'other']); }
        if ($case === 'deleted') { $db->query("DELETE FROM sw_users WHERE username = 'other'"); }
        $lapse($h); eq($who($h), null, $case); eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_remember_tokens')['n'], 0, "$case: token removed");
        if ($case === 'deleted') { seedAdmin('other', 'other passphrase long here', 'editor'); } else { $db->update('users', ['password' => password_hash('other passphrase long here', PASSWORD_DEFAULT), 'disabled' => 0], ['username' => 'other']); }
    }
});
test('signing out forgets this device (token and cookie)', function () use ($signin, $lapse, $me, $who, $db) {
    $db->query('TRUNCATE sw_remember_tokens'); [$h] = $signin('rem', 'remember passphrase long', true); $r = $h->api('POST', '/api/auth/logout', []); eq($r['status'], 200);
    eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_remember_tokens')['n'], 0); $saved = $h->cookies['sw_remember'] ?? ''; ok($saved === '' || $saved === 'deleted', 'cookie dropped');
    $h->cookies['sw_remember'] = 'x'; $lapse($h);
});
test('two-step verification is part of the first sign-in, not repeated for a remembered device; a password alone never gets a token', function () use ($signin, $lapse, $me, $who, $db, $key, $base) {
    $db->query('TRUNCATE sw_remember_tokens'); [$h, $r] = $signin('mfa2', 'mfa two passphrase long', true); eq($r['json']['data'], ['mfa_required' => true]); eq(count($db->fetchAll('SELECT 1 FROM sw_remember_tokens')), 0, 'no token before the code'); ok(!isset($h->cookies['sw_remember']));
    $lapse($h); eq($who($h), null, 'the pending sign-in is not a session');
    [$h, ] = $signin('mfa2', 'mfa two passphrase long', true); $r = $h->api('POST', '/api/auth/mfa', ['code' => Totp::code($key, intdiv(time(), 30))]); eq($r['status'], 200, $r['body']);
    ok(isset($h->cookies['sw_remember']), 'token after the code'); $lapse($h); eq($who($h), 'mfa2');
    [$h, ] = $signin('mfa2', 'mfa two passphrase long', true); $h->api('POST', '/api/auth/mfa', ['code' => '000000']); ok(!isset($h->cookies['sw_remember']) || count($db->fetchAll('SELECT 1 FROM sw_remember_tokens')) === 1, 'a wrong code makes no token');
});
test('the setting turns it off (0) and the cookie is never issued; default is 30 days, capped at 90', function () use ($signin, $off, $db) {
    $d = Settings::registry()['session.remember_days']; eq([$d['default'], $d['min'], $d['max']], [30, 0, 90]);
    $db->query('TRUNCATE sw_remember_tokens'); [$h, $r] = $signin('rem', 'remember passphrase long', true, $off['base']); eq($r['status'], 200); ok(!isset($h->cookies['sw_remember'])); eq(count($db->fetchAll('SELECT 1 FROM sw_remember_tokens')), 0);
});
test('signed-in devices: you see only your own, can remove one, or all; others cannot touch them', function () use ($signin, $db, $base) {
    $db->query('TRUNCATE sw_remember_tokens'); [$a1] = $signin('rem', 'remember passphrase long', true); [$a2] = $signin('rem', 'remember passphrase long', true); [$o] = $signin('other', 'other passphrase long here', true);
    $l = $a1->api('GET', '/api/auth/devices')['json']['data']['devices']; eq(count($l), 2); eq(count(array_filter($l, fn ($d) => $d['current'])), 1); ok(isset($l[0]['id'], $l[0]['browser'], $l[0]['created_at'], $l[0]['last_used_at']) && !str_contains(json_encode($l), 'validator'));
    $otherId = (int)$o->api('GET', '/api/auth/devices')['json']['data']['devices'][0]['id']; eq($a1->api('DELETE', "/api/auth/devices/$otherId")['status'], 404, 'not yours');
    $mine = array_values(array_filter($l, fn ($d) => !$d['current']))[0]['id']; eq($a1->api('DELETE', "/api/auth/devices/$mine")['status'], 200); eq(count($a1->api('GET', '/api/auth/devices')['json']['data']['devices']), 1);
    eq($a1->api('DELETE', '/api/auth/devices')['status'], 200); eq(count($a1->api('GET', '/api/auth/devices')['json']['data']['devices']), 0); eq(count($o->api('GET', '/api/auth/devices')['json']['data']['devices']), 1, 'someone else untouched');
});
test('expired tokens are purged by the hourly housekeeping', function () use ($db) {
    $db->query('TRUNCATE sw_remember_tokens'); foreach ([-2, 5] as $d) { $db->insert('remember_tokens', ['user_id' => 1, 'selector' => bin2hex(random_bytes(12)), 'validator_hash' => hash('sha256', 'x'), 'pwf' => 'x', 'expires_at' => gmdate('Y-m-d H:i:s', time() + $d * 86400)]); }
    $out = Jobs::maintenance(); ok(isset($out['remember_tokens'])); eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_remember_tokens')['n'], 1);
});
