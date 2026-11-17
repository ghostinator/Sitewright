<?php
use Sitewright\Core\{Config, Crypto, Gsc, Opportunities, OpportunityController};

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb(); Config::set('app_key', Crypto::generateKey());
Config::set('google', ['client_id' => 'cid-123456789.apps.googleusercontent.com', 'client_secret' => 'SECRET-abcdefghij', 'redirect_uri' => 'https://sitewright.example/api/gsc/callback']);
$QUERY = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode('sc-domain:shop.example') . '/searchAnalytics/query';
$d = gmdate('Y-m-d', strtotime('-5 days'));

test('gsc sync also imports query+page rows: replaced each sync, cleaned, stored under the connection\'s site, without disturbing the date+query rows', function () use ($db, $QUERY, $d) {
    foreach (['gsc_connections', 'search_pages', 'analytics_keywords'] as $t) { $db->query("TRUNCATE sw_$t"); }
    $c = (int)$db->insert('gsc_connections', ['user_id' => 1, 'refresh_token_enc' => 'x', 'property_url' => 'sc-domain:shop.example', 'site_id' => 7]);
    $db->update('gsc_connections', ['refresh_token_enc' => Crypto::encrypt('1//R', "gsc:$c")], ['id' => $c]);
    $pageRows = [['keys' => ['blue widgets', 'https://shop.example/blue'], 'clicks' => 12, 'impressions' => 340, 'ctr' => 0.0353, 'position' => 4.2],
        ['keys' => ["evil\n<b>q", 'https://shop.example/x'], 'clicks' => 1, 'impressions' => 9, 'ctr' => 0.11, 'position' => 8.0],
        ['keys' => ['no page url', 'javascript:alert(1)'], 'clicks' => 1, 'impressions' => 9, 'ctr' => 0.11, 'position' => 8.0],
        ['keys' => ['   ', 'https://shop.example/y'], 'clicks' => 1, 'impressions' => 9, 'ctr' => 0.11, 'position' => 8.0],
        ['keys' => ['too long', 'https://shop.example/' . str_repeat('a', 1100)], 'clicks' => 1, 'impressions' => 9, 'ctr' => 0.11, 'position' => 8.0]];
    $calls = [];
    fakeWeb(['https://oauth2.googleapis.com/token' => fn () => [200, [], json_encode(['access_token' => 'ya29.A', 'expires_in' => 3599, 'scope' => Gsc::SCOPE, 'token_type' => 'Bearer'])],
        $QUERY => function ($r) use (&$calls, $d, &$pageRows) { $b = json_decode($r['body'], true); $calls[] = $b['dimensions'];
            return [200, [], json_encode(['rows' => $b['dimensions'] === ['date', 'query'] ? [['keys' => [$d, 'blue widgets'], 'clicks' => 5, 'impressions' => 100, 'ctr' => 0.05, 'position' => 3.1]] : $pageRows])]; }]);
    $r = Gsc::handleJob(['connection_id' => $c]);
    eq($calls, [['date', 'query'], ['query', 'page']]); eq($r['data']['page_rows'], 2);
    $rows = $db->fetchAll('SELECT project_id, page, query, impressions, clicks, ctr, position FROM sw_search_pages ORDER BY impressions DESC');
    eq(count($rows), 2); eq([(int)$rows[0]['project_id'], $rows[0]['page'], $rows[0]['query'], (int)$rows[0]['impressions'], (float)$rows[0]['ctr']], [7, 'https://shop.example/blue', 'blue widgets', 340, 3.53]);
    ok(!str_contains($rows[1]['query'], "\n") && str_contains($rows[1]['query'], 'evil'), 'control characters removed');
    eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_analytics_keywords')['n'], 1, 'date+query import untouched');
    $pageRows = [['keys' => ['green widgets', 'https://shop.example/green'], 'clicks' => 2, 'impressions' => 50, 'ctr' => 0.04, 'position' => 6.0]];
    Gsc::handleJob(['connection_id' => $c]); eq(array_column($db->fetchAll('SELECT query FROM sw_search_pages'), 'query'), ['green widgets'], 'replaced, not appended');
    $c2 = (int)$db->insert('gsc_connections', ['user_id' => 1, 'refresh_token_enc' => 'x', 'property_url' => 'sc-domain:shop.example', 'site_id' => 8]);
    $db->update('gsc_connections', ['refresh_token_enc' => Crypto::encrypt('1//R', "gsc:$c2")], ['id' => $c2]);
    Gsc::handleJob(['connection_id' => $c2]); eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_search_pages')['n'], 2, 'another connection keeps its own rows; the first one was untouched');
});

$sp = function (int $project, string $page, string $query, int $imp, int $clk, float $pos) use ($db) {
    $db->insert('search_pages', ['project_id' => $project, 'connection_id' => 1, 'page' => $page, 'query' => $query, 'impressions' => $imp, 'clicks' => $clk, 'ctr' => $imp ? round($clk / $imp * 100, 2) : 0,
        'position' => $pos, 'window_start' => gmdate('Y-m-d', strtotime('-29 days')), 'window_end' => gmdate('Y-m-d', strtotime('-2 days'))]);
};
$reset = function () use ($db) { $db->query('TRUNCATE sw_search_pages'); $db->query('TRUNCATE sw_analytics_keywords'); Config::set('search', ['min_impressions' => 100, 'striking_from' => 8, 'striking_to' => 20]); };
$byType = fn (array $items, string $type) => array_values(array_filter($items, fn ($i) => $i['type'] === $type));

