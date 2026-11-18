<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * The extra details a WordPress site's owner has approved (plugin 1.1+). The plugin's /status lists which data types are approved; we call
 * only those routes. Everything that comes back is untrusted: text is stripped of markup and control characters and cut to a fixed length,
 * numbers are clamped, lists are capped. An optional route can never fail the base sync: a problem is recorded as that kind's state.
 * States: ok, not_allowed (owner has not ticked it; anything stored is removed), unsupported (plugin older than 1.1), error.
 */
final class SiteRemote
{
    /** kind => [label, route] */
    public const KINDS = [
        'site' => ['Site details', '/sitewright/v1/site'],
        'content' => ['Page list', '/sitewright/v1/content'],
        'seo' => ['Search titles and descriptions', '/sitewright/v1/seo'],
        'redirects' => ['Existing redirects', '/sitewright/v1/existing-redirects'],
        'store' => ['Shop address', '/sitewright/v1/store'],
        'media' => ['Media library counts', '/sitewright/v1/media'],
        'versions' => ['Version and update counts', '/sitewright/v1/versions'],
    ];
    public const MAX_ITEMS = 2000;     // pages (and search entries) kept per site
    public const MAX_REDIRECTS = 1000;
    public const BUDGET_SECONDS = 120;   // the optional reads of one sync stop here; what is left continues on the next sync (a job silent for job_stale_seconds is taken as stuck)
    /** Unix time (float) after which no further site request is made. Public so tests can move it. */
    public static float $deadline = 0.0;

