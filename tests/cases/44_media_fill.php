<?php
use Sitewright\Core\{AltText, Config, Crypto, JobFailedException, MediaFill, MediaWriteback};

// The worklist of images without alt text: suggestions, the scan, review, apply and skip.
$u = fn (string $f) => "https://p.example/wp-content/uploads/2026/10/$f";

test('suggestions: the page or product an image belongs to wins; otherwise readable words from the name; camera, tool and random-code noise gives nothing', function () use ($u) {
    $cases = [
        ['', $u('Gildan_18500_Front.png'), 'Live Laugh Pueblo Chili Pepper Canvas Tote Bag', 'Live Laugh Pueblo Chili Pepper Canvas Tote Bag (front view)', 'good'],
        ['Image 3', $u('x-324x243.jpg'), 'Handmade Pueblo Mug', 'Handmade Pueblo Mug', 'good'],
        ['WP-logo', $u('WP-logo.png'), '', 'Logo', 'fair'],
        ['', $u('Spicy-clothing-324x243.jpg'), '', 'Spicy clothing', 'fair'],
        ['', $u('Designer8-gigapixel-high-fidelity-v2-4x-324x243.webp'), '', 'Designer', 'fair'],
        ['', $u('Gemini_Generated_Image_d9ggy0d9ggy0d9gg-324x243.jpg'), '', '', 'none'],
        ['', $u('CleanShot-2026-10-05-at-8.16.24-PM-edited-2.png'), '', '', 'none'],
        ['', $u('IMG_20260704_123456.jpg'), '', '', 'none'],
        ['', $u('1234.jpg'), '', '', 'none'],
        ['', $u('288124a1-a0fe-4b5e-ada3-0e297012c4a5.jpg'), '', '', 'none'],
        ['288124a1-a0fe-4b5e-ada3-0e297012c4a5', $u('x.jpg'), '', '', 'none'],
        ['Riverwalk mural at sunset', $u('IMG_1234.jpg'), 'Blog', 'Riverwalk mural at sunset', 'fair'],
        ['', $u('Pueblo%20Riverwalk%20Mural.jpg'), 'Mural', 'Pueblo riverwalk mural', 'fair'],
    ];
    foreach ($cases as [$title, $url, $parent, $text, $conf]) { eq(AltText::suggest($title, $url, $parent), ['text' => $text, 'confidence' => $conf], "$title | " . basename($url) . " | $parent"); }
    $long = AltText::suggest('', $u('a.png'), str_repeat('Word ', 60)); ok(mb_strlen($long['text']) <= AltText::MAX && !str_ends_with($long['text'], ' '), (string)mb_strlen($long['text']));
    foreach (['<script>alert(1)</script> Mug Shop', "Two\nLines Here"] as $p) { $s = AltText::suggest('', $u('a.png'), $p); ok(!str_contains($s['text'], '<') && !str_contains($s['text'], "\n"), $s['text']); }
});

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb(); $key = Crypto::generateKey(); Config::set('app_key', $key);
seedAdmin('root', 'root passphrase long here', 'admin'); seedAdmin('ed', 'editor passphrase long here', 'editor');
$srv = startServer(['SW_APP_KEY' => $key]); $base = $srv['base'];
$B = 'https://p.example/?rest_route=/sitewright/v1'; $json = fn ($d, int $s = 200) => [$s, ['Content-Type' => 'application/json'], json_encode($d)];
$reset = function () use ($db) { foreach (['sites', 'image_seo', 'media_changes', 'media_missing', 'jobs', 'rate_limits'] as $t) { $db->query("TRUNCATE sw_$t"); }
    $id = (int)$db->insert('sites', ['name' => 'P', 'base_url' => 'https://p.example', 'secret_enc' => 'x']); $db->update('sites', ['secret_enc' => Crypto::encrypt('s3cret', "site:$id")], ['id' => $id]); return $id; };
/** The plugin: a media library with $items (id => [title, file, parent]); pages of 2 so paging is exercised. */
$wp = function (array $items, string $version = '1.3.0', array $features = ['media_list' => true, 'media_write' => true]) use ($B, $json, $u) {
    $pages = ["$B/status" => $json(['plugin_version' => $version, 'features' => $features])]; $ids = array_keys($items); sort($ids); $chunks = array_chunk($ids, 2); $cur = 0;
    foreach ($chunks as $i => $chunk) {
        $list = array_map(fn ($id) => ['id' => $id, 'url' => $u($items[$id][1]), 'title' => $items[$id][0], 'parent_title' => $items[$id][2] ?? '', 'parent_url' => ($items[$id][2] ?? '') !== '' ? 'https://p.example/shop/x/' : ''], $chunk);
        $pages["$B/media-list&cursor=$cur&limit=100"] = $json(['items' => $list, 'next' => isset($chunks[$i + 1]) ? end($chunk) : null]); $cur = end($chunk);
    }
    if (!$chunks) { $pages["$B/media-list&cursor=0&limit=100"] = $json(['items' => [], 'next' => null]); }
    $pages["$B/media-text"] = function ($r) use ($json) { $out = []; foreach (json_decode($r['body'], true)['items'] as $it) { $out[] = str_contains($it['url'], 'refused') ? ['url' => $it['url'], 'ok' => false, 'error' => 'not an image in the media library'] : ['url' => $it['url'], 'ok' => true, 'id' => 1, 'previous' => ['alt' => '', 'title' => 'T']]; } return $json(['results' => $out]); };
    fakeWeb($pages);
};

