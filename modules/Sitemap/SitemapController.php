<?php
declare(strict_types=1);

namespace Sitewright\Modules\Sitemap;

use Sitewright\Core\{Audit, Config, Database, FetchException, Html, Request, Response, SafeHttpClient, Url, Validator};

/** Plain XML URL sitemaps (the "general" type). Crawling is bounded, polite and goes through SafeHttpClient. */
class SitemapController
{
    private const CHANGEFREQ = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getAll(array $params): never
    {
        $projectId = Request::queryInt('project_id', 0, 0, PHP_INT_MAX);
        $rows = $this->db->fetchAll(
            'SELECT id, project_id, type, url, priority, changefreq, last_modified, created_at FROM `' . $this->db->t('sitemaps') . '`'
            . ($projectId > 0 ? ' WHERE project_id = :p' : '') . ' ORDER BY created_at DESC, id DESC LIMIT 5000',
            $projectId > 0 ? ['p' => $projectId] : []
        );
        Response::success('Sitemap entries retrieved', $rows);
    }

    public function add(array $params): never
    {
        $in = Request::json();
        $row = [
            'project_id' => Validator::projectId($in),
            'type' => 'general',
            'url' => Validator::url($in, 'url', 1000),
            'priority' => round((float)Validator::float($in, 'priority', 0, 1, 0.5), 1),
            'changefreq' => Validator::enum($in, 'changefreq', self::CHANGEFREQ, 'weekly'),
            'last_modified' => Validator::datetime($in, 'last_modified') ?? gmdate('Y-m-d H:i:s'),
        ];
        if (isset($in['type']) && $in['type'] !== 'general') { Validator::fail('type', 'only "general" sitemaps are supported'); }
        $this->upsert([$row]);
        Response::success('Sitemap entry saved', ['url' => $row['url']], 201);
    }

    public function delete(array $params): never
    {
        $id = Validator::id($params['id'] ?? null);
        $n = $this->db->delete('sitemaps', ['id' => $id]);
        if ($n) { Audit::log('sitemap_entry_deleted', null, '', "id $id"); }
        $n ? Response::success('Sitemap entry deleted') : Response::notFound('Sitemap entry not found');
    }

    public function generateXML(array $params): never
    {
        Validator::enum(['t' => Request::query('type')], 't', ['general'], 'general');
        $projectId = Request::queryInt('project_id', 0, 0, PHP_INT_MAX);
        $rows = $this->db->fetchAll(
            'SELECT url, priority, changefreq, last_modified FROM `' . $this->db->t('sitemaps') . '` WHERE type = \'general\''
            . ($projectId > 0 ? ' AND project_id = :p' : '') . ' ORDER BY url LIMIT 50000',
            $projectId > 0 ? ['p' => $projectId] : []
        );
        Response::raw(self::buildXml($rows), 'application/xml; charset=utf-8');
    }

    public static function buildXml(array $entries): string
    {
        $x = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($entries as $e) {
            $xml .= "  <url>\n    <loc>" . $x((string)$e['url']) . "</loc>\n";
            if (!empty($e['last_modified']) && ($ts = strtotime((string)$e['last_modified'])) !== false) { $xml .= '    <lastmod>' . gmdate('Y-m-d', $ts) . "</lastmod>\n"; }
            if (in_array($e['changefreq'], self::CHANGEFREQ, true)) { $xml .= '    <changefreq>' . $x((string)$e['changefreq']) . "</changefreq>\n"; }
            if ($e['priority'] !== null && is_numeric($e['priority'])) { $xml .= '    <priority>' . $x(number_format(min(1, max(0, (float)$e['priority'])), 1, '.', '')) . "</priority>\n"; }
            $xml .= "  </url>\n";
        }
        return $xml . '</urlset>' . "\n";
    }

    public function crawlAndGenerate(array $params): never
    {
        $in = Request::json();
        $base = Validator::url($in, 'base_url');
        $projectId = Validator::projectId($in);
        $hardMax = (int)Config::get('limits.max_pages');
        $maxPages = Validator::int($in, 'max_pages', 1, PHP_INT_MAX, 100);
        $maxPages = min($maxPages, $hardMax); // client value can never exceed the server cap
        $budget = (int)Config::get('limits.crawl_seconds');
        set_time_limit($budget + 30);

        $result = $this->crawl($base, $maxPages, microtime(true) + $budget);
        if ($result['pages'] === [] && $result['error'] !== null) {
            Response::error('Could not crawl that site: ' . $result['error'], 502);
        }
        $rows = array_map(static fn (string $u): array => [
            'project_id' => $projectId, 'type' => 'general', 'url' => $u, 'priority' => 0.5, 'changefreq' => 'weekly', 'last_modified' => gmdate('Y-m-d H:i:s'),
        ], $result['pages']);
        $added = $this->upsert($rows);

        Response::success("Crawled {$result['fetched']} pages, found " . count($rows) . ' URLs', [
            'total_found' => count($rows), 'added' => $added, 'fetched' => $result['fetched'],
            'stopped_early' => $result['stopped_early'], 'max_pages' => $maxPages,
        ]);
    }

