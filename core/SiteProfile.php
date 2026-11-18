<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * What a site says about itself, and what the owner decided to do with each item.
 *
 * Discovery reads the site's PUBLIC pages (home page, robots.txt, sitemap) through SafeHttpClient, the same guarded path a crawl uses, so it works
 * for every site, with or without the WordPress plugin. Everything read is untrusted text: tags and control characters are removed, lengths are
 * capped, and values with a known shape (language, phone, sitemap address) are dropped when they do not match it.
 *
 * Per item the owner picks: use what was found (it then follows the site when the site changes), type their own (never overwritten), or skip.
 * Only used and typed values feed the rest of the app (see values()).
 */
final class SiteProfile
{
    /** key => [label, group, max length, info only (a check, not something to choose)] */
    public const FIELDS = [
        'site_title'    => ['Home page title', 'site', 200, false],
        'description'   => ['Home page description', 'site', 300, false],
        'language'      => ['Language', 'site', 35, false],
        'platform'      => ['Built with', 'site', 60, false],
        'sitemap_url'   => ['Sitemap address', 'site', 500, false],
        'https'         => ['Secure connection (HTTPS)', 'check', 60, true],
        'robots'        => ['Search engines allowed in', 'check', 100, true],
        'business_name' => ['Business name', 'business', 150, false],
        'phone'         => ['Phone', 'business', 30, false],
        'street'        => ['Street address', 'business', 200, false],
        'city'          => ['City', 'business', 100, false],
        'region'        => ['State or region', 'business', 100, false],
        'postal_code'   => ['Postal code', 'business', 20, false],
        'country'       => ['Country', 'business', 100, false],
    ];
    private const ACTIONS = ['use', 'manual', 'skip', 'reset'];
    private const BLOCKED = 'Blocked: robots.txt tells every search engine to stay away (Disallow: /)';

    // ------------------------------------------------------------------ reading a site

    /** Strip markup and control characters, collapse white space, cut to $max characters. Null when nothing is left. */
    public static function clean(mixed $v, int $max): ?string
    {
        if (!is_string($v) && !is_int($v) && !is_float($v)) { return null; }
        $s = (string)$v;
        if (!mb_check_encoding($s, 'UTF-8')) { return null; }
        $s = strip_tags(html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8'));   // twice: an encoded tag (&lt;b&gt;) must not come out as one
        $s = str_replace(['<', '>'], '', $s);
        $s = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '') ?? '');
        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    /** @return array{found:array<string,string>,error:?string} */
    public static function discover(array $site): array
    {
        $client = SafeHttpClient::default();
        $deadline = microtime(true) + 40;
        $fail = static fn (string $m): array => ['found' => [], 'error' => $m];
        try {
            $res = $client->get((string)$site['base_url'], ['max_bytes' => (int)Config::get('fetch.max_html_bytes'), 'deadline' => $deadline]);
        } catch (FetchException $e) {
            return $fail('Could not read the site: ' . mb_substr($e->getMessage(), 0, 150));
        }
        if ($res['status'] !== 200) { return $fail('The site answered HTTP ' . (int)$res['status'] . ' for its home page'); }
        if (!in_array($res['content_type'], ['text/html', 'application/xhtml+xml'], true)) { return $fail('The home page is not an HTML page'); }
        $final = Url::normalize($res['final_url']) ?? (string)$site['base_url'];
        $origin = (string)Url::origin($final);

        $robots = '';
        try {
            $r = $client->get($origin . '/robots.txt', ['max_bytes' => 262144, 'timeout' => 5, 'deadline' => $deadline, 'accept' => 'text/plain,*/*;q=0.5']);
            if ($r['status'] === 200) { $robots = $r['body']; }
        } catch (FetchException) { /* no robots.txt: everything is allowed */ }

        $found = self::parse($res['body'], (string)($res['headers']['content-type'] ?? ''), $final, $robots);
        if (!isset($found['sitemap_url'])) {
            foreach (['/sitemap.xml', '/wp-sitemap.xml'] as $path) {
                try {
                    $r = $client->get($origin . $path, ['max_bytes' => 65536, 'timeout' => 5, 'deadline' => $deadline, 'accept' => 'application/xml,text/xml,*/*;q=0.5']);
                } catch (FetchException) { continue; }
                if ($r['status'] === 200 && preg_match('/<(urlset|sitemapindex)\b/i', substr($r['body'], 0, 4096))) { $found['sitemap_url'] = $origin . $path; break; }
            }
        }
        return ['found' => $found, 'error' => null];
    }

