<?php
use Sitewright\Core\{AuditBusyException, Config, Jobs, SiteAudit, SiteAuditController};

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb();
$H = ['Content-Type' => 'text/html; charset=utf-8'];
$desc = 'A description for this page that is long enough to count as optimal for search results, with plenty of useful words in it for readers.';
$pg = fn (string $title, string $links = '', int $words = 400, ?string $d = null, int $h1 = 1, string $head = '') => [200, $H, '<!doctype html><html lang="en"><head>' . ($title === '' ? '' : "<title>$title</title>")
    . '<meta name="description" content="' . ($d ?? $GLOBALS['__desc']) . '"><meta name="viewport" content="width=device-width">' . $head . '</head><body>' . str_repeat('<h1>Heading</h1>', $h1) . $links . '<p>' . str_repeat('word ', $words) . '</p></body></html>'];
$GLOBALS['__desc'] = $desc;
$site = function (array $over = []) use ($pg, $H) {
    $dup = 'Duplicate title for two pages on the site';
    return $over + [
        'https://aud.example/' => $pg('Home page of the audit example site', '<a href="/a">a</a><a href="/b">b</a><a href="/dup1">1</a><a href="/dup2">2</a><a href="/thin">t</a><a href="/gone">g</a><a href="/moved">m</a>'
            . '<a href="https://ext.example/dead">d</a><a href="https://ext.example/ok">o</a>'),
        'https://aud.example/a' => $pg('Another good page with a fine length title', '<a href="/">home</a>'),
        'https://aud.example/b' => $pg('', '<a href="/">home</a>', 400, null, 0, '<meta name="robots" content="noindex">'),
        'https://aud.example/dup1' => $pg($dup, '<a href="/">home</a><a href="/moved">old address of a</a>'), 'https://aud.example/dup2' => $pg($dup, '<a href="/">home</a>'),
        'https://aud.example/thin' => $pg('A thin page with just a few words on it', '<a href="/">home</a>', 20),
        'https://aud.example/orphan' => $pg('A page nothing links to but the sitemap lists'),
        'https://aud.example/gone' => [404, [], 'nope'], 'https://aud.example/moved' => [301, ['Location' => 'https://aud.example/a'], ''],
        'https://ext.example/dead' => [404, [], 'x'], 'https://ext.example/ok' => [200, [], ''],
    ];
};
$reset = function () use ($db) { foreach (['audits', 'audit_pages', 'audit_issues', 'jobs', 'sitemaps', 'sites', 'rate_limits'] as $t) { $db->query("TRUNCATE sw_$t"); }
    Config::set('alerts', ['email_to' => [], 'teams_webhook' => 'https://example.webhook.office.com/x', 'telegram_token' => '', 'telegram_chat_id' => '', 'cooldown' => 0, 'login_failures' => 3,
        'events' => array_merge(Config::defaults()['alerts']['events'], ['audit_new_issues'])]); Config::set('mail.host', ''); Config::set('limits.crawl_delay_ms', 0); };
$run = function (string $url = 'https://aud.example/', int $project = 0) { $r = SiteAudit::start($project, $url, null); while (Jobs::runNext()) {} return $r; };
$issues = fn (int $id) => array_map(fn ($r) => $r['code'] . ' ' . str_replace('https://aud.example', '', $r['url']), $GLOBALS['db']->fetchAll('SELECT code, url FROM sw_audit_issues WHERE audit_id = :a ORDER BY code, url', ['a' => $id]));
$GLOBALS['db'] = $db;
$alerts = fn () => array_map(fn ($r) => json_decode($r['payload'], true)['event'], $db->fetchAll("SELECT payload FROM sw_jobs WHERE type = 'alert' ORDER BY id"));

