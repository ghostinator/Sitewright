<?php
declare(strict_types=1);

namespace Sitewright\Modules\SeoAnalysis;

use Sitewright\Core\{Config, Database, FetchException, Html, Request, Response, SafeHttpClient, Url, Validator};

/**
 * On-page SEO checks for one URL. The page is fetched ONCE through SafeHttpClient and every check
 * reads that single response. Checks are simple heuristics, not a substitute for Lighthouse or Search Console.
 */
class SeoAnalysisController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function analyze(array $params): never
    {
        $in = Request::json();
        $url = Validator::url($in, 'url');
        $projectId = Validator::projectId($in);
        $isCompetitor = filter_var($in['is_competitor'] ?? false, FILTER_VALIDATE_BOOLEAN);

        try {
            $res = SafeHttpClient::default()->get($url, ['max_bytes' => (int)Config::get('fetch.max_html_bytes')]);
        } catch (FetchException $e) {
            Response::error('Could not analyse that URL: ' . $e->getMessage(), $e->httpStatus === 400 ? 400 : 502);
        }
        if ($res['status'] >= 400) {
            Response::error('The site answered with HTTP ' . $res['status'] . '; nothing to analyse.', 502);
        }
        if (!in_array($res['content_type'], ['text/html', 'application/xhtml+xml', ''], true)) {
            Response::error('The URL did not return an HTML page (' . $res['content_type'] . ').', 422);
        }

        $results = self::analyseHtml($res);
        $score = self::calculateScore($results);

        $id = $this->db->insert('seo_analysis', [
            'project_id' => $projectId,
            'url' => mb_substr($res['final_url'], 0, 500),
            'analysis_type' => $isCompetitor ? 'competitor' : 'site',
            'score' => $score,
            'results' => json_encode($results, JSON_INVALID_UTF8_SUBSTITUTE),
        ]);

        Response::success('Analysis complete', [
            'id' => (int)$id, 'url' => $res['final_url'], 'requested_url' => $url, 'score' => $score, 'results' => $results,
        ]);
    }

    public function getHistory(array $params): never
    {
        $type = Validator::enum(['t' => Request::query('type')], 't', ['site', 'competitor'], 'site');
        $limit = Request::queryInt('limit', 10, 1, 100);
        $projectId = Request::queryInt('project_id', 0, 0, PHP_INT_MAX);

        $sql = 'SELECT id, project_id, url, analysis_type, score, results, analyzed_at, is_sample FROM `' . $this->db->t('seo_analysis') . '` WHERE analysis_type = :type';
        $q = ['type' => $type];
        if ($projectId > 0) { $sql .= ' AND project_id = :project_id'; $q['project_id'] = $projectId; }
        $sql .= ' ORDER BY analyzed_at DESC, id DESC LIMIT :limit';
        $q['limit'] = $limit;

        $rows = $this->db->fetchAll($sql, $q);
        foreach ($rows as &$r) { $r['results'] = json_decode((string)$r['results'], true); }
        Response::success('Analysis history retrieved', $rows);
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
                'title' => $title, 'title_length' => $titleLen, 'title_optimal' => $titleLen >= 30 && $titleLen <= 60,
                'html_size' => $res['bytes'], 'word_count' => Html::wordCount($text),
                'canonical' => $canonical, 'lang' => $lang, 'noindex' => str_contains($robots, 'noindex'),
            ],
            'meta' => [
                'description' => $desc, 'description_length' => $descLen, 'description_optimal' => $descLen >= 120 && $descLen <= 160,
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
                'load_time' => $loadTime, 'load_time_optimal' => $loadTime < 3, 'page_size' => $res['bytes'],
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
        $s = 0;
        if ($r['basic']['title'] !== '') { $s += 5 + ($r['basic']['title_optimal'] ? 5 : 0); }
        if ($r['meta']['description'] !== '') { $s += 5 + ($r['meta']['description_optimal'] ? 5 : 0); }
        if ($r['headings']['h1_optimal']) { $s += 10; }
        $alt = $r['images']['alt_ratio'];
        $s += $alt >= 90 ? 15 : ($alt >= 50 ? 10 : ($alt > 0 ? 5 : 0));
        $s += $r['performance']['load_time_optimal'] ? 15 : ($r['performance']['load_time'] < 5 ? 10 : 5);
        if ($r['viewport']['has_viewport']) { $s += 10; }
        if ($r['security']['uses_https']) { $s += 10; }
        if ($r['structured_data']['has_schema']) { $s += 10; }
        if ($r['links']['internal_links'] > 5) { $s += 5; }
        if ($r['links']['external_links'] > 0) { $s += 5; }
        $w = $r['basic']['word_count'];
        $s += ($w >= 300 ? 5 : 0) + ($w >= 1000 ? 5 : 0);
        return min(100, $s);
    }
}
