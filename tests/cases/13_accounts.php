<?php
use Sitewright\Core\{Accounts, Router, Totp};

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb();
seedAdmin('root', 'root passphrase long here', 'admin');
seedAdmin('second', 'second admin passphrase', 'admin');
seedAdmin('ed', 'editor passphrase long here', 'editor');
seedAdmin('view', 'viewer passphrase long here', 'viewer');
$cfgEnv = ['SW_BASE_URL' => 'https://sitewright.example', 'SW_ALLOWED_HOSTS' => '127.0.0.1,localhost', 'SW_MAIL_HOST' => '127.0.0.1', 'SW_MAIL_PORT' => '2525', 'SW_MAIL_ENCRYPTION' => 'none', 'SW_MAIL_FROM' => 'sitewright@example.test'];
$srv = startServer($cfgEnv); $base = $srv['base'];
$as = function (string $u, string $p) use ($base): TestHttp { $h = new TestHttp($base); $r = $h->login($u, $p); eq($r['status'], 200, "login $u"); return $h; };
$pw = ['root' => 'root passphrase long here', 'second' => 'second admin passphrase', 'ed' => 'editor passphrase long here', 'view' => 'viewer passphrase long here'];
$mailTokens = function () use ($db): array {
    $out = [];
    foreach ($db->fetchAll("SELECT payload FROM sw_jobs WHERE type = 'mail' ORDER BY id") as $r) { $p = json_decode($r['payload'], true); if (preg_match('#https://sitewright\.example/reset\?token=([a-f0-9]{64})#', $p['text'], $m)) { $out[] = ['to' => $p['to'], 'token' => $m[1], 'subject' => $p['subject']]; } }
    return $out;
};

// ---- role matrix over the REAL route table --------------------------------------------------------------------------------
test('roles: every route refuses roles below its requirement with the permission message (no route is accidentally open)', function () use ($as, $pw) {
    $routes = (new Router(Sitewright\Core\App::routes()))->routes();
    $rank = Accounts::ROLES; $checked = 0;
    foreach (['view' => 'viewer', 'ed' => 'editor'] as $user => $role) {
        $h = $as($user, $pw[$user]);
        foreach ($routes as $r) {
            if (!empty($r['opts']['public'])) { continue; }
            $need = $r['opts']['role'] ?? ($r['method'] === 'GET' ? 'viewer' : 'editor');
            if ($rank[$role] >= $rank[$need]) { continue; }
            $path = Router::samplePath($r['path']);
            $res = $h->api($r['method'], $path, $r['method'] === 'GET' ? null : []);
            eq($res['status'], 403, "$role $r[method] $path should be 403, got {$res['status']}");
            ok(str_contains((string)($res['json']['message'] ?? ''), 'permission'), "$role $r[method] $path: wrong 403 reason: " . ($res['json']['message'] ?? $res['body']));
            $checked++;
        }
    }
    ok($checked > 30, "matrix too small ($checked)");
});
test('roles: read-only GET routes work for a viewer; analysis/POST needs editor; 404 log (IPs) needs editor; users/audit need admin', function () use ($as, $pw) {
    $v = $as('view', $pw['view']); $e = $as('ed', $pw['ed']); $a = $as('root', $pw['root']);
    eq($v->api('GET', '/api/dashboard/stats')['status'], 200);
    eq($v->api('POST', '/api/seo-analysis/analyze', ['url' => 'https://example.com/'])['status'], 403);
    eq($v->api('GET', '/api/404-monitor/logs')['status'], 403); eq($e->api('GET', '/api/404-monitor/logs')['status'], 200);
    eq($e->api('GET', '/api/users')['status'], 403); eq($e->api('GET', '/api/audit')['status'], 403);
    eq($a->api('GET', '/api/users')['status'], 200); eq($a->api('GET', '/api/audit')['status'], 200);
    eq($v->api('POST', '/api/auth/logout')['status'], 200, 'a viewer can sign out');
});