    /** Pure: page bytes + robots.txt in, the items found out. @return array<string,string> */
    public static function parse(string $body, string $contentType, string $finalUrl, string $robots): array
    {
        $dom = Html::load($body, $contentType);
        $xp = new \DOMXPath($dom);
        $out = [];
        $set = static function (string $k, ?string $v) use (&$out): void { if ($v !== null && $v !== '') { $out[$k] = $v; } };
        $max = static fn (string $k): int => self::FIELDS[$k][2];

        $set('site_title', self::clean($xp->evaluate('string(//title[1])'), $max('site_title')));
        $set('description', self::clean($xp->evaluate('string(//meta[translate(@name,"DESCRIPTION","description")="description"][1]/@content)'), $max('description')));
        $lang = self::clean($xp->evaluate('string(/html/@lang)'), 35);
        if ($lang !== null && preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8}){0,3}$/D', $lang)) { $out['language'] = $lang; }
        $out['https'] = str_starts_with($finalUrl, 'https://') ? 'Yes' : 'No: the site is served over plain http';

        // platform
        $gen = self::clean($xp->evaluate('string(//meta[translate(@name,"GENERATOR","generator")="generator"][1]/@content)'), 60);
        $known = ['WordPress', 'Shopify', 'Wix', 'Squarespace', 'Webflow', 'Joomla', 'Drupal', 'Ghost', 'Hugo', 'Jekyll', 'WooCommerce'];
        $platform = null;
        foreach ($known as $k) { if ($gen !== null && stripos($gen, $k) !== false) { $platform = $k; break; } }
        if ($platform === null && str_contains($body, '/wp-content/')) { $platform = 'WordPress'; }
        if ($platform === null && str_contains($body, 'cdn.shopify.com')) { $platform = 'Shopify'; }
        $set('platform', $platform ?? $gen);

        // robots.txt: whether every crawler is shut out, and the sitemaps it lists
        $origin = (string)Url::origin($finalUrl);
        $applies = false; $blocked = false;
        foreach (preg_split('/\r?\n/', $robots) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
            if (preg_match('/^user-agent:\s*(.+)$/i', $line, $m)) { $applies = trim($m[1]) === '*'; }
            elseif ($applies && preg_match('/^disallow:\s*\/\s*$/i', $line)) { $blocked = true; }
            elseif (!isset($out['sitemap_url']) && preg_match('/^sitemap:\s*(\S+)$/i', $line, $m) && !preg_match('/[\x00-\x20\x7F]/', $m[1])) {
                $u = Url::normalize($m[1]);
                if ($u !== null && Url::origin($u) === $origin && mb_strlen($u) <= $max('sitemap_url')) { $out['sitemap_url'] = $u; }
            }
        }
        $out['robots'] = $blocked ? self::BLOCKED : 'Allowed';

