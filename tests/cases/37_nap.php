<?php
use Sitewright\Core\{Config, Html, Jobs, Nap, SiteAudit, SiteProfile};

// Phase 5: phone and address consistency across the pages of an audit.
$dom = fn (string $body) => Html::load('<!doctype html><html><head></head><body>' . $body . '</body></html>', 'text/html; charset=utf-8');

test('phones: the same number written differently is the same number; a different one is not; junk is not a phone number', function () {
    foreach ([['(505) 555-0100', '+1 505-555-0100'], ['505.555.0100', '5055550100'], ['+1 505 555 0100', '1-505-555-0100'], ['+44 20 7946 0958', '020 7946 0958'], ['+44 20 7946 0958', '+44 (0)20 7946 0958'], ['+44 20 7946 0958', '+44 20 7946 0959', false]] as $c) {
        eq(Nap::samePhone($c[0], $c[1]), $c[2] ?? true, $c[0] . ' vs ' . $c[1]);
    }
    ok(!Nap::samePhone('505-555-0100', '505-555-0199')); ok(!Nap::samePhone('505-555-0100', '')); ok(!Nap::samePhone('12', '12'));
    eq([Nap::key('12'), Nap::key('call us'), Nap::key(str_repeat('9', 20)), Nap::key('+1 (505) 555-0100')], [null, null, null, '5055550100']);
});

test('addresses: post code and house number decide; different spellings of the rest do not matter; nothing to compare is not a mismatch', function () {
    eq(Nap::sameAddress(['street' => '12 Main St', 'postal' => '87501'], ['street' => '12 Main Street.', 'postal' => '87501']), true);
    eq(Nap::sameAddress(['street' => '12 Main St', 'postal' => '87501'], ['street' => '14 Main St', 'postal' => '87501']), false, 'house number differs');
    eq(Nap::sameAddress(['street' => '12 Main St', 'postal' => '87501'], ['street' => '12 Main St', 'postal' => '87502']), false, 'postal code differs');
    eq(Nap::sameAddress(['street' => 'Main St', 'postal' => 'sw1a 1aa'], ['street' => '', 'postal' => 'SW1A1AA']), true, 'spaces and case in a postal code');
    eq(Nap::sameAddress(['street' => 'Main St', 'postal' => ''], ['street' => 'Elm Rd', 'postal' => '']), null, 'no number, no postal code: cannot tell');
});

test('extract: tel: links and structured data are read; plain text is not guessed at; every value is cleaned and capped', function () use ($dom) {
    $ld = json_encode(['@context' => 'https://schema.org', '@graph' => [['@type' => 'LocalBusiness', 'telephone' => '+1 505 555 0100', 'address' => ['@type' => 'PostalAddress', 'streetAddress' => '12 <b>Main</b> St', 'postalCode' => '87501']],
        ['@type' => 'ContactPoint', 'telephone' => ['(505) 555-0199', 'x']]]]);
    $p = Nap::extract($dom('<p>Call 505-555-7777 today</p><a href="tel:+1-505-555-0100">call</a><a href="TEL:505%20555%200123">x</a><a href="tel:nope">n</a><a href="mailto:a@b.c">m</a><script type="application/ld+json">' . $ld . '</script>'));
    eq($p['phones'], ['+1-505-555-0100', '505 555 0123', '+1 505 555 0100', '(505) 555-0199'], 'the number in the paragraph is not read');
    eq($p['addresses'], [['street' => '12 Main St', 'postal' => '87501']]);
    $many = ''; for ($i = 0; $i < 50; $i++) { $many .= '<a href="tel:50555501' . str_pad((string)$i, 2, '0', STR_PAD_LEFT) . '">x</a>'; }
    eq(count(Nap::extract($dom($many))['phones']), 10, 'capped per page');
    eq(Nap::extract($dom('<a href="tel:<script>alert(1)</script>5055550100">x</a>'))['phones'] === [] || !str_contains(json_encode(Nap::extract($dom('<a href="tel:<script>alert(1)</script>5055550100">x</a>'))), '<'), true, 'no markup survives');
});