test('scan: reads the image list page by page, stores each image with a suggestion, drops anything not on the site\'s own host, cleans hostile text, and says so in words when the plugin or the owner\'s tick is missing', function () use ($reset, $wp, $db, $u) {
    $site = $reset();
    $wp([10 => ['', 'Gildan_Front.png', 'Chili Tote Bag'], 11 => ['WP-logo', 'WP-logo.png'], 12 => ['', 'Gemini_Generated_Image_d9ggy0d9ggy0d9gg.jpg'], 13 => ["<b>Mural</b>\nat night", 'mural.jpg'], 14 => ['', 'Spicy-clothing.jpg']]);
    $r = MediaFill::handleJob(['site_id' => $site]); eq($r['data'], ['seen' => 5, 'complete' => true]);
    $rows = array_column($db->fetchAll('SELECT attachment_id, url, title, parent_title, suggestion, confidence, status FROM sw_media_missing ORDER BY attachment_id'), null, 'attachment_id');
    eq(array_map(fn ($x) => [$x['suggestion'], $x['confidence']], $rows), [10 => ['Chili Tote Bag (front view)', 'good'], 11 => ['Logo', 'fair'], 12 => [null, 'none'], 13 => ['Mural at night', 'fair'], 14 => ['Spicy clothing', 'fair']]); eq($rows[13]['title'], 'Mural at night');
    $s = MediaFill::summary($site); eq([$s['open'], $s['good'], $s['fair'], $s['none']], [5, 1, 3, 1]);
    $wp([20 => ['', 'a.png']]); fakeWeb(['https://p.example/?rest_route=/sitewright/v1/status' => [200, ['Content-Type' => 'application/json'], json_encode(['plugin_version' => '1.3.0', 'features' => ['media_list' => true]])],
        'https://p.example/?rest_route=/sitewright/v1/media-list&cursor=0&limit=100' => [200, ['Content-Type' => 'application/json'], json_encode(['items' => [['id' => 30, 'url' => 'https://evil.example/x.png', 'title' => 'x'], ['id' => 31, 'url' => 'javascript:alert(1)', 'title' => 'x'], ['id' => 'a', 'url' => $u('b.png')], ['id' => 32, 'url' => $u('ok.png'), 'title' => 'Ok', 'parent_url' => 'https://evil.example/p']], 'next' => null])]]);
    MediaFill::handleJob(['site_id' => $site]); $ids = array_column($db->fetchAll('SELECT attachment_id FROM sw_media_missing ORDER BY attachment_id'), 'attachment_id'); eq(array_map('intval', $ids), [32], 'foreign addresses, scripts and bad ids are dropped, and images no longer missing alt text are removed');
    eq($db->fetchOne('SELECT parent_url FROM sw_media_missing WHERE attachment_id = 32')['parent_url'], null, 'a page address on another host is not kept');
});

test('scan: refused with the way forward when the plugin is too old or the owner has not ticked "Image list"; a re-scan keeps a person\'s typing and what was applied or skipped', function () use ($reset, $wp, $db) {
    $site = $reset();
    foreach ([['1.2.1', ['media_list' => true], 'Update the Sitewright plugin'], ['1.3.0', ['media_list' => false], 'tick "Image list']] as [$v, $f, $say]) {
        $wp([1 => ['', 'a.png']], $v, $f); $e = throws(JobFailedException::class, fn () => MediaFill::handleJob(['site_id' => $site])); ok(str_contains($e->getMessage(), $say), $e->getMessage()); eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_media_missing')['n'], 0);
    }
    $wp([1 => ['', 'a.png'], 2 => ['', 'b.png'], 3 => ['', 'c.png']]); MediaFill::handleJob(['site_id' => $site]);
    $db->query("UPDATE sw_media_missing SET suggestion = 'My own words' WHERE attachment_id = 1"); $db->query("UPDATE sw_media_missing SET status = 'applied' WHERE attachment_id = 2"); $db->query("UPDATE sw_media_missing SET status = 'skipped' WHERE attachment_id = 3");
    $wp([1 => ['New title', 'a.png'], 2 => ['', 'b.png'], 3 => ['', 'c.png']]); MediaFill::handleJob(['site_id' => $site]);
    $r = array_column($db->fetchAll('SELECT attachment_id, suggestion, status, title FROM sw_media_missing'), null, 'attachment_id');
    eq([$r[1]['suggestion'], $r[1]['title'], $r[2]['status'], $r[3]['status']], ['My own words', 'New title', 'applied', 'skipped']);
});

