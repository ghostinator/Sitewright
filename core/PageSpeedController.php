<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** Core Web Vitals: viewers read, editors start a measurement. Settings decide whether the feature is on. */
final class PageSpeedController
{
    public function status(array $params): never
    {
        Response::success('PageSpeed', ['enabled' => PageSpeed::enabled(), 'strategy' => Config::get('pagespeed.strategy') === 'desktop' ? 'desktop' : 'mobile', 'has_key' => (string)Config::get('pagespeed.api_key') !== '']);
    }

    public function run(array $params): never
    {
        if (!PageSpeed::enabled()) { Response::error('Core Web Vitals measurements are turned off. An administrator can turn them on under Settings, Integrations.', 409); }
        $in = Request::json();
        $url = (string)Validator::url($in, 'url', 500);
        $project = Validator::siteId($in);
        try { SafeHttpClient::default()->validate($url); } catch (FetchException $e) { Response::error('That address cannot be measured: ' . $e->getMessage(), 400); }
        $uid = PHP_SAPI === 'cli' ? null : ((int)(Auth::user()['id'] ?? 0) ?: null);
        Response::success('Measurement queued', ['job_id' => Jobs::enqueue('pagespeed', ['url' => $url, 'project_id' => $project], $uid, 0, 1)], 202);
    }

    public function history(array $params): never
    {
        $db = Database::getInstance();
        $q = []; $w = [];
        $url = Request::query('url');
        if ($url !== null && $url !== '') { $w[] = 'url = :u'; $q['u'] = $url; }
        $project = Request::queryInt('project_id', 0, 0, PHP_INT_MAX);
        if ($project > 0) { $w[] = 'project_id = :p'; $q['p'] = $project; }
        $limit = Request::queryInt('limit', 30, 1, 200);
        $rows = $db->fetchAll('SELECT id, project_id, watch_id, url, strategy, status, error, perf_score, lcp_ms, cls, tbt_ms, fcp_ms, ttfb_ms, field_category, field_lcp_ms, field_cls, field_inp_ms, fetched_at FROM `'
            . $db->t('pagespeed_runs') . '`' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY id DESC LIMIT $limit", $q);
        Response::success('Measurements', ['runs' => $rows]);
    }
}