        // structured data (JSON-LD): the business itself
        $nodes = [];
        foreach ($xp->query('//script[@type="application/ld+json"]') ?: [] as $script) {
            $data = json_decode((string)$script->textContent, true, 64);
            if (is_array($data)) { self::collectNodes($data, 0, $nodes); }
            if (count($nodes) >= 200) { break; }
        }
        // the business itself: the shallowest node with an address or phone (a top-level node or one in @graph beats a place nested inside an event or offer)
        $other = '/^(Event|Person|JobPosting|Offer|Product|Article|BlogPosting|NewsArticle|Review|Recipe|Course|Place|PostalAddress|ContactPoint)$/';
        $isOther = static fn (array $n): bool => (bool)array_filter((array)($n['@type'] ?? ''), static fn ($t) => is_string($t) && preg_match($other, $t));
        usort($nodes, static fn (array $a, array $b): int => $a['d'] <=> $b['d']);   // stable in PHP 8: document order inside one depth
        $biz = null;
        foreach ($nodes as ['n' => $n]) { if ((isset($n['address']) || isset($n['telephone'])) && !$isOther($n)) { $biz = $n; break; } }
        if ($biz === null) { foreach ($nodes as ['n' => $n]) { if (preg_match('/Organization|Business|Store|Restaurant/i', json_encode($n['@type'] ?? '') ?: '') && isset($n['name'])) { $biz = $n; break; } } }
        if ($biz !== null) {
            $set('business_name', self::clean($biz['name'] ?? null, $max('business_name')));
            $tel = self::clean($biz['telephone'] ?? null, 30);
            if ($tel !== null && preg_match('/^\+?[0-9][0-9 ().\-]{4,29}$/D', $tel)) { $out['phone'] = $tel; }
            $addr = $biz['address'] ?? null;
            if (is_array($addr) && array_is_list($addr)) { $addr = $addr[0] ?? null; }
            if (is_string($addr)) { $set('street', self::clean($addr, $max('street'))); }
            elseif (is_array($addr)) {
                foreach (['street' => 'streetAddress', 'city' => 'addressLocality', 'region' => 'addressRegion', 'postal_code' => 'postalCode', 'country' => 'addressCountry'] as $k => $key) {
                    $v = $addr[$key] ?? null;
                    if (is_array($v)) { $v = $v['name'] ?? null; }       // addressCountry may be a Country object
                    $set($k, self::clean($v, $max($k)));
                }
            }
        }
        if (!isset($out['phone'])) {   // a visible tel: link is the next best source
            $href = (string)$xp->evaluate('string(//a[starts-with(translate(@href,"TEL","tel"),"tel:")][1]/@href)');
            $tel = self::clean(rawurldecode(substr($href, 4)), 30);
            if ($tel !== null && preg_match('/^\+?[0-9][0-9 ().\-]{4,29}$/D', $tel)) { $out['phone'] = $tel; }
        }
        return $out;
    }

    /** Depth- and count-limited walk for objects that carry an @type (handles @graph and nested lists). */
    private static function collectNodes(array $v, int $depth, array &$nodes): void
    {
        if ($depth > 6 || count($nodes) >= 200) { return; }
        if (isset($v['@type'])) { $nodes[] = ['d' => $depth, 'n' => $v]; }
        foreach ($v as $child) { if (is_array($child)) { self::collectNodes($child, $depth + 1, $nodes); } }
    }

    // ------------------------------------------------------------------ storing

    /** Read the site now and store what was found. Never throws: a failed read is recorded on the site and earlier choices stay. @return array{error:?string,found:int} */
    public static function refresh(int $siteId): array
    {
        $db = Database::getInstance();
        $site = $db->fetchOne('SELECT id, base_url FROM `' . $db->t('sites') . '` WHERE id = :i', ['i' => $siteId]);
        if (!$site) { return ['error' => 'Site not found', 'found' => 0]; }
        try {
            $d = self::discover($site);
        } catch (\Throwable $e) {
            Logger::warn('site_profile_failed', ['site' => $siteId, 'error' => get_class($e)]);
            $d = ['found' => [], 'error' => 'Could not read the site (unexpected error; see the log)'];
        }
        $now = gmdate('Y-m-d H:i:s');
        if ($d['error'] === null) {
            try { self::store($siteId, array_replace($d['found'], SiteRemote::profileFound($siteId)), $now); }   // what the owner let the plugin tell us beats what the public page shows
            catch (\Throwable $e) { Logger::warn('site_profile_failed', ['site' => $siteId, 'error' => get_class($e)]); $d['error'] = 'Could not save what the site says (see the log)'; }
        }
        $db->update('sites', ['profile_at' => $now, 'profile_error' => $d['error'] === null ? null : mb_substr($d['error'], 0, 250)], ['id' => $siteId]);
        return ['error' => $d['error'], 'found' => count($d['found'])];
    }

    /** @param array<string,string> $found */
    private static function store(int $siteId, array $found, string $now): void
    {
        $db = Database::getInstance();
        $t = $db->t('site_profile');
        $db->transaction(function (Database $db) use ($siteId, $found, $now, $t): void {
            $rows = array_column($db->fetchAll("SELECT field, state FROM `$t` WHERE site_id = :s", ['s' => $siteId]), 'state', 'field');
            foreach (self::FIELDS as $field => [, , , $info]) {
                $f = $found[$field] ?? null;
                $state = $rows[$field] ?? null;
                $key = ['s' => $siteId, 'f' => $field];
                if ($state === null) {
                    if ($f !== null) { $db->query("INSERT INTO `$t` (site_id, field, found, state, found_at, updated_at) VALUES (:s, :f, :v, :st, :n1, :n2) ON DUPLICATE KEY UPDATE found = VALUES(found), found_at = VALUES(found_at)", $key + ['v' => $f, 'st' => $info ? 'info' : 'pending', 'n1' => $now, 'n2' => $now]); }
                } elseif ($state === 'pending' && $f === null) {
                    $db->query("DELETE FROM `$t` WHERE site_id = :s AND field = :f", $key);   // nothing left to decide
                } elseif ($state === 'used' && $f !== null) {
                    $db->query("UPDATE `$t` SET found = :v1, value = :v2, found_at = :n1, updated_at = :n2 WHERE site_id = :s AND field = :f", $key + ['v1' => $f, 'v2' => $f, 'n1' => $now, 'n2' => $now]);   // follows the site
                } else {
                    // pending, info, typed, skipped, or used while the site no longer shows it: only what the site says is updated
                    $db->query("UPDATE `$t` SET found = :v, found_at = :n WHERE site_id = :s AND field = :f", $key + ['v' => $f, 'n' => $f === null ? null : $now]);
                }
            }
        });
    }

    // ------------------------------------------------------------------ choices

    /** @throws ResponseException on a bad field, action or text, or an unknown site */
    public static function choose(int $siteId, string $field, string $action, ?string $value = null): void
    {
        $db = Database::getInstance();
        $t = $db->t('site_profile');
        if (!$db->fetchOne('SELECT 1 x FROM `' . $db->t('sites') . '` WHERE id = :i', ['i' => $siteId])) { Response::notFound('Site not found'); }
        if (!isset(self::FIELDS[$field])) { Validator::fail('field', 'unknown item'); }
        if (!in_array($action, self::ACTIONS, true)) { Validator::fail('action', 'must be one of: ' . implode(', ', self::ACTIONS)); }
        [$label, , $max, $info] = self::FIELDS[$field];
        if ($info) { Validator::fail('field', "$label is a check, not something to choose"); }
        $row = $db->fetchOne("SELECT found FROM `$t` WHERE site_id = :s AND field = :f", ['s' => $siteId, 'f' => $field]);
        $found = $row['found'] ?? null;
        $now = gmdate('Y-m-d H:i:s');
        $upsert = static fn (string $state, ?string $v) => $db->query(
            "INSERT INTO `$t` (site_id, field, found, value, state, updated_at) VALUES (:s, :f, :found, :v, :st, :n)
             ON DUPLICATE KEY UPDATE value = VALUES(value), state = VALUES(state), updated_at = VALUES(updated_at)",
            ['s' => $siteId, 'f' => $field, 'found' => $found, 'v' => $v, 'st' => $state, 'n' => $now]);
        switch ($action) {
            case 'use':
                if ($found === null) { Validator::fail('action', "nothing was found for $label; type your own instead"); }
                $upsert('used', $found); break;
            case 'manual':
                $v = self::clean($value, 100000);
                if ($v === null) { Validator::fail('value', 'type the text to use'); }
                if (mb_strlen($v) > $max) { Validator::fail('value', "must be at most $max characters"); }
                $upsert('manual', $v); break;
            case 'skip':
                $upsert('skipped', null); break;
            case 'reset':
                if ($found === null) { $db->query("DELETE FROM `$t` WHERE site_id = :s AND field = :f", ['s' => $siteId, 'f' => $field]); }
                else { $upsert('pending', null); }
        }
    }

    /** The values the rest of the app may rely on: items the owner used or typed. @return array<string,string> */
    public static function values(int $siteId): array
    {
        return self::valuesFor([$siteId])[$siteId] ?? [];
    }

    /** @param int[] $siteIds @return array<int,array<string,string>> */
    public static function valuesFor(array $siteIds): array
    {
        $db = Database::getInstance();
        $out = [];
        $siteIds = array_values(array_filter(array_map('intval', $siteIds)));
        if (!$siteIds) { return []; }
        foreach ($db->fetchAll('SELECT site_id, field, value FROM `' . $db->t('site_profile') . "` WHERE state IN ('used','manual') AND value IS NOT NULL AND site_id IN (" . implode(',', $siteIds) . ')') as $r) {
            if (isset(self::FIELDS[$r['field']])) { $out[(int)$r['site_id']][$r['field']] = $r['value']; }
        }
        foreach ($out as &$vals) { $vals = array_replace(array_intersect_key(array_flip(array_keys(self::FIELDS)), $vals), $vals); }
        return $out;
    }

    /** Everything the setup guide shows for one site. @return array{site:array,fields:array,remote:array} */
    public static function view(int $siteId): array
    {
        $db = Database::getInstance();
        $site = $db->fetchOne('SELECT id, name, base_url, sync_enabled, profile_at, profile_error FROM `' . $db->t('sites') . '` WHERE id = :i', ['i' => $siteId]) ?? Response::notFound('Site not found');
        $rows = array_column($db->fetchAll('SELECT field, found, value, state, found_at FROM `' . $db->t('site_profile') . '` WHERE site_id = :s', ['s' => $siteId]), null, 'field');
        $fields = [];
        foreach (self::FIELDS as $field => [$label, $group, $max, $info]) {
            $r = $rows[$field] ?? null;
            $fields[] = ['field' => $field, 'label' => $label, 'group' => $group, 'max' => $max, 'info' => $info,
                'found' => $r['found'] ?? null, 'value' => $r['value'] ?? null, 'state' => $r['state'] ?? 'empty', 'found_at' => $r['found_at'] ?? null];
        }
        return ['site' => ['id' => (int)$site['id'], 'name' => $site['name'], 'base_url' => $site['base_url'], 'wordpress' => (int)$site['sync_enabled'] === 1,
            'profile_at' => $site['profile_at'], 'profile_error' => $site['profile_error']], 'fields' => $fields, 'remote' => (int)$site['sync_enabled'] === 1 ? SiteRemote::view($siteId) : []];
    }

    // ------------------------------------------------------------------ jobs and scheduling

    /** Job handler: payload {site_id}. Always finishes: a site that cannot be read is shown on the site, not as a failed job (the health check counts failed jobs). */
    public static function handleJob(array $p): array
    {
        $id = (int)($p['site_id'] ?? 0);
        $r = self::refresh($id);
        return ['message' => $r['error'] ?? 'Site details read', 'data' => $r];
    }

    public static function queue(int $siteId, ?int $userId = null): int
    {
        return Jobs::enqueue('site_profile', ['site_id' => $siteId], $userId, 0, 1);
    }

    /** Queue a read unless one is already waiting or running for this site. */
    public static function queueUnlessOpen(int $siteId): bool
    {
        $db = Database::getInstance();
        $open = $db->fetchOne('SELECT 1 x FROM `' . $db->t('jobs') . "` WHERE type = 'site_profile' AND status IN ('queued','running') AND payload = :p", ['p' => (string)json_encode(['site_id' => $siteId])]);
        if ($open) { return false; }
        self::queue($siteId);
        return true;
    }

    /** Worker tick: queue a read for sites never read, or read more than sites.profile_days ago (0 = never again on a schedule). */
    public static function scheduleDue(): int
    {
        $days = (int)Config::get('sites.profile_days');
        if ($days < 1) { return 0; }
        $db = Database::getInstance();
        $n = 0;
        foreach ($db->fetchAll('SELECT id FROM `' . $db->t('sites') . '` WHERE profile_at IS NULL OR profile_at < :c', ['c' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]) as $s) {
            if (self::queueUnlessOpen((int)$s['id'])) { $n++; }
        }
        return $n;
    }
}
