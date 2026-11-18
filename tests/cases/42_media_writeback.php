<?php
use Sitewright\Core\{Config, Crypto, MediaController, MediaWriteback};

// Writing image alt text and titles back to a WordPress site: every safeguard, and undo.
if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb(); $key = Crypto::generateKey(); Config::set('app_key', $key);
seedAdmin('root', 'root passphrase long here', 'admin'); seedAdmin('ed', 'editor passphrase long here', 'editor');
$srv = startServer(['SW_APP_KEY' => $key]); $base = $srv['base'];
$B = 'https://p.example/?rest_route=/sitewright/v1'; $json = fn ($d, int $s = 200) => [$s, ['Content-Type' => 'application/json'], json_encode($d)];
$reset = function () use ($db) { foreach (['sites', 'image_seo', 'media_changes', 'jobs', 'rate_limits'] as $t) { $db->query("TRUNCATE sw_$t"); }
    $id = (int)$db->insert('sites', ['name' => 'P', 'base_url' => 'https://p.example', 'secret_enc' => 'x']); $db->update('sites', ['secret_enc' => Crypto::encrypt('s3cret', "site:$id")], ['id' => $id]); return $id; };
$img = fn (int $site, string $name, ?string $alt, ?string $title = null) => (int)$db->insert('image_seo', ['project_id' => $site, 'image_url' => "https://p.example/wp-content/uploads/$name.png", 'alt_text' => $alt, 'title' => $title]);
/** The site: version and the owner's approval are parameters; the plugin answers like the real one (previous values, per item). */
$wp = function (string $version = '1.2.0', bool $allowed = true, ?array &$sent = null, array $fail = []) use ($B, $json) {
    $store = ['a' => ['alt' => 'old alt a', 'title' => 'Old A'], 'b' => ['alt' => '', 'title' => 'Old B']]; $sent = [];
    fakeWeb(["$B/status" => $json(['plugin_version' => $version, 'features' => ['media_write' => $allowed]]),
        "$B/media-text" => function ($r) use ($json, &$sent, &$store, $fail) {
            $items = json_decode($r['body'], true)['items']; $sent[] = $items; $out = [];
            foreach ($items as $it) {
                preg_match('#/([a-z]+)\.png$#', $it['url'], $m); $n = $m[1];
                if (in_array($n, $fail, true) || !isset($store[$n])) { $out[] = ['url' => $it['url'], 'ok' => false, 'error' => 'not an image in the media library']; continue; }
                $out[] = ['url' => $it['url'], 'ok' => true, 'id' => ord($n), 'previous' => $store[$n]];
                if (isset($it['alt'])) { $store[$n]['alt'] = $it['alt']; } if (isset($it['title'])) { $store[$n]['title'] = $it['title']; }
            }
            return $json(['results' => $out]);
        }]);
};

test('apply: only the chosen images of THIS site, only text that was typed, sent in one signed request; every change is logged with the old text', function () use ($reset, $db, $img, $wp) {
    $site = $reset(); $other = (int)$db->insert('sites', ['name' => 'Q', 'base_url' => 'https://q.example', 'secret_enc' => 'x']);
    $a = $img($site, 'a', 'Fresh alt for a', 'New A'); $b = $img($site, 'b', 'Alt for b'); $empty = $img($site, 'c', '', ''); $foreign = $img($other, 'a', 'must not be sent'); $unchosen = $img($site, 'd', 'not chosen');
    $wp('1.2.0', true, $sent);
    $r = MediaWriteback::apply($site, [$a, $b, $empty, $foreign, 99999], 7);
    eq($r, ['applied' => 2, 'failed' => [], 'skipped' => 3], json_encode($r));
    eq($sent, [[['url' => 'https://p.example/wp-content/uploads/a.png', 'alt' => 'Fresh alt for a', 'title' => 'New A'], ['url' => 'https://p.example/wp-content/uploads/b.png', 'alt' => 'Alt for b']]], 'nothing else was sent: no empty text, nothing from another site, nothing unchosen');
    $c = $db->fetchAll('SELECT image_url, old_alt, new_alt, old_title, new_title, applied_by, undone_at FROM sw_media_changes ORDER BY id');
    eq([$c[0]['old_alt'], $c[0]['new_alt'], $c[0]['old_title'], $c[0]['new_title'], (int)$c[0]['applied_by']], ['old alt a', 'Fresh alt for a', 'Old A', 'New A', 7]); eq([$c[1]['old_alt'], $c[1]['new_alt'], $c[1]['new_title']], ['', 'Alt for b', null], 'a title that was not sent is not logged as changed');
});

