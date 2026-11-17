<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * On-page SEO checks for one URL. The page is fetched ONCE through SafeHttpClient and every check reads that single response.
 * Checks are simple heuristics, not a substitute for Lighthouse or Search Console. Used by the analysis page, the watchlist and the audit.
 */
final class SeoAnalyzer
{
    /**
     * Fetch, analyse, score and store one page.
     * @return array{id:int,url:string,requested_url:string,score:int,results:array}
     * @throws AnalysisException with the HTTP status a request should answer with
     */
    public static function run(string $url, int $projectId = 0, string $type = 'site', ?int $watchId = null): array
    {
        try {
            $res = SafeHttpClient::default()->get($url, ['max_bytes' => (int)Config::get('fetch.max_html_bytes')]);
        } catch (FetchException $e) {
            throw new AnalysisException('Could not analyse that URL: ' . $e->getMessage(), $e->httpStatus === 400 ? 400 : 502);
        }
        if ($res['status'] >= 400) {
            throw new AnalysisException('The site answered with HTTP ' . $res['status'] . '; nothing to analyse.', 502);
        }
        if (!in_array($res['content_type'], ['text/html', 'application/xhtml+xml', ''], true)) {
            throw new AnalysisException('The URL did not return an HTML page (' . $res['content_type'] . ').', 422);
        }
        $results = self::analyseHtml($res);
        $score = self::calculateScore($results);
        $id = Database::getInstance()->insert('seo_analysis', [
            'project_id' => $projectId, 'watch_id' => $watchId,
            'url' => mb_substr($res['final_url'], 0, 500), 'analysis_type' => $type === 'competitor' ? 'competitor' : 'site',
            'score' => $score, 'results' => json_encode($results, JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
        return ['id' => (int)$id, 'url' => $res['final_url'], 'requested_url' => $url, 'score' => $score, 'results' => $results];
    }

    /** @param array{final_url:string,status:int,headers:array,body:string,bytes:int,elapsed_ms:int,redirects:array} $res */
    public static function analyseHtml(array $res): array
    {
        $dom = Html::load($res['body'], (string)($res['headers']['content-type'] ?? ''));
        $xp = new \DOMXPath($dom);
        $final = $res['final_url'];
        $host = strtolower((string)parse_url($final, PHP_URL_HOST));

        $title = trim((string)($dom->getElementsByTagName('title')->item(0)?->textContent ?? ''));
        $meta = [];
        foreach ($xp->query('//meta') ?: [] as $tag) {
            $name = strtolower($tag->getAttribute('name') ?: $tag->getAttribute('property'));
            if ($name !== '' && !isset($meta[$name])) { $meta[$name] = $tag->getAttribute('content'); }
        }
        $desc = trim($meta['description'] ?? '');
        $robots = strtolower(($meta['robots'] ?? '') . ',' . (string)($res['headers']['x-robots-tag'] ?? ''));
        $canonical = (string)($xp->query('//link[@rel="canonical"]/@href')->item(0)?->nodeValue ?? '');
        $lang = (string)($dom->getElementsByTagName('html')->item(0)?->getAttribute('lang') ?? '');

        $headings = [];
        for ($i = 1; $i <= 6; $i++) {
            $headings["h$i"] = [];
            foreach ($dom->getElementsByTagName("h$i") as $h) { $headings["h$i"][] = trim(preg_replace('/\s+/u', ' ', $h->textContent) ?? ''); }
        }

        $images = [];
        $withAlt = 0;
        foreach ($dom->getElementsByTagName('img') as $img) {
            $alt = trim($img->getAttribute('alt'));
            $withAlt += $alt !== '' ? 1 : 0;
            $images[] = ['src' => $img->getAttribute('src') ?: $img->getAttribute('data-src'), 'alt' => $alt, 'has_alt' => $alt !== ''];
        }
        $totalImages = count($images);

        $internal = $external = $nofollow = 0;
        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href === '' || $href[0] === '#') { continue; }
            $abs = Url::resolve($final, $href);
            $scheme = $abs !== null ? (Url::parse($abs)['scheme'] ?? '') : '';
            if (!in_array($scheme, ['http', 'https'], true)) { continue; } // mailto:, tel:, javascript: ...
            if (stripos($a->getAttribute('rel'), 'nofollow') !== false) { $nofollow++; }
            strtolower((string)parse_url($abs, PHP_URL_HOST)) === $host ? $internal++ : $external++;
        }

        $schemas = [];
        foreach ($xp->query('//script[@type="application/ld+json"]') ?: [] as $s) {
            $d = json_decode($s->textContent, true);
            if (is_array($d)) { $schemas[] = $d; }
        }

        $text = Html::visibleText($dom);
        $viewport = $xp->query('//meta[@name="viewport"]')->item(0);
        $loadTime = round($res['elapsed_ms'] / 1000, 3);
        $titleLen = mb_strlen($title);
        $descLen = mb_strlen($desc);

        return [
            'fetch' => [
                'status_code' => $res['status'], 'final_url' => $final, 'redirect_count' => count($res['redirects']),
                'content_type' => (string)($res['content_type'] ?? ''),
            ],
            'basic' => [
                'title' => $title, 'title_length' => $titleLen, 'title_optimal' => $titleLen >= (int)Config::get('seo.title_min') && $titleLen <= (int)Config::get('seo.title_max'),
                'html_size' => $res['bytes'], 'word_count' => Html::wordCount($text),
                'canonical' => $canonical, 'lang' => $lang, 'noindex' => str_contains($robots, 'noindex'),
            ],
            'meta' => [
                'description' => $desc, 'description_length' => $descLen, 'description_optimal' => $descLen >= (int)Config::get('seo.meta_min') && $descLen <= (int)Config::get('seo.meta_max'),
                'keywords' => $meta['keywords'] ?? '', 'robots' => $meta['robots'] ?? '',
                'og_title' => $meta['og:title'] ?? '', 'og_description' => $meta['og:description'] ?? '',
                'og_image' => $meta['og:image'] ?? '', 'twitter_card' => $meta['twitter:card'] ?? '',
            ],
            'headings' => [
                'headings' => $headings, 'h1_count' => count($headings['h1']), 'h1_optimal' => count($headings['h1']) === 1,
            ],
            'images' => [
                'total_images' => $totalImages, 'images_with_alt' => $withAlt, 'images_without_alt' => $totalImages - $withAlt,
                'alt_ratio' => $totalImages > 0 ? round($withAlt / $totalImages * 100, 2) : 0, 'images' => array_slice($images, 0, 200),
            ],
            'links' => ['total_links' => $internal + $external, 'internal_links' => $internal, 'external_links' => $external, 'nofollow_links' => $nofollow],
            'performance' => [
                'load_time' => $loadTime, 'load_time_optimal' => $loadTime < (float)Config::get('seo.load_fast'), 'page_size' => $res['bytes'],
                'note' => 'Server-side fetch time of the HTML document only; not a Core Web Vitals measurement.',
            ],
            'viewport' => ['has_viewport' => $viewport !== null, 'viewport_content' => $viewport ? $viewport->getAttribute('content') : ''],
            'security' => ['uses_https' => str_starts_with($final, 'https://')],
            'structured_data' => ['has_schema' => count($schemas) > 0, 'schema_count' => count($schemas), 'schemas' => array_slice($schemas, 0, 10)],
            'method' => 'heuristic',
        ];
    }

    public static function calculateScore(array $r): int
    {
        $w = static fn (string $k): int => (int)Config::get("seo.w.$k");
        $s = 0;
        if ($r['basic']['title'] !== '') { $s += $w('title_present') + ($r['basic']['title_optimal'] ? $w('title_ok') : 0); }
        if ($r['meta']['description'] !== '') { $s += $w('meta_present') + ($r['meta']['description_optimal'] ? $w('meta_ok') : 0); }
        if ($r['headings']['h1_optimal']) { $s += $w('h1'); }
        $alt = $r['images']['alt_ratio'];
        $s += $alt >= (float)Config::get('seo.alt_high') ? $w('alt_high') : ($alt >= (float)Config::get('seo.alt_mid') ? $w('alt_mid') : ($alt > 0 ? $w('alt_low') : 0));
        $s += $r['performance']['load_time_optimal'] ? $w('load_fast') : ($r['performance']['load_time'] < (float)Config::get('seo.load_ok') ? $w('load_ok') : $w('load_slow'));
        if ($r['viewport']['has_viewport']) { $s += $w('viewport'); }
        if ($r['security']['uses_https']) { $s += $w('https'); }
        if ($r['structured_data']['has_schema']) { $s += $w('schema'); }
        if ($r['links']['internal_links'] > (int)Config::get('seo.internal_min')) { $s += $w('internal'); }
        if ($r['links']['external_links'] > 0) { $s += $w('external'); }
        $wc = $r['basic']['word_count'];
        $s += ($wc >= (int)Config::get('seo.words_good') ? $w('words_good') : 0) + ($wc >= (int)Config::get('seo.words_great') ? $w('words_great') : 0);
        return min(100, $s);
    }
}