test('issues: with a chosen phone every other number is a difference; without one, several numbers are reported once, most used first', function () {
    $pages = ['https://a.example/' => ['phones' => ['(505) 555-0100'], 'addresses' => []], 'https://a.example/c' => ['phones' => ['505-555-0199', '+1 505 555 0100'], 'addresses' => []], 'https://a.example/d' => ['phones' => ['505-555-0100'], 'addresses' => []]];
    $i = Nap::issues($pages, ['phone' => '+1 505 555 0100']);
    eq(array_map(fn ($x) => $x[0] . ' ' . $x[1], $i), ['nap_phone_differs https://a.example/c']); ok(str_contains($i[0][2], '505-555-0199') && str_contains($i[0][2], 'your phone is +1 505 555 0100'));
    $v = Nap::issues($pages, []); eq(count($v), 1); eq([$v[0][0], $v[0][1]], ['nap_phone_varies', 'https://a.example/']); ok(str_contains($v[0][2], '(505) 555-0100 (3 pages); 505-555-0199 (1 page)'), $v[0][2]);
    eq(Nap::issues(['https://a.example/' => ['phones' => ['505-555-0100'], 'addresses' => []]], []), [], 'one number everywhere: nothing to say');
    $a = Nap::issues(['https://a.example/' => ['phones' => [], 'addresses' => [['street' => '99 Old Rd', 'postal' => '87000']]], 'https://a.example/b' => ['phones' => [], 'addresses' => [['street' => '12 Main St', 'postal' => '87501']]]], ['street' => '12 Main Street', 'postal_code' => '87501']);
    eq(array_map(fn ($x) => $x[0] . ' ' . $x[1], $a), ['nap_address_differs https://a.example/']);
});

test('audit: the new checks run on a real crawl, compare with what the owner chose, and stay quiet when the site is consistent', function () {
    $db = $GLOBALS['db'] ?? null;
    if (!dbAvailable()) { ok(false, 'database'); return; }
    $db = freshDb(); Config::set('limits.crawl_delay_ms', 0);
    $H = ['Content-Type' => 'text/html; charset=utf-8'];
    $desc = 'A description for this page that is long enough to count as optimal for search results, with plenty of useful words in it for readers.';
    $pg = fn (string $title, string $body) => [200, $H, '<!doctype html><html lang="en"><head><title>' . $title . '</title><meta name="description" content="' . $desc . '"><meta name="viewport" content="width=device-width"><link rel="canonical" href="https://nap.example/"></head><body><h1>H</h1>' . $body . '<p>' . str_repeat('word ', 400) . '</p></body></html>'];
    $web = fn (string $contactPhone, string $contactPostal) => fakeWeb([
        'https://nap.example/' => $pg('Home page of the example business', '<a href="/contact">c</a><a href="/about">a</a><a href="tel:+1-505-555-0100">call</a>'),
        'https://nap.example/contact' => $pg('Contact the example business today', '<a href="/">h</a><a href="tel:' . $contactPhone . '">call</a><script type="application/ld+json">' . json_encode(['@type' => 'LocalBusiness', 'address' => ['@type' => 'PostalAddress', 'streetAddress' => '12 Main St', 'postalCode' => $contactPostal]]) . '</script>'),
        'https://nap.example/about' => $pg('About the example business and its team', '<a href="/">h</a>'),
    ]);
    $run = function (int $project) { $r = SiteAudit::start($project, 'https://nap.example/', null); while (Jobs::runNext()) {} return array_map(fn ($x) => $x['code'] . ' ' . str_replace('https://nap.example', '', $x['url']), $GLOBALS['__db']->fetchAll('SELECT code, url FROM sw_audit_issues WHERE audit_id = :a AND code LIKE "nap\_%" ORDER BY code, url', ['a' => $r['audit_id']])); };
    $GLOBALS['__db'] = $db;
    foreach (['audits', 'audit_pages', 'audit_issues', 'jobs', 'sitemaps', 'sites', 'site_profile', 'rate_limits'] as $t) { $db->query("TRUNCATE sw_$t"); }
    $sid = (int)$db->insert('sites', ['name' => 'Nap', 'base_url' => 'https://nap.example', 'secret_enc' => 'x', 'sync_enabled' => 0]);
    $web('505-555-0199', '87502');
    eq($run($sid), ['nap_phone_varies /'], 'nothing chosen yet: the two numbers are reported once, and the address is not judged');
    SiteProfile::choose($sid, 'phone', 'manual', '(505) 555-0100'); SiteProfile::choose($sid, 'street', 'manual', '12 Main Street'); SiteProfile::choose($sid, 'postal_code', 'manual', '87501');
    eq($run($sid), ['nap_address_differs /contact', 'nap_phone_differs /contact']);
    $web('+1 505 555 0100', '87501');
    eq($run($sid), [], 'consistent: no issues');
    $web('505-555-0199', '87502');
    eq($run(0), ['nap_phone_varies /'], 'a site not in the list has nothing chosen, so only the variation is shown');
});