    /** Never throws. @param array<string,mixed> $status the decoded /status answer */
    public static function run(array $site, string $secret, array $status, bool $force = false): void
    {
        $id = (int)$site['id'];
        $db = Database::getInstance();
        self::$deadline = microtime(true) + self::BUDGET_SECONDS;
        try {
            $features = is_array($status['features'] ?? null) ? $status['features'] : null;
            $rows = array_column($db->fetchAll('SELECT kind, state, fetched_at FROM `' . $db->t('site_remote') . '` WHERE site_id = :s', ['s' => $id]), null, 'kind');
            $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, (int)Config::get('sites.remote_hours')) * 3600);
            $profileChanged = false;
            foreach (self::KINDS as $kind => [, $route]) {
                $had = $rows[$kind]['state'] ?? null;
                if ($features === null) { $want = 'unsupported'; }
                elseif (($features[$kind] ?? false) !== true) { $want = 'not_allowed'; }
                else { $want = 'ok'; }
                if ($want !== 'ok') {
                    if ($had !== $want) { self::forget($id, $kind, $want); $profileChanged = $profileChanged || in_array($kind, ['site', 'store'], true); }
                    continue;
                }
                if (!$force && $had === 'ok' && (string)$rows[$kind]['fetched_at'] >= $cutoff) { continue; }
                if (microtime(true) > self::$deadline) { continue; }   // out of time: this kind waits for the next sync
                try {
                    $before = $kind === 'versions' ? (array)json_decode((string)($db->fetchOne('SELECT data FROM `' . $db->t('site_remote') . "` WHERE site_id = :s AND kind = 'versions' AND state = 'ok'", ['s' => $id])['data'] ?? ''), true) : [];
                    [$items, $data] = self::fetch($site, $secret, $kind, $route);
                    self::save($id, $kind, 'ok', $data, $items, null);
                    if ($kind === 'versions') { self::warnVersions($site, $before, (array)$data); }
                    $profileChanged = $profileChanged || in_array($kind, ['site', 'store'], true);
                } catch (JobFailedException $e) {
                    $denied = $e->httpStatus === 403 && stripos($e->getMessage(), 'not approved') !== false;
                    if ($denied) { self::forget($id, $kind, 'not_allowed'); }
                    else { self::mark($id, $kind, 'error', $e->getMessage()); }
                } catch (JobRetryException $e) {
                    self::mark($id, $kind, 'error', $e->getMessage());
                }
            }
            if ($profileChanged) { SiteProfile::queueUnlessOpen($id); }
        } catch (\Throwable $e) {
            Logger::warn('site_remote_failed', ['site' => $id, 'error' => get_class($e)]);
        }
    }

    /** @return array{0:int,1:mixed} item count and the data to keep (null when it lives in its own table) */
    private static function fetch(array $site, string $secret, string $kind, string $route): array
    {
        return match ($kind) {
            'site' => self::fetchSite($site, $secret, $route),
            'content' => self::fetchContent($site, $secret, $route),
            'seo' => self::fetchSeo($site, $secret, $route),
            'redirects' => self::fetchRedirects($site, $secret, $route),
            'store' => self::fetchStore($site, $secret, $route),
            'media' => self::fetchMedia($site, $secret, $route),
            'versions' => self::fetchVersions($site, $secret, $route),
        };
    }

    private static function fetchSite(array $site, string $secret, string $route): array
    {
        $r = self::call($site, $secret, $route);
        $lang = SiteProfile::clean($r['language'] ?? null, 35);
        $d = array_filter([
            'site_title' => SiteProfile::clean($r['title'] ?? null, SiteProfile::FIELDS['site_title'][2]),
            'description' => SiteProfile::clean($r['tagline'] ?? null, SiteProfile::FIELDS['description'][2]),
            'language' => $lang !== null && preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8}){0,3}$/D', $lang) ? $lang : null,
        ], static fn ($v) => $v !== null);
        return [count($d), $d];
    }

    private static function fetchStore(array $site, string $secret, string $route): array
    {
        $r = self::call($site, $secret, $route);
        if (empty($r['available'])) { return [0, []]; }
        $street = SiteProfile::clean(trim((string)($r['street'] ?? '') . ' ' . (string)($r['street2'] ?? '')), SiteProfile::FIELDS['street'][2]);
        $d = array_filter([
            'street' => $street,
            'city' => SiteProfile::clean($r['city'] ?? null, SiteProfile::FIELDS['city'][2]),
            'region' => SiteProfile::clean($r['region'] ?? null, SiteProfile::FIELDS['region'][2]),
            'postal_code' => SiteProfile::clean($r['postcode'] ?? null, SiteProfile::FIELDS['postal_code'][2]),
            'country' => SiteProfile::clean($r['country'] ?? null, SiteProfile::FIELDS['country'][2]),
        ], static fn ($v) => $v !== null);
        return [count($d), $d];
    }

    private static function fetchContent(array $site, string $secret, string $route): array
    {
        $rows = []; $cursor = 0; $capped = false;
        for ($page = 0; $page < intdiv(self::MAX_ITEMS, 200) + 1; $page++) {
            $r = self::call($site, $secret, $route, null, ['cursor' => $cursor, 'limit' => 200]);
            foreach (is_array($r['items'] ?? null) ? $r['items'] : [] as $it) {
                if (!is_array($it)) { continue; }
                $path = self::path($it['path'] ?? null);
                if ($path === null) { continue; }
                $type = is_string($it['type'] ?? null) && preg_match('/^[a-z0-9_-]{1,30}$/Di', $it['type']) ? strtolower($it['type']) : 'page';
                $m = strtotime(is_string($it['modified'] ?? null) ? $it['modified'] : '');
                $rows[sha1($path)] = [$path, $type, SiteProfile::clean($it['title'] ?? null, 300), $m ? gmdate('Y-m-d H:i:s', $m) : null];
                if (count($rows) >= self::MAX_ITEMS) { $capped = true; break 2; }
            }
            $next = $r['next'] ?? null;
            if (!is_int($next) || $next <= $cursor) { break; }
            $cursor = $next;
        }
        $db = Database::getInstance();
        $t = $db->t('site_pages');
        $db->transaction(function (Database $db) use ($site, $rows, $t): void {
            $db->query("DELETE FROM `$t` WHERE site_id = :s", ['s' => (int)$site['id']]);
            foreach ($rows as $hash => [$path, $type, $title, $mod]) {
                $db->query("INSERT INTO `$t` (site_id, path_hash, path, type, title, modified) VALUES (:s, :h, :p, :t, :ti, :m)", ['s' => (int)$site['id'], 'h' => $hash, 'p' => $path, 't' => $type, 'ti' => $title, 'm' => $mod]);
            }
        });
        return [count($rows), ['capped' => $capped]];
    }

    private static function fetchSeo(array $site, string $secret, string $route): array
    {
        $rows = []; $cursor = 0; $capped = false; $plugin = 'none'; $templated = 0;
        for ($page = 0; $page < intdiv(self::MAX_ITEMS, 200) + 1; $page++) {
            $r = self::call($site, $secret, $route, null, ['cursor' => $cursor, 'limit' => 200]);
            $p = is_string($r['plugin'] ?? null) && in_array($r['plugin'], ['yoast', 'rank_math'], true) ? $r['plugin'] : 'none';
            if ($p !== 'none') { $plugin = $p; }
            $templated += max(0, min(100000, (int)($r['templated'] ?? 0)));
            foreach (is_array($r['items'] ?? null) ? $r['items'] : [] as $it) {
                if (!is_array($it)) { continue; }
                $path = self::path($it['path'] ?? null);
                if ($path === null) { continue; }
                $rows[sha1($path)] = [$path, SiteProfile::clean($it['seo_title'] ?? null, 300), SiteProfile::clean($it['seo_description'] ?? null, 500), ($it['noindex'] ?? false) === true ? 1 : 0];
                if (count($rows) >= self::MAX_ITEMS) { $capped = true; break 2; }
            }
            $next = $r['next'] ?? null;
            if (!is_int($next) || $next <= $cursor) { break; }
            $cursor = $next;
        }
        $db = Database::getInstance();
        $t = $db->t('site_seo');
        $db->transaction(function (Database $db) use ($site, $rows, $t): void {
            $db->query("DELETE FROM `$t` WHERE site_id = :s", ['s' => (int)$site['id']]);
            foreach ($rows as $hash => [$path, $title, $desc, $noindex]) {
                $db->query("INSERT INTO `$t` (site_id, path_hash, path, seo_title, seo_description, noindex) VALUES (:s, :h, :p, :t, :d, :n)", ['s' => (int)$site['id'], 'h' => $hash, 'p' => $path, 't' => $title, 'd' => $desc, 'n' => $noindex]);
            }
        });
        return [count($rows), ['plugin' => $plugin, 'capped' => $capped, 'templated' => $templated]];
    }

    private static function fetchRedirects(array $site, string $secret, string $route): array
    {
        $r = self::call($site, $secret, $route);
        $out = [];
        foreach (is_array($r['items'] ?? null) ? $r['items'] : [] as $it) {
            if (!is_array($it) || count($out) >= self::MAX_REDIRECTS) { continue; }
            $src = self::path($it['source'] ?? null);
            $dst = is_string($it['target'] ?? null) ? trim($it['target']) : '';
            $type = (int)($it['type'] ?? 0);
            $okDst = ($dst !== '' && $dst[0] === '/' && !str_starts_with($dst, '//')) || (bool)preg_match('#^https?://[^\s/]+#i', $dst);
            if ($src === null || !$okDst || strlen($dst) > 1000 || preg_match('/[\x00-\x1F\x7F]/', $dst) || !in_array($type, [301, 302, 307, 308], true)) { continue; }
            $out[] = ['source' => $src, 'target' => $dst, 'type' => $type];
        }
        return [count($out), ['redirects' => $out]];
    }

    private static function fetchMedia(array $site, string $secret, string $route): array
    {
        $r = self::call($site, $secret, $route);
        $images = max(0, min(100000000, (int)($r['images'] ?? 0)));
        return [$images, ['images' => $images, 'images_without_alt' => min($images, max(0, (int)($r['images_without_alt'] ?? 0)))]];
    }

    private static function fetchVersions(array $site, string $secret, string $route): array
    {
        $r = self::call($site, $secret, $route);
        $n = static fn ($v): int => max(0, min(9999, (int)$v));
        $checked = (int)($r['checked_at'] ?? 0);
        return [1, [
            'wordpress' => SiteProfile::clean($r['wordpress'] ?? null, 20), 'php' => SiteProfile::clean($r['php'] ?? null, 20),
            'core_update' => ($r['core_update'] ?? false) === true, 'plugin_updates' => $n($r['plugin_updates'] ?? 0), 'theme_updates' => $n($r['theme_updates'] ?? 0),
            'active_plugins' => $n($r['active_plugins'] ?? 0), 'checked_at' => $checked > 946684800 && $checked <= time() + 86400 ? gmdate('Y-m-d H:i:s', $checked) : null,
        ] + self::phpSupport((string)($r['php'] ?? ''))];
    }

    /** Last day each PHP branch gets security fixes (php.net/supported-versions). Update this list when a new branch appears. */
    public const PHP_SECURITY_UNTIL = ['8.0' => '2023-11-26', '8.1' => '2025-12-31', '8.2' => '2026-12-31', '8.3' => '2027-12-31', '8.4' => '2028-12-31', '8.5' => '2029-12-31'];

    /** @return array{php_support:?string,php_until:?string} "ended", "soon" (within 90 days) or null when fine or unknown */
    public static function phpSupport(string $php, ?int $now = null): array
    {
        $now ??= time();
        if (!preg_match('/^(\d+)\.(\d+)/', $php, $m)) { return ['php_support' => null, 'php_until' => null]; }
        $branch = $m[1] . '.' . $m[2];
        if ((int)$m[1] < 8) { return ['php_support' => 'ended', 'php_until' => null]; }
        $until = self::PHP_SECURITY_UNTIL[$branch] ?? null;
        if ($until === null) { return ['php_support' => null, 'php_until' => null]; }
        $end = strtotime($until . ' 23:59:59 UTC');
        return ['php_support' => $end < $now ? 'ended' : ($end - $now < 90 * 86400 ? 'soon' : null), 'php_until' => $until];
    }

    /** Alert when something new needs attention: an update appears (or more of them), or PHP loses security support. Never for what was already known. */
    private static function warnVersions(array $site, array $before, array $now): void
    {
        $id = (int)$site['id']; $name = (string)$site['name'];
        $parts = [];
        if (!empty($now['core_update']) && empty($before['core_update'])) { $parts[] = 'a WordPress update'; }
        foreach (['plugin_updates' => 'plugin', 'theme_updates' => 'theme'] as $k => $word) {
            if ((int)($now[$k] ?? 0) > (int)($before[$k] ?? 0)) { $parts[] = (int)$now[$k] . ' ' . $word . ' update' . ((int)$now[$k] === 1 ? '' : 's'); }
        }
        if ($parts) { Alerts::notify('updates_waiting', $name . ' has ' . implode(', ', $parts) . ' waiting. Updates fix security problems; install them in WordPress (Dashboard, Updates).', 'warning', 'upd' . $id, $id); }
        $rank = ['ended' => 2, 'soon' => 1];
        $new = $now['php_support'] ?? null;
        if ($new !== null && ($rank[$new] ?? 0) > ($rank[$before['php_support'] ?? ''] ?? 0)) {
            $msg = $new === 'ended' ? $name . ' runs PHP ' . ($now['php'] ?? '?') . ', which no longer gets security fixes' . (!empty($now['php_until']) ? ' (ended ' . $now['php_until'] . ')' : '') . '. Ask your host to move the site to a newer PHP version.'
                : $name . ' runs PHP ' . ($now['php'] ?? '?') . ', which stops getting security fixes on ' . $now['php_until'] . '. Plan the move to a newer PHP version.';
            Alerts::notify('php_outdated', $msg, $new === 'ended' ? 'error' : 'warning', 'php' . $id, $id);
        }
    }

    /** One optional request. Refuses to start once the time budget is spent, so a slow site cannot hold the job (earlier data is kept). */
    private static function call(array $site, string $secret, string $route, ?array $json = null, array $query = []): array
    {
        if (microtime(true) > self::$deadline) { throw new JobRetryException('Out of time for this sync; the rest continues on the next one', 60); }
        Jobs::touch();
        return SiteSync::call($site, $secret, 'GET', $route, $json, $query);
    }

    /** A path from the site: starts with "/", no control characters, at most 500 characters; anything else is dropped. */
    private static function path(mixed $v): ?string
    {
        if (!is_string($v) || $v === '' || $v[0] !== '/' || str_starts_with($v, '//') || mb_strlen($v) > 500 || preg_match('/[\x00-\x1F\x7F]/', $v) || !mb_check_encoding($v, 'UTF-8')) { return null; }
        return $v;
    }

    // ------------------------------------------------------------------ storage

    private static function save(int $siteId, string $kind, string $state, mixed $data, int $items, ?string $error): void
    {
        $db = Database::getInstance();
        $json = $data === null ? null : (string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $db->query('INSERT INTO `' . $db->t('site_remote') . '` (site_id, kind, state, data, items, fetched_at, error) VALUES (:s, :k, :st, :d, :i, :f, :e)
            ON DUPLICATE KEY UPDATE state = VALUES(state), data = VALUES(data), items = VALUES(items), fetched_at = VALUES(fetched_at), error = VALUES(error)',
            ['s' => $siteId, 'k' => $kind, 'st' => $state, 'd' => $json, 'i' => max(0, $items), 'f' => gmdate('Y-m-d H:i:s'), 'e' => $error === null ? null : mb_substr($error, 0, 250)]);
    }

    /** A problem with one kind: recorded, earlier data stays. */
    private static function mark(int $siteId, string $kind, string $state, string $error): void
    {
        $db = Database::getInstance();
        $db->query('INSERT INTO `' . $db->t('site_remote') . '` (site_id, kind, state, error) VALUES (:s, :k, :st, :e) ON DUPLICATE KEY UPDATE state = VALUES(state), error = VALUES(error)',
            ['s' => $siteId, 'k' => $kind, 'st' => $state, 'e' => mb_substr($error, 0, 250)]);
    }

    /** The owner has not approved this (or the plugin cannot offer it): record that and delete anything stored from it earlier. */
    private static function forget(int $siteId, string $kind, string $state): void
    {
        $db = Database::getInstance();
        if ($kind === 'content') { $db->query('DELETE FROM `' . $db->t('site_pages') . '` WHERE site_id = :s', ['s' => $siteId]); }
        if ($kind === 'seo') { $db->query('DELETE FROM `' . $db->t('site_seo') . '` WHERE site_id = :s', ['s' => $siteId]); }
        $db->query('INSERT INTO `' . $db->t('site_remote') . '` (site_id, kind, state, data, items, fetched_at, error) VALUES (:s, :k, :st, NULL, 0, NULL, NULL)
            ON DUPLICATE KEY UPDATE state = VALUES(state), data = NULL, items = 0, fetched_at = NULL, error = NULL', ['s' => $siteId, 'k' => $kind, 'st' => $state]);
    }

    /** Profile items the plugin supplied (site details and shop address), for the setup guide. They take the place of what the public page showed. @return array<string,string> */
    public static function profileFound(int $siteId): array
    {
        $db = Database::getInstance();
        $out = [];
        foreach ($db->fetchAll('SELECT data FROM `' . $db->t('site_remote') . "` WHERE site_id = :s AND state = 'ok' AND kind IN ('site','store')", ['s' => $siteId]) as $r) {
            foreach ((array)json_decode((string)$r['data'], true) as $k => $v) {
                if (isset(SiteProfile::FIELDS[$k]) && is_string($v) && $v !== '') { $out[$k] = $v; }
            }
        }
        return $out;
    }

    /** What the setup guide shows for each kind. @return list<array<string,mixed>> */
    public static function view(int $siteId): array
    {
        $db = Database::getInstance();
        $rows = array_column($db->fetchAll('SELECT kind, state, data, items, fetched_at, error FROM `' . $db->t('site_remote') . '` WHERE site_id = :s', ['s' => $siteId]), null, 'kind');
        $out = [];
        foreach (self::KINDS as $kind => [$label]) {
            $r = $rows[$kind] ?? null;
            $out[] = ['kind' => $kind, 'label' => $label, 'state' => $r['state'] ?? 'unknown', 'items' => (int)($r['items'] ?? 0), 'fetched_at' => $r['fetched_at'] ?? null,
                'error' => $r['error'] ?? null, 'summary' => $r && $r['state'] === 'ok' ? self::summary($kind, (array)json_decode((string)$r['data'], true), (int)$r['items']) : null];
        }
        return $out;
    }

    private static function summary(string $kind, array $d, int $items): string
    {
        return match ($kind) {
            'site' => $items . ' details read',
            'content' => $items . ' pages and posts' . (!empty($d['capped']) ? ' (the first ' . self::MAX_ITEMS . ')' : ''),
            'seo' => ($d['plugin'] ?? 'none') === 'none' ? 'No SEO plugin (Yoast or Rank Math) found'
                : $items . ' pages with a search title, description or noindex (' . ($d['plugin'] === 'yoast' ? 'Yoast' : 'Rank Math') . ')' . (!empty($d['templated']) ? '; ' . (int)$d['templated'] . ' use a template and are left out' : ''),
            'redirects' => $items . ' existing redirects',
            'store' => $items > 0 ? $items . ' address details read' : 'No shop address (WooCommerce is not set up)',
            'media' => $items . ' images, ' . (int)($d['images_without_alt'] ?? 0) . ' without alt text',
            'versions' => 'WordPress ' . ($d['wordpress'] ?? '?') . ', PHP ' . ($d['php'] ?? '?') . '; ' . (int)($d['plugin_updates'] ?? 0) . ' plugin and ' . (int)($d['theme_updates'] ?? 0) . ' theme updates waiting' . (!empty($d['core_update']) ? '; a WordPress update is waiting' : ''),
            default => '',
        };
    }
}
