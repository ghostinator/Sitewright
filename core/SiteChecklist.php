<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** What is left to set up for one site, worked out from what Sitewright already knows (nothing is stored). */
final class SiteChecklist
{
    /** @return array{done:int,total:int,items:list<array{key:string,label:string,done:bool,detail:string,action:?string}>} */
    public static function for(int $siteId): array
    {
        $db = Database::getInstance();
        $site = $db->fetchOne('SELECT id, base_url, sync_enabled, plugin_version, last_status, profile_at, profile_error FROM `' . $db->t('sites') . '` WHERE id = :i', ['i' => $siteId]);
        if (!$site) { return ['done' => 0, 'total' => 0, 'items' => []]; }
        $prof = array_column($db->fetchAll('SELECT field, found, value, state FROM `' . $db->t('site_profile') . '` WHERE site_id = :s', ['s' => $siteId]), null, 'field');
        $exp = array_column($db->fetchAll('SELECT kind, state, expires_at, detail, error FROM `' . $db->t('site_expiry') . '` WHERE site_id = :s', ['s' => $siteId]), null, 'kind');
        $has = static fn (string $sql): bool => (bool)$db->fetchOne($sql, ['s' => $siteId]);
        $chosen = static fn (string $f): bool => in_array($prof[$f]['state'] ?? '', ['used', 'manual'], true) && ($prof[$f]['value'] ?? '') !== '';
        $items = [];
        $add = static function (string $key, string $label, bool $done, string $detail, ?string $action = null) use (&$items): void { $items[] = ['key' => $key, 'label' => $label, 'done' => $done, 'detail' => $detail, 'action' => $action]; };

        $read = $site['profile_at'] !== null && $site['profile_error'] === null;
        $add('read', 'Site details read', $read, $read ? 'Read on ' . $site['profile_at'] . ' (UTC).' : ($site['profile_error'] ?? 'Not read yet.'), $read ? null : 'profile-refresh');

        $pending = count(array_filter($prof, static fn ($r) => $r['state'] === 'pending' && $r['found'] !== null));
        $add('review', 'Decide what Sitewright uses from the site', $pending === 0 && $read, $pending > 0 ? $pending . ' item' . ($pending === 1 ? '' : 's') . ' waiting for your choice (Use this, Type my own or Skip).' : 'Nothing is waiting.');

        $https = str_starts_with((string)($prof['https']['found'] ?? ''), 'Yes');
        $add('https', 'Served over HTTPS', $https, $https ? 'Yes.' : 'The site is not served over HTTPS (or could not be read).');
        $open = str_starts_with((string)($prof['robots']['found'] ?? ''), 'Allowed');
        $add('robots', 'Search engines are allowed in', $open, $open ? 'robots.txt lets them in.' : (isset($prof['robots']['found']) ? 'robots.txt tells search engines to stay away.' : 'Not checked yet.'));
        $sm = (string)($prof['sitemap_url']['found'] ?? '');
        $add('sitemap', 'A sitemap exists', $sm !== '', $sm !== '' ? $sm : 'No sitemap found at robots.txt, /sitemap.xml or /wp-sitemap.xml.');

        if ((int)$site['sync_enabled'] === 1) {
            $ok = version_compare((string)$site['plugin_version'], '1.1.0', '>=') && $site['last_status'] === 'ok';
            $add('plugin', 'WordPress plugin 1.1 or newer, syncing', $ok, $ok ? 'Version ' . $site['plugin_version'] . ', last sync ok.' : ($site['last_status'] === 'ok' ? 'Version ' . ($site['plugin_version'] ?: 'unknown') . ': update it from the Sites page to choose what the site shares.' : 'The last sync did not work. See the Sites page.'));
        }

        $c = $exp['cert'] ?? null;
        if (str_starts_with((string)$site['base_url'], 'https://')) {
            $add('cert', 'Certificate checked', ($c['state'] ?? '') === 'ok', $c === null ? 'Not checked yet.' : (($c['state'] === 'ok') ? 'Valid until ' . $c['expires_at'] . ' UTC.' : (string)($c['error'] ?: $c['detail'])));
        }
        $d = $exp['domain'] ?? null;
        $noRdap = $d !== null && $d['state'] === 'error' && str_contains((string)$d['error'], 'does not offer a public RDAP');   // nothing the owner can do about it, so it is not a to-do
        if ((bool)Config::get('sites.rdap_enabled') && !$noRdap) {
            $add('domain', 'Domain expiry known', ($d['state'] ?? '') === 'ok', $d === null ? 'Not checked yet.' : (($d['state'] === 'ok') ? 'Registration runs until ' . $d['expires_at'] . ' UTC.' : (string)$d['error']));
        }
        $w = $has('SELECT 1 x FROM `' . $db->t('watch_items') . '` WHERE project_id = :s');
        $add('watch', 'The home page is watched', $w, $w ? 'Checked on a schedule; you are told when it goes down or its score drops.' : 'Not watched yet.', $w ? null : 'profile-watch');
        $a = $has('SELECT 1 x FROM `' . $db->t('audits') . '` WHERE project_id = :s');
        $add('audit', 'A site audit has run', $a, $a ? 'See Site Audit.' : 'No audit yet.', $a ? null : 'profile-audit');
        $g = $has('SELECT 1 x FROM `' . $db->t('gsc_connections') . '` WHERE site_id = :s');
        $add('gsc', 'Search Console is connected', $g, $g ? 'Connected.' : 'Not connected (Search Console page).');
        $biz = $chosen('phone') && $chosen('street') && $chosen('city');
        $add('business', 'Business details chosen (for Local SEO)', $biz, $biz ? 'Name, phone and address are set.' : 'Phone, street or city is not chosen yet. Skip this if the site is not a local business.');

        return ['done' => count(array_filter($items, static fn ($i) => $i['done'])), 'total' => count($items), 'items' => $items];
    }
}