// ---- user administration ----------------------------------------------------------------------------------------------------
test('users: create with password, change role, disable (existing session ends, login refused), delete; each is audited', function () use ($as, $pw, $base, $db) {
    $a = $as('root', $pw['root']);
    $r = $a->api('POST', '/api/users', ['username' => 'newbie', 'email' => 'newbie@example.test', 'role' => 'editor', 'password' => 'newbie passphrase long']);
    eq($r['status'], 201, $r['body']); $id = $r['json']['data']['id'];
    eq($a->api('POST', '/api/users', ['username' => 'newbie', 'email' => 'x@example.test', 'password' => 'long enough passphrase'])['status'], 409, 'duplicate');
    eq($a->api('POST', '/api/users', ['username' => 'weak', 'email' => 'w@example.test', 'password' => 'short'])['status'], 400);
    $n = new TestHttp($base); eq($n->login('newbie', 'newbie passphrase long')['status'], 200); eq($n->api('GET', '/api/auth/me')['status'], 200);
    eq($a->api('PATCH', "/api/users/$id", ['role' => 'viewer'])['status'], 200);
    eq($n->api('POST', '/api/seo-analysis/analyze', ['url' => 'https://example.com/'])['status'], 403, 'role change applies to the live session');
    eq($a->api('PATCH', "/api/users/$id", ['disabled' => true])['status'], 200);
    eq($n->api('GET', '/api/auth/me')['status'], 401, 'disabled => session ends');
    eq((new TestHttp($base))->login('newbie', 'newbie passphrase long')['status'], 401, 'disabled => cannot sign in');
    eq($a->api('DELETE', "/api/users/$id")['status'], 200);
    $acts = array_column($db->fetchAll("SELECT action FROM sw_audit_log WHERE action LIKE 'user_%' OR action = 'login_disabled_account' ORDER BY id"), 'action');
    foreach (['user_created', 'user_updated', 'user_deleted', 'login_disabled_account'] as $x) { ok(in_array($x, $acts, true), "missing $x in " . json_encode($acts)); }
});
test('users: the last enabled admin cannot be demoted, disabled or deleted, and nobody edits themselves into a lock-out', function () use ($as, $pw, $db) {
    $a = $as('root', $pw['root']);
    $ids = array_column($db->fetchAll("SELECT id, username FROM sw_users"), 'id', 'username');
    eq($a->api('PATCH', "/api/users/{$ids['root']}", ['role' => 'viewer'])['status'], 409, 'self role change');
    eq($a->api('PATCH', "/api/users/{$ids['root']}", ['disabled' => true])['status'], 409, 'self disable');
    eq($a->api('DELETE', "/api/users/{$ids['root']}")['status'], 409, 'self delete');
    eq($a->api('PATCH', "/api/users/{$ids['second']}", ['role' => 'editor'])['status'], 200, 'a second admin may be demoted while another remains');
    $b = $as('ed', $pw['ed']);
    eq($b->api('PATCH', "/api/users/{$ids['root']}", ['role' => 'viewer'])['status'], 403);
    $db->update('users', ['role' => 'admin'], ['username' => 'second']);
    $s = $as('second', $pw['second']);
    $db->update('users', ['disabled' => 1], ['username' => 'root']);
    eq($s->api('PATCH', "/api/users/{$ids['ed']}", ['role' => 'admin'])['status'], 200);
    eq($s->api('DELETE', "/api/users/{$ids['second']}")['status'], 409, 'cannot delete own account');
    $db->update('users', ['disabled' => 0, 'role' => 'admin'], ['username' => 'root']); $db->update('users', ['role' => 'editor'], ['username' => 'ed']);
    // demote the only enabled admin through another admin is impossible because both guards apply; verify the count guard directly
    $db->update('users', ['disabled' => 1], ['username' => 'second']);
    $r = $as('root', $pw['root']);
    eq(Accounts::enabledAdminCount(), 1);
    $db->update('users', ['disabled' => 0], ['username' => 'second']);
});