    /**
     * Breadth-first same-origin crawl. Only HTTP 200 HTML pages are listed.
     * @return array{pages:string[],fetched:int,stopped_early:bool,error:?string}
     */
    public function crawl(string $startUrl, int $maxPages, float $deadline): array
    {
        $client = SafeHttpClient::default();
        $delay = (int)Config::get('limits.crawl_delay_ms') * 1000;
        $start = Url::normalize($startUrl);
        if ($start === null) { return ['pages' => [], 'fetched' => 0, 'stopped_early' => false, 'error' => 'Invalid URL']; }

        $origin = null; $disallow = null;
        $queue = [$start]; $queued = [$start => true]; $pages = []; $fetched = 0; $error = null; $stopped = false;

        while ($queue) {
            if (count($pages) >= $maxPages || microtime(true) > $deadline) { $stopped = true; break; }
            $url = array_shift($queue);
            if ($origin !== null && $disallow !== null && $this->blocked($url, $disallow)) { continue; }
            if ($fetched > 0) { usleep($delay); }
            try {
                $res = $client->get($url, ['max_bytes' => (int)Config::get('fetch.max_html_bytes'), 'deadline' => $deadline]);
            } catch (FetchException $e) {
                $error ??= $e->getMessage();
                $fetched++;
                continue;
            }
            $fetched++;
            $final = Url::normalize($res['final_url']);
            if ($final === null) { continue; }
            if ($origin === null) {
                $origin = Url::origin($final);          // the start URL may redirect (http->https, www): follow that
                $disallow = $this->robots($client, $origin, $deadline);
            }
            if (Url::origin($final) !== $origin) { continue; }
            if ($res['status'] !== 200 || !in_array($res['content_type'], ['text/html', 'application/xhtml+xml'], true)) { continue; }
            if ($this->blocked($final, $disallow ?? [])) { continue; }

            $pages[$final] = true;
            $dom = Html::load($res['body'], (string)($res['headers']['content-type'] ?? ''));
            foreach ($dom->getElementsByTagName('a') as $a) {
                $abs = Url::resolve($final, trim($a->getAttribute('href')));
                $norm = $abs !== null ? Url::normalize($abs) : null; // drops mailto:/tel:/javascript:/data:, fragments
                if ($norm === null || isset($queued[$norm]) || Url::origin($norm) !== $origin) { continue; }
                if (count($queued) >= $maxPages * 20) { break; } // bound memory on link-heavy sites
                $queued[$norm] = true;
                $queue[] = $norm;
            }
        }
        return ['pages' => array_keys($pages), 'fetched' => $fetched, 'stopped_early' => $stopped || ($queue !== [] && count($pages) >= $maxPages), 'error' => $pages ? null : $error];
    }

    /** @return string[] Disallow path prefixes for "User-agent: *" */
    private function robots(SafeHttpClient $client, string $origin, float $deadline): array
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

    private function blocked(string $url, array $disallow): bool
    {
        $p = (string)parse_url($url, PHP_URL_PATH) . (($q = parse_url($url, PHP_URL_QUERY)) ? '?' . $q : '');
        foreach ($disallow as $rule) {
            if ($rule !== '' && str_starts_with($p, $rule)) { return true; }
        }
        return false;
    }

    /** @return int number of NEW rows */
    private function upsert(array $rows): int
    {
        $t = $this->db->t('sitemaps');
        return (int)$this->db->transaction(function (Database $db) use ($rows, $t): int {
            $new = 0;
            foreach ($rows as $r) {
                $n = $db->query(
                    "INSERT INTO `$t` (project_id, type, url, priority, changefreq, last_modified) VALUES (:project_id, :type, :url, :priority, :changefreq, :last_modified)
                     ON DUPLICATE KEY UPDATE priority = VALUES(priority), changefreq = VALUES(changefreq), last_modified = VALUES(last_modified)", $r
                )->rowCount();
                $new += $n === 1 ? 1 : 0; // MySQL: 1 = inserted, 2 = updated
            }
            return $new;
        });
    }
}
