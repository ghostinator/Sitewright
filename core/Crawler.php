<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Same-origin, polite crawl through SafeHttpClient (robots.txt honoured, page and time caps, pause between requests).
 * The sitemap generator and the site audit both use it; hooks let a caller see each response without the crawler keeping page bodies.
 *   on_page(string $finalUrl, array $res, \DOMDocument $dom)                 each accepted internal HTML page (200)
 *   on_fetch(string $requestedUrl, ?array $res, ?string $error)               every internal fetch, including errors and non-200 answers
 *   on_links(string $finalUrl, string[] $internal, string[] $external)        normalised link targets found on that page
 *   on_tick()                                                                 once per loop (long jobs refresh their heartbeat here)
 *   seeds: string[]                                                           extra start URLs (same origin as the start URL), e.g. the sitemap list
 */
final class Crawler
{
    private const MAX_LINKS_PER_PAGE = 500;
    /** Query keys of links that ask the site to DO something (Jetpack share buttons, WooCommerce add-to-cart, comment reply, nonces): not pages, so never followed or reported. */
    private const ACTION_KEYS = ['share', 'add-to-cart', 'add_to_cart', 'add_to_wishlist', 'remove_item', 'removed_item', 'undo_item', 'wc-ajax', 'replytocom', '_wpnonce', 'redirect_to', 'wp_logout', 'action'];
    private const ACTION_PATHS = ['/wp-login.php', '/wp-admin', '/xmlrpc.php', '/wp-cron.php'];

    /** A link that triggers an action (share, add to cart, log out, reply) rather than showing a page. */
    public static function isActionLink(string $url): bool
    {
        $path = strtolower((string)parse_url($url, PHP_URL_PATH));
        foreach (self::ACTION_PATHS as $p) { if ($path === $p || str_starts_with($path, $p . '/')) { return true; } }
        $q = (string)parse_url($url, PHP_URL_QUERY);
        if ($q === '') { return false; }
        parse_str($q, $keys);
        foreach (array_keys($keys) as $k) { if (in_array(strtolower((string)$k), self::ACTION_KEYS, true)) { return true; } }
        return false;
    }

    /**
     * @param array{on_page?:callable,on_fetch?:callable,on_links?:callable,on_tick?:callable,seeds?:array} $hooks
     * @return array{pages:string[],fetched:int,stopped_early:bool,error:?string,actions_skipped:int}
     */
    public static function run(string $startUrl, int $maxPages, float $deadline, array $hooks = []): array
    {
        $client = SafeHttpClient::default();
        $delay = (int)Config::get('limits.crawl_delay_ms') * 1000;
        $start = Url::normalize($startUrl);
        if ($start === null) { return ['pages' => [], 'fetched' => 0, 'stopped_early' => false, 'error' => 'Invalid URL']; }

        $origin = null; $disallow = null;
        $queue = [$start]; $queued = [$start => true];
        foreach ((array)($hooks['seeds'] ?? []) as $seed) {
            $n = is_string($seed) ? Url::normalize($seed) : null;
            if ($n !== null && !isset($queued[$n]) && Url::origin($n) === Url::origin($start) && count($queued) < $maxPages * 20) { $queued[$n] = true; $queue[] = $n; }
        } $pages = []; $fetched = 0; $error = null; $stopped = false; $actions = 0;

        while ($queue) {
            if (isset($hooks['on_tick'])) { ($hooks['on_tick'])(); }
            if (count($pages) >= $maxPages || microtime(true) > $deadline) { $stopped = true; break; }
            $url = array_shift($queue);
            if ($origin !== null && $disallow !== null && self::blocked($url, $disallow)) { continue; }
            if ($fetched > 0) { usleep($delay); }
            try {
                $res = $client->get($url, ['max_bytes' => (int)Config::get('fetch.max_html_bytes'), 'deadline' => $deadline]);
            } catch (FetchException $e) {
                $error ??= $e->getMessage();
                $fetched++;
                if (isset($hooks['on_fetch'])) { ($hooks['on_fetch'])($url, null, $e->getMessage()); }
                continue;
            }
            $fetched++;
            if (isset($hooks['on_fetch'])) { ($hooks['on_fetch'])($url, $res, null); }
            $final = Url::normalize($res['final_url']);
            if ($final === null) { continue; }
            if ($origin === null) {
                $origin = Url::origin($final);          // the start URL may redirect (http->https, www): follow that
                $disallow = self::robots($client, $origin, $deadline);
            }
            if (Url::origin($final) !== $origin) { continue; }
            if ($res['status'] !== 200 || !in_array($res['content_type'], ['text/html', 'application/xhtml+xml'], true)) { continue; }
            if (self::blocked($final, $disallow ?? [])) { continue; }

            $pages[$final] = true;
            $dom = Html::load($res['body'], (string)($res['headers']['content-type'] ?? ''));
            if (isset($hooks['on_page'])) { ($hooks['on_page'])($final, $res, $dom); }
            $internal = []; $external = [];
            foreach ($dom->getElementsByTagName('a') as $a) {
                $abs = Url::resolve($final, trim($a->getAttribute('href')));
                $norm = $abs !== null ? Url::normalize($abs) : null; // drops mailto:/tel:/javascript:/data:, fragments
                if ($norm === null) { continue; }
                if (Url::origin($norm) !== $origin) {
                    if (count($external) < self::MAX_LINKS_PER_PAGE) { $external[$norm] = true; }
                    continue;
                }
                if (self::isActionLink($norm)) { $actions++; continue; }
                if (count($internal) < self::MAX_LINKS_PER_PAGE) { $internal[$norm] = true; }
                if (isset($queued[$norm])) { continue; }
                if (count($queued) >= $maxPages * 20) { continue; } // bound memory on link-heavy sites
                $queued[$norm] = true;
                $queue[] = $norm;
            }
            if (isset($hooks['on_links'])) { ($hooks['on_links'])($final, array_keys($internal), array_keys($external)); }
        }
        return ['pages' => array_keys($pages), 'fetched' => $fetched, 'stopped_early' => $stopped || ($queue !== [] && count($pages) >= $maxPages), 'error' => $pages ? null : $error, 'actions_skipped' => $actions];
    }

    /** @return string[] Disallow prefixes that apply to every user agent */
    private static function robots(SafeHttpClient $client, string $origin, float $deadline): array
    {
        try {
            $r = $client->get($origin . '/robots.txt', ['max_bytes' => 262144, 'timeout' => 5, 'deadline' => $deadline, 'accept' => 'text/plain,*/*;q=0.5']);
        } catch (FetchException) {
            return [];
        }
        if ($r['status'] !== 200) { return []; }
        $rules = []; $applies = false;
        foreach (preg_split('/\r?\n/', $r['body']) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
            if (preg_match('/^user-agent:\s*(.+)$/i', $line, $m)) { $applies = trim($m[1]) === '*'; }
            elseif ($applies && preg_match('/^disallow:\s*(\S+)$/i', $line, $m)) { $rules[] = $m[1]; }
        }
        return $rules;
    }

    private static function blocked(string $url, array $disallow): bool
    {
        $p = (string)parse_url($url, PHP_URL_PATH) . (($q = parse_url($url, PHP_URL_QUERY)) ? '?' . $q : '');
        foreach ($disallow as $rule) {
            if ($rule !== '' && str_starts_with($p, $rule)) { return true; }
        }
        return false;
    }
}