test('opportunities: a page-one query whose click-through is far below what its position normally gets is a CTR gap, with the clicks it could win', function () use ($reset, $sp) {
    $reset(); $sp(1, '/a', 'low ctr', 1000, 30, 3.0); $sp(1, '/b', 'healthy', 1000, 110, 3.0); $sp(1, '/c', 'tiny', 50, 0, 3.0); $sp(1, '/d', 'page two', 1000, 5, 15.0);
    $g = (new Opportunities())->find(1); $gap = array_values(array_filter($g['items'], fn ($i) => $i['type'] === 'ctr_gap'));
    eq(array_column($gap, 'query'), ['low ctr']); eq($gap[0]['potential_clicks'], 80, 'about 11% expected at position 3, 3% now: 80 more clicks on 1,000 views'); ok($gap[0]['advice'] !== '');
});
test('opportunities: striking distance is position 8-20 with enough impressions; the settings move the line', function () use ($reset, $sp) {
    $reset(); $sp(1, '/a', 'q12', 500, 5, 12.0); $sp(1, '/b', 'q25', 500, 1, 25.0); $sp(1, '/c', 'q5', 500, 40, 5.0); $sp(1, '/d', 'q9 small', 99, 1, 9.0);
    $s = function () { $q = array_column(array_filter((new Opportunities())->find(1)['items'], fn ($i) => $i['type'] === 'striking_distance'), 'query'); sort($q); return $q; };
    eq($s(), ['q12']); Config::set('search', ['min_impressions' => 50, 'striking_from' => 8, 'striking_to' => 30]); eq($s(), ['q12', 'q25', 'q9 small']);
});
test('opportunities: one query shown for several of your pages is cannibalisation, led by the page with most impressions', function () use ($reset, $sp) {
    $reset(); $sp(1, '/a', 'blue widgets', 400, 20, 6.0); $sp(1, '/b', 'blue widgets', 200, 4, 11.0); $sp(1, '/z', 'blue widgets', 10, 0, 40.0); $sp(1, '/only', 'one page', 900, 50, 4.0);
    $c = array_values(array_filter((new Opportunities())->find(1)['items'], fn ($i) => $i['type'] === 'cannibalisation'));
    eq(count($c), 1); eq($c[0]['query'], 'blue widgets'); eq($c[0]['page'], '/a'); eq($c[0]['other_pages'], ['/b'], 'a page with a tiny share is not a competitor');
});
test('opportunities: a keyword that lost most of its clicks against the previous 28 days is flagged; small or rising ones are not', function () use ($db, $reset) {
    $reset(); $recent = gmdate('Y-m-d', strtotime('-10 days')); $prev = gmdate('Y-m-d', strtotime('-40 days'));
    foreach ([['falling kw', 100, 30], ['small kw', 5, 0], ['rising kw', 20, 90], ['steady kw', 100, 90]] as [$k, $p, $r]) {
        foreach ([[$prev, $p], [$recent, $r]] as [$day, $clicks]) { $db->insert('analytics_keywords', ['project_id' => 1, 'keyword' => $k, 'impressions' => 1000, 'clicks' => $clicks, 'position' => 5, 'ctr' => 1, 'date' => $day]); }
    }
    $f = array_values(array_filter((new Opportunities())->find(1)['items'], fn ($i) => $i['type'] === 'falling'));
    eq(array_column($f, 'query'), ['falling kw']); eq([$f[0]['previous_clicks'], $f[0]['clicks'], $f[0]['potential_clicks']], [100, 30, 70]);
});
test('opportunities: scoped to a site, sorted by the clicks at stake, limited, with a summary; no data says so', function () use ($reset, $sp) {
    $reset(); $o = new Opportunities(); $e = $o->find(1); eq([$e['has_data'], $e['items']], [false, []]);
    $sp(1, '/a', 'big', 5000, 50, 3.0); $sp(1, '/b', 'small', 300, 3, 3.0); $sp(2, '/other', 'other site', 9000, 10, 3.0);
    $r = $o->find(1); eq($r['has_data'], true); eq(array_column($r['items'], 'query'), ['big', 'small']); ok($r['items'][0]['potential_clicks'] > $r['items'][1]['potential_clicks']);
    eq($r['summary']['ctr_gap'], 2); ok($r['summary']['potential_clicks'] > 0); eq(count($o->find(1, null, 1)['items']), 1);
    ok(in_array('other site', array_column($o->find(0)['items'], 'query'), true), 'project 0 = all sites'); eq(array_column($o->find(1, 'cannibalisation')['items'], 'query'), []);
});
test('opportunities API: type filter is validated, results carry the site name and the benchmark note', function () use ($db, $reset, $sp) {
    $reset(); $db->query('TRUNCATE sw_sites'); $sid = (int)$db->insert('sites', ['name' => 'Shop', 'base_url' => 'https://shop.example', 'secret_enc' => '', 'sync_enabled' => 0]); $sp($sid, '/a', 'q', 1000, 10, 3.0);
    $c = fn (array $q) => call(OpportunityController::class, 'list', ['query' => $q]);
    eq($c(['type' => 'nonsense'])['status'], 400); $r = $c(['project_id' => (string)$sid, 'type' => 'ctr_gap']); eq($r['status'], 200);
    eq($r['json']['data']['items'][0]['site'], 'Shop'); ok(str_contains($r['json']['data']['note'], 'average'));
});
