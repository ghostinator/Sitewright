<?php
use Sitewright\Core\{Config, Crypto, SiteRemote, SiteSync};

// Phase 4c: warnings built on the version and update counts a site owner approved.
if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb(); Config::set('app_key', Crypto::generateKey());
Config::set('alerts', ['email_to' => [], 'teams_webhook' => 'https://example.webhook.office.com/x', 'telegram_token' => '', 'telegram_chat_id' => '', 'cooldown' => 0, 'login_failures' => 3,
    'events' => ['updates_waiting', 'php_outdated']]);
$B = 'https://p.example/?rest_route=/sitewright/v1';
$json = fn ($d) => [200, ['Content-Type' => 'application/json'], json_encode($d)];
$mk = function () use ($db) {
    foreach (['sites', 'site_remote', 'jobs', 'rate_limits', '404_monitor', 'site_profile'] as $t) { $db->query("TRUNCATE sw_$t"); }
    $id = (int)$db->insert('sites', ['name' => 'Corner', 'base_url' => 'https://p.example', 'secret_enc' => 'x']);
    $db->update('sites', ['secret_enc' => Crypto::encrypt('s3cret', "site:$id")], ['id' => $id]); return $id;
};
/** One sync in which the owner has approved only version counts. */
$sync = function (int $id, array $versions) use ($B, $json) {
    fakeWeb(["$B/status" => $json(['plugin_version' => '1.1.0', 'features' => ['versions' => true, 'site' => false, 'content' => false, 'seo' => false, 'redirects' => false, 'store' => false, 'media' => false]]),
        "$B/404s&since_seq=0&limit=500" => $json(['items' => [], 'max_seq' => 0]), "$B/redirects" => $json(['accepted' => 0]),
        "$B/versions" => $json($versions + ['wordpress' => '6.8', 'php' => '8.4.1', 'core_update' => false, 'plugin_updates' => 0, 'theme_updates' => 0, 'active_plugins' => 10, 'checked_at' => time()])]);
    SiteSync::handleJob(['site_id' => $id, 'force' => true]);
};
$msgs = fn () => array_map(fn ($r) => (($p = json_decode($r['payload'], true))['event']) . ': ' . $p['message'], $db->fetchAll("SELECT payload FROM sw_jobs WHERE type = 'alert' ORDER BY id"));

test('updates: the first sight of waiting updates warns once; more of them warn again; the same count does not; after they are installed the next one warns', function () use ($mk, $sync, $msgs) {
    $id = $mk();
    $sync($id, []); eq($msgs(), [], 'nothing waiting, nothing said');
    $sync($id, ['plugin_updates' => 3]); eq(count($msgs()), 1); ok(str_contains($msgs()[0], 'updates_waiting: Corner has 3 plugin updates waiting'), $msgs()[0]);
    $sync($id, ['plugin_updates' => 3]); eq(count($msgs()), 1, 'same count: quiet');
    $sync($id, ['plugin_updates' => 2]); eq(count($msgs()), 1, 'fewer: quiet');
    $sync($id, ['plugin_updates' => 4, 'core_update' => true]); eq(count($msgs()), 2); ok(str_contains($msgs()[1], 'a WordPress update, 4 plugin updates'), $msgs()[1]);
    $sync($id, []); $sync($id, ['theme_updates' => 1]); eq(count($msgs()), 3); ok(str_contains($msgs()[2], '1 theme update waiting'), $msgs()[2]);
});

test('PHP: a version that no longer gets security fixes is an error once; one about to lose them is a warning; a supported one is quiet', function () use ($mk, $sync, $msgs) {
    $id = $mk(); $sync($id, ['php' => '8.4.1']); eq($msgs(), [], 'supported');
    $sync($id, ['php' => '7.4.33']); eq(count($msgs()), 1); ok(str_contains($msgs()[0], 'php_outdated: Corner runs PHP 7.4.33, which no longer gets security fixes'), $msgs()[0]);
    $sync($id, ['php' => '7.4.33']); eq(count($msgs()), 1, 'said once');
    $sync($id, ['php' => '8.4.1']); $sync($id, ['php' => '8.1.2']); eq(count($msgs()), 2); ok(str_contains($msgs()[1], 'ended 2025-12-31'), $msgs()[1]);
});

test('PHP support dates: ended, soon (within 90 days) and fine are told apart; an unknown or odd version says nothing', function () {
    $t = fn (string $php, string $today) => SiteRemote::phpSupport($php, strtotime($today . ' 12:00:00 UTC'))['php_support'];
    eq([$t('8.1.9', '2026-11-17'), $t('8.2.5', '2026-11-17'), $t('8.2.5', '2026-09-01'), $t('8.3.0', '2026-11-17'), $t('7.2', '2026-11-17'), $t('9.9.1', '2026-11-17'), $t('banana', '2026-11-17'), $t('', '2026-11-17')],
        ['ended', 'soon', null, null, 'ended', null, null, null]);
});
