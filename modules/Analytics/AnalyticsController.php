<?php
declare(strict_types=1);

namespace Sitewright\Modules\Analytics;

use Sitewright\Core\{Config, Database, Request, Response, Validator};

/**
 * Keyword metrics that YOU add (API) or import as JSON. There is no Google Search Console / OAuth
 * integration: the data is only as good as what is imported.
 */
class AnalyticsController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getDashboardStats(array $params): never
    {
        $k = $this->db->t('analytics_keywords');
        $kw = $this->db->fetchOne(
            "SELECT SUM(impressions) AS impressions, SUM(clicks) AS clicks, COUNT(DISTINCT keyword_hash) AS keywords, AVG(position) AS pos,
                    MAX(is_sample) AS sample FROM `$k` WHERE `date` BETWEEN (CURDATE() - INTERVAL 30 DAY) AND CURDATE()"
        ) ?? [];
        // Score of YOUR site only: competitor analyses are excluded.
        $seo = $this->db->fetchOne(
            'SELECT AVG(score) AS avg_score, COUNT(*) AS n, MAX(is_sample) AS sample FROM `' . $this->db->t('seo_analysis')
            . "` WHERE analysis_type = 'site' AND analyzed_at >= (NOW() - INTERVAL 30 DAY)"
        ) ?? [];
        $recent = $this->db->fetchAll(
            'SELECT analysis_type AS type, url, score, analyzed_at AS date, is_sample FROM `' . $this->db->t('seo_analysis') . '` ORDER BY analyzed_at DESC, id DESC LIMIT 5'
        );

        Response::success('Dashboard stats retrieved', [
            'seo_score' => (int)$seo['n'] > 0 ? (int)round((float)$seo['avg_score']) : null,
            'total_keywords' => (int)($kw['keywords'] ?? 0),
            'impressions' => (int)($kw['impressions'] ?? 0),
            'clicks' => (int)($kw['clicks'] ?? 0),
            'avg_position' => $kw['pos'] !== null ? round((float)$kw['pos'], 1) : null,
            'includes_sample_data' => (bool)($kw['sample'] ?? false) || (bool)($seo['sample'] ?? false),
            'recent_activity' => $recent,
        ]);
    }

    public function getDashboard(array $params): never
    {
        $start = Validator::date(['d' => Request::query('start_date')], 'd') ?? gmdate('Y-m-d', strtotime('-30 days'));
        $end = Validator::date(['d' => Request::query('end_date')], 'd') ?? gmdate('Y-m-d');
        $projectId = Request::queryInt('project_id', 0, 0, PHP_INT_MAX);
        $k = $this->db->t('analytics_keywords');
        $pw = $projectId > 0 ? ' AND project_id = :p' : '';
        $q = ['s' => $start, 'e' => $end] + ($projectId > 0 ? ['p' => $projectId] : []);

        $keywords = $this->db->fetchAll(
            "SELECT keyword, SUM(impressions) AS total_impressions, SUM(clicks) AS total_clicks, AVG(position) AS avg_position, AVG(ctr) AS avg_ctr, MAX(is_sample) AS is_sample
             FROM `$k` WHERE `date` BETWEEN :s AND :e$pw GROUP BY keyword ORDER BY total_impressions DESC LIMIT 20", $q);
        $summary = $this->db->fetchOne(
            "SELECT SUM(impressions) AS total_impressions, SUM(clicks) AS total_clicks, AVG(position) AS avg_position, COUNT(DISTINCT keyword_hash) AS total_keywords
             FROM `$k` WHERE `date` BETWEEN :s AND :e$pw", $q);
        Response::success('Analytics retrieved', ['keywords' => $keywords, 'summary' => $summary, 'range' => ['start' => $start, 'end' => $end]]);
    }

    public function addKeyword(array $params): never
    {
        $row = self::keywordRow(Request::json());
        $this->upsert([$row]);
        Response::success('Keyword data saved', ['keyword' => $row['keyword'], 'date' => $row['date']], 201);
    }

    /** JSON import: {"project_id":0,"keywords":[{keyword,date,impressions,clicks,position,ctr},...]} */
    public function importKeywords(array $params): never
    {
        $in = Request::json();
        $items = $in['keywords'] ?? null;
        $max = (int)Config::get('limits.import_rows');
        if (!is_array($items) || !$items) { Response::error('"keywords" must be a non-empty array', 400); }
        if (count($items) > $max) { Response::error("Too many rows (max $max)", 413); }
        $projectId = Validator::projectId($in);
        $rows = [];
        foreach (array_values($items) as $i => $item) {
            if (!is_array($item)) { Response::error('Item ' . ($i + 1) . ' must be an object', 400); }
            $rows[] = ['project_id' => $projectId] + self::keywordRow($item);
        }
        $this->upsert($rows);
        Response::success('Imported ' . count($rows) . ' keyword rows', ['count' => count($rows)]);
    }

    /** @return array{project_id:int,keyword:string,date:string,impressions:int,clicks:int,position:?float,ctr:?float} */
    public static function keywordRow(array $in): array
    {
        return [
            'project_id' => Validator::projectId($in),
            'keyword' => Validator::str($in, 'keyword', 500, true),
            'date' => Validator::date($in, 'date', true),
            'impressions' => Validator::int($in, 'impressions', 0, PHP_INT_MAX, 0),
            'clicks' => Validator::int($in, 'clicks', 0, PHP_INT_MAX, 0),
            'position' => Validator::float($in, 'position', 0, 999.99),
            'ctr' => Validator::float($in, 'ctr', 0, 100),
        ];
    }

    private function upsert(array $rows): void
    {
        $t = $this->db->t('analytics_keywords');
        $this->db->transaction(function (Database $db) use ($rows, $t): void {
            foreach ($rows as $r) {
                $db->query(
                    "INSERT INTO `$t` (project_id, keyword, impressions, clicks, position, ctr, `date`) VALUES (:project_id, :keyword, :impressions, :clicks, :position, :ctr, :date)
                     ON DUPLICATE KEY UPDATE impressions = VALUES(impressions), clicks = VALUES(clicks), position = VALUES(position), ctr = VALUES(ctr)",
                    $r
                );
            }
        });
    }
}