test('apply: the text the person reviewed is sent through the write-back, only images the site accepted are marked applied, a refused one stays open; skip leaves images alone', function () use ($reset, $wp, $db) {
    $site = $reset(); $wp([1 => ['', 'a.png', 'Mug'], 2 => ['', 'refused.png'], 3 => ['', 'c.png']]); MediaFill::handleJob(['site_id' => $site]);
    $r = MediaFill::apply($site, [['id' => 1, 'alt' => 'A <b>blue</b> mug on a table'], ['id' => 2, 'alt' => 'Anything'], ['id' => 3, 'alt' => ''], ['id' => 99, 'alt' => 'unknown'], ['id' => 'x', 'alt' => 'x'], 'junk'], 5);
    eq([$r['applied'], count($r['failed']), $r['skipped']], [1, 1, 4]); eq($r['failed'][0]['error'], 'not an image in the media library');
    $st = array_column($db->fetchAll('SELECT attachment_id, status, suggestion FROM sw_media_missing'), null, 'attachment_id'); eq([$st[1]['status'], $st[1]['suggestion'], $st[2]['status'], $st[3]['status']], ['applied', 'A blue mug on a table', 'open', 'open']);
    eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_media_changes')['n'], 1, 'one logged change, so it can be undone');
    eq(MediaFill::skip($site, [3, 3, 'x', 0, 77]), 1); eq($db->fetchOne('SELECT status FROM sw_media_missing WHERE attachment_id = 3')['status'], 'skipped'); eq(MediaFill::skip($site, [1]), 0, 'an applied image is not "skipped"');
    $wp([5 => ['', 'Gemini_Generated_Image_d9ggy0d9ggy0d9gg.jpg'], 6 => ['', 'Spicy-clothing.jpg']]); MediaFill::handleJob(['site_id' => $site]);
    eq(MediaFill::skipConfidence($site, 'none'), 1, 'only the open ones of that level'); eq($db->fetchOne('SELECT status FROM sw_media_missing WHERE attachment_id = 6')['status'], 'open'); throws(Sitewright\Core\ResponseException::class, fn () => MediaFill::skipConfidence($site, 'bogus'));
    foreach ([[], range(1, 201)] as $bad) { throws(Sitewright\Core\ResponseException::class, fn () => MediaFill::apply($site, $bad, null)); }
    $wp([1 => ['', 'a.png']], '1.3.0', ['media_list' => true, 'media_write' => false]); $db->query("UPDATE sw_media_missing SET status = 'open'");
    $e = throws(Sitewright\Core\ResponseException::class, fn () => MediaFill::apply($site, [['id' => 1, 'alt' => 'x']], null)); eq($e->status, 409); ok(str_contains((string)(json_decode($e->body, true)['message'] ?? ''), 'Change image text'), $e->body);
});

test('API: administrators only; scan answers in words before queueing; the list is paged and filtered by confidence', function () use ($reset, $wp, $db, $base) {
    $site = $reset(); $e = new TestHttp($base); eq($e->login('ed', 'editor passphrase long here')['status'], 200);
    foreach ([['GET', "/api/sites/$site/media-missing"], ['POST', "/api/sites/$site/media-scan"], ['POST', "/api/sites/$site/media-missing/apply"], ['POST', "/api/sites/$site/media-missing/skip"]] as [$m, $p]) { eq($e->api($m, $p, $m === 'GET' ? null : [])['status'], 403, "$m $p"); }
    $wp([1 => ['', 'a.png', 'Mug shop'], 2 => ['', 'Gemini_Generated_Image_d9ggy0d9ggy0d9gg.jpg'], 3 => ['', 'Spicy-clothing.jpg']]); MediaFill::handleJob(['site_id' => $site]);
    $all = call(Sitewright\Core\MediaFillController::class, 'show', [], ['id' => (string)$site]); eq(array_column($all['json']['data']['items'], 'confidence'), ['good', 'fair', 'none'], 'best suggestions first'); eq($all['json']['data']['summary']['open'], 3);
    Sitewright\Core\Request::fake(['query' => ['confidence' => 'none', 'limit' => '1']]); $r = Sitewright\Core\MediaFillController::class; $none = resp(fn () => (new $r())->show(['id' => (string)$site])); eq(count($none['json']['data']['items']), 1); eq($none['json']['data']['items'][0]['confidence'], 'none');
    eq(call($r, 'show', [], ['id' => '9999'])['status'], 404); $scan = call($r, 'scan', [], ['id' => (string)$site]); eq($scan['status'], 202); eq(call($r, 'scan', [], ['id' => (string)$site])['json']['data']['job_id'], $scan['json']['data']['job_id'], 'a scan already waiting is not queued twice');
    $sk = call($r, 'skip', ['method' => 'POST', 'body' => ['confidence' => 'none']], ['id' => (string)$site]); eq([$sk['status'], $sk['json']['data']['skipped']], [200, 1], json_encode($sk['json'])); eq(call($r, 'skip', ['method' => 'POST', 'body' => ['confidence' => 'x']], ['id' => (string)$site])['status'], 400);
    $wp([1 => ['', 'a.png']], '1.2.0'); $x = call($r, 'scan', [], ['id' => (string)$site]); eq($x['status'], 409);
});