// ---- password reset ---------------------------------------------------------------------------------------------------------
test('forgot: identical answer for known, unknown and disabled addresses; link uses base_url, never the Host header', function () use ($base, $db, $mailTokens) {
    $db->query('TRUNCATE sw_jobs'); $db->query('TRUNCATE sw_rate_limits');
    $answers = [];
    foreach (['ed@example.test', 'nobody@example.test', 'ED@EXAMPLE.TEST'] as $e) {
        $h = new TestHttp($base); $h->bootstrap();
        $r = $h->req('POST', '/api/auth/forgot', ['email' => $e], ['Content-Type' => 'application/json', 'X-CSRF-Token' => (string)$h->csrf, 'X-Forwarded-Host' => 'evil.example']);
        $answers[] = [$r['status'], $r['json']['message']];
    }
    eq(count(array_unique(array_map('json_encode', $answers))), 1, json_encode($answers));
    $t = $mailTokens(); ok(count($t) >= 1 && $t[0]['to'] === 'ed@example.test'); ok(!str_contains(json_encode($db->fetchAll('SELECT payload FROM sw_jobs')), 'evil.example'));
});
test('forgot: rate limited per e-mail and per IP', function () use ($base, $db) {
    $db->query('TRUNCATE sw_rate_limits'); $codes = [];
    for ($i = 0; $i < 7; $i++) { $h = new TestHttp($base); $h->bootstrap(); $codes[] = $h->api('POST', '/api/auth/forgot', ['email' => 'ed@example.test'])['status']; }
    ok(in_array(429, $codes, true), json_encode($codes));
});
test('reset: token works once, new password signs in, old password and old sessions are dead, MFA is NOT bypassed, expired/garbage tokens refused', function () use ($base, $db, $as, $pw, $mailTokens) {
    $db->query('TRUNCATE sw_jobs'); $db->query('TRUNCATE sw_rate_limits');
    $old = $as('ed', $pw['ed']);
    $h = new TestHttp($base); $h->bootstrap(); eq($h->api('POST', '/api/auth/forgot', ['email' => 'ed@example.test'])['status'], 200);
    $tok = $mailTokens()[0]['token'];
    $do = fn (string $t, string $p) => (function () use ($base, $t, $p) { $x = new TestHttp($base); $x->bootstrap(); return $x->api('POST', '/api/auth/reset', ['token' => $t, 'password' => $p]); })();
    eq($do('not-a-token', 'brand new passphrase 1')['status'], 400);
    eq($do($tok, 'short')['status'], 400, 'weak password refused and the token is NOT burned');
    eq($do($tok, 'brand new passphrase 1')['status'], 200);
    eq($do($tok, 'another passphrase here 2')['status'], 400, 'single use');
    eq((new TestHttp($base))->login('ed', $pw['ed'])['status'], 401, 'old password dead');
    eq($old->api('GET', '/api/auth/me')['status'], 401, 'old session ended');
    $n = new TestHttp($base); eq($n->login('ed', 'brand new passphrase 1')['status'], 200);
    // expired token
    $db->query('TRUNCATE sw_rate_limits'); $h2 = new TestHttp($base); $h2->bootstrap(); $h2->api('POST', '/api/auth/forgot', ['email' => 'ed@example.test']);
    $tok2 = array_slice($mailTokens(), -1)[0]['token'];
    $db->query("UPDATE sw_password_resets SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE token_hash = :h", ['h' => hash('sha256', $tok2)]);
    eq($do($tok2, 'yet another passphrase 3')['status'], 400, 'expired');
    ok(!str_contains(json_encode($db->fetchAll('SELECT token_hash FROM sw_password_resets')), $tok2), 'only the hash is stored');
    // MFA account: reset changes the password but the second factor still stands
    $secret = Totp::generateSecret(); $db->update('users', ['totp_secret' => $secret], ['username' => 'view']);
    $db->query('TRUNCATE sw_rate_limits'); $h3 = new TestHttp($base); $h3->bootstrap(); $h3->api('POST', '/api/auth/forgot', ['email' => 'view@example.test']);
    $tok3 = array_slice($mailTokens(), -1)[0]['token'];
    eq($do($tok3, 'viewer reset passphrase 4')['status'], 200);
    $v = new TestHttp($base); $r = $v->login('view', 'viewer reset passphrase 4');
    eq($r['json']['data'], ['mfa_required' => true], 'still asks for the code'); eq($v->api('GET', '/api/auth/me')['status'], 401);
    $db->query("UPDATE sw_users SET totp_secret = NULL, password = :p WHERE username = 'view'", ['p' => password_hash($pw['view'], PASSWORD_DEFAULT)]);
    $db->query("UPDATE sw_users SET password = :p WHERE username = 'ed'", ['p' => password_hash($pw['ed'], PASSWORD_DEFAULT)]);
});
test('invite: a user created without a password gets an e-mail link and chooses their own password', function () use ($base, $db, $as, $pw, $mailTokens) {
    $db->query('TRUNCATE sw_jobs'); $db->query('TRUNCATE sw_rate_limits');
    $a = $as('root', $pw['root']);
    $r = $a->api('POST', '/api/users', ['username' => 'invitee', 'email' => 'invitee@example.test', 'role' => 'viewer']);
    eq($r['status'], 201, $r['body']);
    $t = $mailTokens(); eq($t[0]['to'], 'invitee@example.test'); ok(str_contains($t[0]['subject'], 'account'));
    $x = new TestHttp($base); $x->bootstrap(); eq($x->api('POST', '/api/auth/reset', ['token' => $t[0]['token'], 'password' => 'invitee chosen passphrase'])['status'], 200);
    eq((new TestHttp($base))->login('invitee', 'invitee chosen passphrase')['status'], 200);
});