test('apply is refused, with the way forward, when the plugin is too old, the owner has not allowed it, or the site has no plugin; nothing is sent or logged', function () use ($reset, $db, $img, $wp) {
    $site = $reset(); $a = $img($site, 'a', 'x');
    foreach ([['1.1.0', true, 'Update the Sitewright plugin'], ['1.2.0', false, 'tick "Change image text"']] as [$v, $allowed, $say]) {
        $wp($v, $allowed, $sent); $e = throws(Sitewright\Core\ResponseException::class, fn () => MediaWriteback::apply($site, [$a], null)); eq($e->status, 409); ok(str_contains((string)(json_decode($e->body, true)['message'] ?? ''), $say), $e->body); eq($sent, []);
    }
    $db->query('UPDATE sw_sites SET sync_enabled = 0'); $e = throws(Sitewright\Core\ResponseException::class, fn () => MediaWriteback::apply($site, [$a], null)); eq($e->status, 409); ok(str_contains($e->body, 'does not use the Sitewright plugin'), $e->body);
    eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_media_changes')['n'], 0);
    $db->query('UPDATE sw_sites SET sync_enabled = 1'); fakeWeb(["https://p.example/?rest_route=/sitewright/v1/status" => [401, ['Content-Type' => 'application/json'], '{"message":"Bad signature"}']]);
    $e = throws(Sitewright\Core\ResponseException::class, fn () => MediaWriteback::apply($site, [$a], null)); eq($e->status, 502); ok(str_contains($e->body, 'secret'), $e->body);
    throws(Sitewright\Core\ResponseException::class, fn () => MediaWriteback::apply($site, [], null)); throws(Sitewright\Core\ResponseException::class, fn () => MediaWriteback::apply($site, range(1, 201), null));
    $wp('1.2.0', true, $sent); eq(MediaWriteback::apply($site, [$img($site, 'z', '')], null), ['applied' => 0, 'failed' => [], 'skipped' => 1]); eq($sent, [], 'only empty boxes: the site is not even contacted');
});

test('a plugin that refuses one item: that one is reported and not logged, the others are applied', function () use ($reset, $db, $img, $wp) {
    $site = $reset(); $a = $img($site, 'a', 'good'); $b = $img($site, 'b', 'refused'); $wp('1.2.0', true, $sent, ['b']);
    $r = MediaWriteback::apply($site, [$a, $b], null); eq($r['applied'], 1); eq($r['failed'], [['url' => 'https://p.example/wp-content/uploads/b.png', 'error' => 'not an image in the media library']]);
    eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_media_changes')['n'], 1);
});

test('undo puts the previous text back (and only what was changed), once; the change list shows it', function () use ($reset, $db, $img, $wp) {
    $site = $reset(); $a = $img($site, 'a', 'Fresh alt for a', 'New A'); $b = $img($site, 'b', 'Alt for b'); $wp('1.2.0', true, $sent); MediaWriteback::apply($site, [$a, $b], 1); $sent = [];
    $ids = array_column($db->fetchAll('SELECT id FROM sw_media_changes ORDER BY id'), 'id');
    MediaWriteback::undo((int)$ids[0], 1); eq($sent, [[['url' => 'https://p.example/wp-content/uploads/a.png', 'alt' => 'old alt a', 'title' => 'Old A']]]);
    $sent = []; MediaWriteback::undo((int)$ids[1], 1); eq($sent, [[['url' => 'https://p.example/wp-content/uploads/b.png', 'alt' => '']]], 'the title was not changed, so it is not touched; the empty old alt text is restored');
    $e = throws(Sitewright\Core\ResponseException::class, fn () => MediaWriteback::undo((int)$ids[0], 1)); eq($e->status, 409); ok(str_contains((string)(json_decode($e->body, true)['message'] ?? ''), 'already undone'), $e->body);
    throws(Sitewright\Core\ResponseException::class, fn () => MediaWriteback::undo(99999, 1));
    $wp('1.2.0', false); $db->query('UPDATE sw_media_changes SET undone_at = NULL'); $e = throws(Sitewright\Core\ResponseException::class, fn () => MediaWriteback::undo((int)$ids[0], 1)); eq($e->status, 409, 'undo needs the owner\'s approval too');
    $list = MediaWriteback::recent($site); eq(count($list), 2); eq((int)$list[0]['id'], (int)$ids[1], 'newest first');
});

test('API: administrators only; the answers carry no secrets', function () use ($reset, $db, $img, $wp, $base) {
    $site = $reset(); $a = $img($site, 'a', 'x'); $e = new TestHttp($base); eq($e->login('ed', 'editor passphrase long here')['status'], 200);
    eq($e->api('POST', "/api/sites/$site/media-text", ['image_ids' => [$a]])['status'], 403); eq($e->api('GET', "/api/sites/$site/media-changes")['status'], 403); eq($e->api('POST', '/api/media-changes/1/undo')['status'], 403);
    $wp('1.2.0', true, $sent); $r = call(MediaController::class, 'apply', ['method' => 'POST', 'body' => ['image_ids' => [$a]]], ['id' => (string)$site]); eq($r['status'], 200, json_encode($r['json'])); eq($r['json']['data']['applied'], 1);
    $l = call(MediaController::class, 'changes', [], ['id' => (string)$site]); eq(count($l['json']['data']['changes']), 1); ok(!str_contains(json_encode($l['json']), 's3cret'));
});