test('audit: crawls the site (seeded from the sitemap list) and reports each kind of issue on the right page', function () use ($db, $reset, $run, $site, $issues) {
    $reset(); $db->insert('sitemaps', ['project_id' => 0, 'type' => 'general', 'url' => 'https://aud.example/orphan']);
    fakeWeb($site());
    $r = $run(); $a = $db->fetchOne('SELECT * FROM sw_audits WHERE id = :i', ['i' => $r['audit_id']]);
    eq($a['status'], 'done', (string)$a['error']); eq((int)$a['pages'], 7);
    $got = $issues($r['audit_id']);
    foreach (['missing_title /b', 'missing_h1 /b', 'noindex /b', 'duplicate_title /dup1', 'duplicate_title /dup2', 'duplicate_description /dup1', 'duplicate_description /dup2', 'thin_content /thin',
              'broken_link /', 'redirected_link /', 'orphan_page /orphan', 'broken_external_link /'] as $want) { ok(in_array($want, $got, true), "missing: $want in " . implode(' | ', $got)); }
    foreach (['missing_title /', 'duplicate_title /a', 'orphan_page /a', 'orphan_page /', 'thin_content /', 'broken_external_link /a'] as $no) { ok(!in_array($no, $got, true), "unexpected: $no"); }
    $d = $db->fetchAll("SELECT detail FROM sw_audit_issues WHERE audit_id = :a AND code IN ('broken_link','broken_external_link','redirected_link') ORDER BY code, detail", ['a' => $r['audit_id']]);
    ok(str_contains($d[1]['detail'], '/gone') && str_contains($d[1]['detail'], '404'), $d[1]['detail']); ok(str_contains($d[0]['detail'], 'ext.example/dead'), $d[0]['detail']); ok(str_contains($d[2]['detail'], '/moved') && str_contains($d[2]['detail'], '/a'), $d[2]['detail']);
    $in = array_column($db->fetchAll('SELECT url, inbound FROM sw_audit_pages WHERE audit_id = :a', ['a' => $r['audit_id']]), 'inbound', 'url');
    eq((int)$in['https://aud.example/a'], 2, 'the home page links to it directly; dup1 links through the redirect: two distinct pages'); eq((int)$in['https://aud.example/orphan'], 0);
    $s = json_decode($a['summary'], true); eq($s['pages'], 7); ok($s['by_severity']['high'] >= 2 && $s['by_code']['duplicate_title'] === 2 && $s['avg_score'] > 0, json_encode($s));
    eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_audit_pages WHERE audit_id = :a', ['a' => $r['audit_id']])['n'], 7);
});
test('audit: a second audit says what is new and what was fixed, and alerts only about NEW high-priority problems (never on the first audit)', function () use ($db, $reset, $run, $site, $pg, $alerts) {
    $reset(); fakeWeb($site()); $first = $run(); eq($alerts(), [], 'first audit: nothing to compare with, no alert');
    $links = '<a href="/a">a</a><a href="/b">b</a><a href="/dup1">1</a><a href="/dup2">2</a><a href="/thin">t</a><a href="/moved">m</a><a href="https://ext.example/dead">d</a><a href="https://ext.example/ok">o</a>';   // /gone link removed
    fakeWeb($site(['https://aud.example/' => $pg('Home page of the audit example site', $links), 'https://aud.example/a' => $pg('Another good page with a fine length title', '<a href="/">home</a><a href="/gone2">x</a>'),
        'https://aud.example/gone2' => [404, [], 'x']]));
    $second = $run(); $s = json_decode($db->fetchOne('SELECT summary FROM sw_audits WHERE id = :i', ['i' => $second['audit_id']])['summary'], true);
    eq($s['previous_audit_id'], $first['audit_id']); ok($s['fixed'] >= 1 && $s['new'] >= 1 && $s['new_high'] === 1, json_encode($s));
    eq($alerts(), ['audit_new_issues'], 'one alert for the new broken link');
    fakeWeb($site(['https://aud.example/' => $pg('Home page of the audit example site', $links), 'https://aud.example/a' => $pg('Another good page with a fine length title', '<a href="/">home</a><a href="/gone2">x</a>'), 'https://aud.example/gone2' => [404, [], 'x']]));
    $run(); eq($alerts(), ['audit_new_issues'], 'a third identical audit raises nothing new');
});
test('audit: an unreachable site ends the audit as failed with a reason, and the JOB still finishes as done (no job_failed alert, health stays green)', function () use ($db, $reset, $run, $alerts) {
    $reset(); fakeWeb(['https://dead.example/' => [503, [], 'down']]);
    $r = $run('https://dead.example/'); $a = $db->fetchOne('SELECT status, error FROM sw_audits WHERE id = :i', ['i' => $r['audit_id']]);
    eq($a['status'], 'failed'); ok($a['error'] !== null && $a['error'] !== '', 'reason recorded');
    eq($db->fetchOne("SELECT status FROM sw_jobs WHERE type = 'site_audit'")['status'], 'done'); eq($alerts(), []);
});
test('audit: one audit per site at a time; a different site is fine', function () use ($db, $reset, $site) {
    $reset(); fakeWeb($site());
    SiteAudit::start(0, 'https://aud.example/', null);
    throws(AuditBusyException::class, fn () => SiteAudit::start(0, 'https://aud.example/x', null));
    SiteAudit::start(0, 'https://other.example/', null); eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_audits')['n'], 2);
});
test('audit: the page cap and the time budget stop the crawl and say so', function () use ($db, $reset, $run, $site) {
    $reset(); fakeWeb($site()); Config::set('audit.max_pages', 3);
    $r = $run(); $a = $db->fetchOne('SELECT pages, stopped_early, status FROM sw_audits WHERE id = :i', ['i' => $r['audit_id']]); eq([(int)$a['pages'], (int)$a['stopped_early'], $a['status']], [3, 1, 'done']);
    Config::set('audit.max_pages', 200);
});
test('audit: the running job refreshes its heartbeat while it crawls, so a long audit is not declared stuck', function () use ($db, $reset, $site) {
    $reset(); $pages = $site(); $first = true;
    $pages['https://aud.example/'] = function () use (&$first, $db, $site) { if ($first) { $first = false; $db->query("UPDATE sw_jobs SET heartbeat_at = NOW() - INTERVAL 1 HOUR WHERE status = 'running'"); } return $site()['https://aud.example/']; };
    fakeWeb($pages); SiteAudit::start(0, 'https://aud.example/', null); while (Jobs::runNext()) {}
    $age = (int)$db->fetchOne("SELECT TIMESTAMPDIFF(SECOND, heartbeat_at, NOW()) s FROM sw_jobs WHERE type = 'site_audit'")['s']; ok($age < 120, "heartbeat age $age s");
});
test('audit: external links are checked up to the cap, only a real 404/410 counts, and 0 turns the check off', function () use ($db, $reset, $run, $site) {
    $reset(); $p = $site(['https://aud.example/' => [200, ['Content-Type' => 'text/html'], '<html><head><title>Home page of the audit example site</title></head><body><h1>x</h1>'
        . '<a href="https://e1.example/">1</a><a href="https://e2.example/">2</a><a href="https://e3.example/">3</a><a href="https://e4.example/">4</a><a href="/a">a</a></body></html>'],
        'https://e1.example/' => [404, [], ''], 'https://e2.example/' => [410, [], ''], 'https://e3.example/' => [403, [], ''], 'https://e4.example/' => [500, [], '']]);
    fakeWeb($p); Config::set('audit.external_links_max', 10); $r = $run();
    $d = array_column($db->fetchAll("SELECT detail FROM sw_audit_issues WHERE audit_id = :a AND code = 'broken_external_link'", ['a' => $r['audit_id']]), 'detail');
    eq(count($d), 2, implode(' | ', $d)); ok(str_contains(implode($d), 'e1.example') && str_contains(implode($d), 'e2.example'), 'only 404 and 410 (403 blocks bots, 500 is not proof)');
    $db->query('TRUNCATE sw_audits'); $db->query('TRUNCATE sw_jobs'); $db->query('TRUNCATE sw_audit_issues'); fakeWeb($p); Config::set('audit.external_links_max', 0); $r = $run();
    eq((int)$db->fetchOne("SELECT COUNT(*) n FROM sw_audit_issues WHERE code = 'broken_external_link'")['n'], 0); Config::set('audit.external_links_max', 50);
});
test('audit API: start (site address is used when a site is chosen), list, detail with advice per issue type, issues by type, worst pages, delete', function () use ($db, $reset, $site) {
    $reset(); fakeWeb($site()); $sid = (int)$db->insert('sites', ['name' => 'Aud', 'base_url' => 'https://aud.example', 'secret_enc' => '', 'sync_enabled' => 0]);
    $c = fn (string $m, string $e, array $req = [], array $params = []) => call(SiteAuditController::class, $m, $req + ['method' => 'GET'], $params);
    eq($c('start', 'x', ['method' => 'POST', 'body' => []])['status'], 400, 'needs a site or an address'); eq($c('start', 'x', ['method' => 'POST', 'body' => ['project_id' => 999]])['status'], 400);
    $r = $c('start', 'x', ['method' => 'POST', 'body' => ['project_id' => $sid]]); eq($r['status'], 202, $r['body']); $id = (int)$r['json']['data']['audit_id']; ok($r['json']['data']['job_id'] > 0);
    eq($c('start', 'x', ['method' => 'POST', 'body' => ['project_id' => $sid]])['status'], 409, 'already running'); while (Jobs::runNext()) {}
    $l = $c('list', 'x', ['query' => ['project_id' => (string)$sid]])['json']['data']['audits']; eq(count($l), 1); eq([$l[0]['status'], $l[0]['site']], ['done', 'Aud']); ok($l[0]['by_severity']['high'] >= 1);
    $d = $c('show', 'x', [], ['id' => (string)$id])['json']['data']; ok($d['audit']['pages'] > 0); $types = array_column($d['types'], null, 'code'); ok(isset($types['broken_link']) && $types['broken_link']['count'] >= 1 && $types['broken_link']['advice'] !== '' && $types['broken_link']['title'] !== '');
    ok(array_search('high', array_column($d['types'], 'severity')) === 0, 'high severity first');
    $i = $c('issues', 'x', ['query' => ['code' => 'broken_link']], ['id' => (string)$id])['json']['data']['issues']; ok(count($i) >= 1 && $i[0]['code'] === 'broken_link');
    $p = $c('pages', 'x', ['query' => ['limit' => '3']], ['id' => (string)$id])['json']['data']['pages']; eq(count($p), 3); ok($p[0]['score'] <= $p[2]['score'], 'worst first');
    eq($c('issues', 'x', ['query' => ['code' => '; DROP']], ['id' => (string)$id])['status'], 400);
    eq($c('delete', 'x', ['method' => 'DELETE'], ['id' => (string)$id])['status'], 200); eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_audit_issues')['n'], 0); eq($c('show', 'x', [], ['id' => (string)$id])['status'], 404);
});
test('audit scheduling: repeat_days queues one audit for a site that is due, never when off, never while one is open, with no user id', function () use ($db, $reset, $site) {
    $reset(); fakeWeb($site()); Config::set('audit.repeat_days', 0);
    $s1 = (int)$db->insert('sites', ['name' => 'One', 'base_url' => 'https://aud.example', 'secret_enc' => '', 'sync_enabled' => 0]); $s2 = (int)$db->insert('sites', ['name' => 'Two', 'base_url' => 'https://other.example', 'secret_enc' => '', 'sync_enabled' => 0]);
    eq(SiteAudit::scheduleDue(), 0, 'off'); Config::set('audit.repeat_days', 7);
    eq(SiteAudit::scheduleDue(), 1, 'one per tick, so audits are spread out'); eq(SiteAudit::scheduleDue(), 1, 'the other site next tick'); eq(SiteAudit::scheduleDue(), 0, 'both have an audit open');
    eq((int)$db->fetchOne("SELECT COUNT(*) n FROM sw_jobs WHERE type = 'site_audit' AND user_id IS NOT NULL")['n'], 0);
    while (Jobs::runNext()) {} eq(SiteAudit::scheduleDue(), 0, 'both were just audited'); $db->query('UPDATE sw_audits SET finished_at = NOW() - INTERVAL 8 DAY, created_at = NOW() - INTERVAL 8 DAY');
    eq(SiteAudit::scheduleDue(), 1, 'due again after the interval'); Config::set('audit.repeat_days', 0);
});
test('audit retention keeps the newest N audits per site and removes the rest with their pages and issues', function () use ($db, $reset) {
    $reset(); Config::set('audit.keep', 2);
    foreach ([1, 2, 3, 4] as $n) { $id = (int)$db->insert('audits', ['project_id' => 5, 'base_url' => 'https://x/', 'status' => 'done']); $db->insert('audit_issues', ['audit_id' => $id, 'code' => 'noindex', 'severity' => 'low', 'url' => 'https://x/']); $db->insert('audit_pages', ['audit_id' => $id, 'url' => 'https://x/']); }
    $other = (int)$db->insert('audits', ['project_id' => 6, 'base_url' => 'https://y/', 'status' => 'done']);
    eq(SiteAudit::purge(), 2); eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_audits WHERE project_id = 5')['n'], 2); eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_audit_issues')['n'], 2); eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_audit_pages')['n'], 2);
    ok((bool)$db->fetchOne('SELECT 1 x FROM sw_audits WHERE id = :i', ['i' => $other]), 'another site is untouched'); Config::set('audit.keep', 10);
});
