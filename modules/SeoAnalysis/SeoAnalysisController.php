<?php
declare(strict_types=1);

namespace Sitewright\Modules\SeoAnalysis;

use Sitewright\Core\{AnalysisException, Database, Request, Response, SeoAnalyzer, Validator};

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
            $r = SeoAnalyzer::run($url, $projectId, $isCompetitor ? 'competitor' : 'site');
        } catch (AnalysisException $e) {
            Response::error($e->getMessage(), $e->httpStatus);
        }
        Response::success('Analysis complete', $r);
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

    public static function analyseHtml(array $res): array { return SeoAnalyzer::analyseHtml($res); }

    public static function calculateScore(array $r): int { return SeoAnalyzer::calculateScore($r); }
}
