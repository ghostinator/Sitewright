<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * One row per connected site for the dashboard's "All sites" view: latest score, 28-day search numbers and recent 404s.
 * A value is null when there is no data (never 0 for "unknown"). Later features add their own columns here.
 */
final class OverviewController
{
    public function sites(array $params): never
    {
        $db = Database::getInstance();
        $sites = $db->fetchAll('SELECT id, name, base_url, sync_enabled FROM `' . $db->t('sites') . '` ORDER BY name');
        $score = $this->byProject("SELECT project_id, SUBSTRING_INDEX(GROUP_CONCAT(score ORDER BY analyzed_at DESC, id DESC), ',', 1) v, MAX(analyzed_at) at FROM `{$db->t('seo_analysis')}` WHERE analysis_type = 'site' AND project_id > 0 AND score IS NOT NULL GROUP BY project_id");
        $search = $this->byProject("SELECT project_id, SUM(impressions) imp, SUM(clicks) clk FROM `{$db->t('analytics_keywords')}` WHERE project_id > 0 AND `date` >= CURDATE() - INTERVAL 28 DAY GROUP BY project_id");
        $nf = $this->byProject("SELECT project_id, COUNT(*) n FROM `{$db->t('404_monitor')}` WHERE project_id > 0 AND last_accessed >= NOW() - INTERVAL 30 DAY GROUP BY project_id");
        $out = [];
        foreach ($sites as $s) {
            $id = (int)$s['id'];
            $out[] = [
                'id' => $id, 'name' => $s['name'], 'base_url' => $s['base_url'], 'plugin' => (bool)(int)$s['sync_enabled'],
                'last_score' => isset($score[$id]) ? (int)$score[$id]['v'] : null, 'last_analyzed_at' => $score[$id]['at'] ?? null,
                'impressions' => isset($search[$id]) ? (int)$search[$id]['imp'] : null, 'clicks' => isset($search[$id]) ? (int)$search[$id]['clk'] : null,
                'not_found' => (int)($nf[$id]['n'] ?? 0),
            ];
        }
        Response::success('Overview', ['sites' => $out]);
    }

    /** @return array<int,array<string,mixed>> rows keyed by project_id */
    private function byProject(string $sql): array
    {
        $by = [];
        foreach (Database::getInstance()->fetchAll($sql) as $r) { $by[(int)$r['project_id']] = $r; }
        return $by;
    }
}