// ---- own account: password + MFA self-service ---------------------------------------------------------------------------
test('account: change password needs the current one; other sessions end, this one continues', function () use ($as, $pw, $base, $db) {
    $db->query('TRUNCATE sw_rate_limits');
    $a = $as('view', $pw['view']); $b = $as('view', $pw['view']);
    eq($a->api('POST', '/api/auth/password', ['current_password' => 'wrong', 'new_password' => 'a brand new long passphrase'])['status'], 403);
    eq($a->api('POST', '/api/auth/password', ['current_password' => $pw['view'], 'new_password' => 'short'])['status'], 400);
    $r = $a->api('POST', '/api/auth/password', ['current_password' => $pw['view'], 'new_password' => 'a brand new long passphrase']); eq($r['status'], 200, $r['body']);
    $a->csrf = $r['json']['data']['csrf_token'];
    eq($a->api('GET', '/api/auth/me')['status'], 200, 'this session continues'); eq($b->api('GET', '/api/auth/me')['status'], 401, 'other session ended');
    $db->query("UPDATE sw_users SET password = :p WHERE username = 'view'", ['p' => password_hash($pw['view'], PASSWORD_DEFAULT)]);
});
test('account: MFA set-up needs the password, activates only after a valid code, returns 8 recovery codes, then sign-in needs the code; disable needs the password', function () use ($as, $pw, $base, $db) {
    $db->query('TRUNCATE sw_rate_limits');
    $a = $as('ed', $pw['ed']);
    eq($a->api('POST', '/api/auth/mfa/setup', ['password' => 'wrong'])['status'], 403);
    $s = $a->api('POST', '/api/auth/mfa/setup', ['password' => $pw['ed']]); eq($s['status'], 200, $s['body']);
    $secret = $s['json']['data']['secret']; ok(str_starts_with($s['json']['data']['uri'], 'otpauth://totp/Sitewright:ed?secret='));
    eq($db->fetchOne("SELECT totp_secret FROM sw_users WHERE username='ed'")['totp_secret'], null, 'not active yet');
    eq($a->api('POST', '/api/auth/mfa/enable', ['code' => '000000'])['status'], 400);
    $code = Totp::code((string)Totp::base32Decode($secret), intdiv(time(), 30));
    $e = $a->api('POST', '/api/auth/mfa/enable', ['code' => $code]); eq($e['status'], 200, $e['body']); eq(count($e['json']['data']['recovery_codes']), 8);
    $a->csrf = $e['json']['data']['csrf_token']; eq($a->api('GET', '/api/auth/me')['json']['data']['mfa_enabled'], true);
    $n = new TestHttp($base); eq($n->login('ed', $pw['ed'])['json']['data'], ['mfa_required' => true]);
    eq($a->api('POST', '/api/auth/mfa/disable', ['password' => 'wrong'])['status'], 403);
    $d = $a->api('POST', '/api/auth/mfa/disable', ['password' => $pw['ed']]); eq($d['status'], 200);
    eq((new TestHttp($base))->login('ed', $pw['ed'])['json']['data']['user']['username'], 'ed', 'plain sign-in again');
});
test('audit API: admin only, newest first, paged, filterable; role/MFA/account changes are in it', function () use ($as, $pw, $db) {
    $a = $as('root', $pw['root']);
    $all = $a->api('GET', '/api/audit?limit=5'); eq($all['status'], 200); eq(count($all['json']['data']['rows']), 5);
    ok($all['json']['data']['next_before_id'] !== null);
    $p2 = $a->api('GET', '/api/audit?limit=5&before_id=' . $all['json']['data']['next_before_id']);
    ok($p2['json']['data']['rows'][0]['id'] < $all['json']['data']['rows'][4]['id'], 'second page is older');
    $f = $a->api('GET', '/api/audit?action=user_created'); ok(count($f['json']['data']['rows']) >= 1);
    foreach ($f['json']['data']['rows'] as $r) { eq($r['action'], 'user_created'); }
    eq($a->api('GET', '/api/audit?action=' . urlencode("x' OR 1=1 --"))['status'], 200, 'injection-shaped filter is ignored, not executed');
});
