<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Things worth fixing, found in the Search Console data you imported (last 28 days of query+page rows, plus the daily keyword history):
 *  ctr_gap            page-one query whose click-through is under half of what its position normally gets
 *  striking_distance  a query ranking just below page one (position range is a setting)
 *  cannibalisation    several of your pages compete for the same query
 *  falling            a keyword that lost most of its clicks against the previous 28 days
 * potential_clicks is a rough estimate from industry-average click-through by position; use it to rank, not to promise.
 */
final class Opportunities
{
    public const TYPES = ['ctr_gap', 'striking_distance', 'cannibalisation', 'falling'];
    /** Average organic click-through (%) by position 1..10. */
    private const CTR = [1 => 28.5, 2 => 15.7, 3 => 11.0, 4 => 8.0, 5 => 7.2, 6 => 5.1, 7 => 4.0, 8 => 3.2, 9 => 2.8, 10 => 2.5];
    private const ADVICE = [
        'ctr_gap' => 'People see this result but rarely click it. Rewrite the page title and meta description so they promise what the searcher wants.',
        'striking_distance' => 'Close to page one. Strengthen the page for this query: answer it more fully, add internal links to it, and use the query in the headings.',
        'cannibalisation' => 'Several of your pages compete for this query. Merge them, or make one clearly the best answer and point the others to it.',
        'falling' => 'Clicks dropped a lot compared with the previous 28 days. Check what changed on the page, its ranking, and whether a competitor overtook it.',
    ];

    /** @return array{has_data:bool,items:array,summary:array} */
    public function find(int $projectId, ?string $type = null, int $limit = 100): array
    {
        $db = Database::getInstance();
        $sp = $db->t('search_pages'); $kw = $db->t('analytics_keywords');
        $w = $projectId > 0 ? ' WHERE project_id = :p' : ''; $q = $projectId > 0 ? ['p' => $projectId] : [];
        $has = $db->fetchOne("SELECT 1 x FROM `$sp`$w LIMIT 1", $q) || $db->fetchOne("SELECT 1 x FROM `$kw`$w LIMIT 1", $q);
        $min = max(1, (int)Config::get('search.min_impressions'));
        $from = (float)Config::get('search.striking_from'); $to = (float)Config::get('search.striking_to');
        $items = [];
        $rows = $db->fetchAll("SELECT project_id, page, query, impressions, clicks, ctr, position FROM `$sp`" . ($projectId > 0 ? ' WHERE project_id = :p' : '') . ' ORDER BY impressions DESC LIMIT 50000', $q);
        $byQuery = [];
        foreach ($rows as $r) {
            $imp = (int)$r['impressions']; $pos = (float)$r['position']; $ctr = (float)$r['ctr'];
            $byQuery[(int)$r['project_id'] . '|' . mb_strtolower($r['query'])][] = $r;
            if ($imp < $min) { continue; }
            if ($pos > 0 && $pos <= 10.0) {
                $expected = self::CTR[max(1, min(10, (int)round($pos)))];
                if ($ctr < $expected * 0.5) { $items[] = $this->item('ctr_gap', $r, (int)round($imp * ($expected - $ctr) / 100)); }
            }
            if ($pos >= $from && $pos <= $to) {
                $items[] = $this->item('striking_distance', $r, (int)round(0.5 * $imp * max(0.0, self::CTR[3] - $ctr) / 100));   // half the way to the top three: be modest
            }
        }
        foreach ($byQuery as $group) {
            if (count($group) < 2) { continue; }
            $total = array_sum(array_map(static fn (array $r): int => (int)$r['impressions'], $group));
            $competing = array_values(array_filter($group, static fn (array $r): bool => (int)$r['impressions'] >= $min / 2 && (int)$r['impressions'] >= 0.15 * $total));
            if (count($competing) < 2) { continue; }
            usort($competing, static fn (array $a, array $b): int => (int)$b['impressions'] <=> (int)$a['impressions']);
            $lead = $competing[0];
            $item = $this->item('cannibalisation', $lead, 0);
            $item['impressions'] = array_sum(array_map(static fn (array $r): int => (int)$r['impressions'], $competing));
            $item['clicks'] = array_sum(array_map(static fn (array $r): int => (int)$r['clicks'], $competing));
            $item['other_pages'] = array_map(static fn (array $r): string => (string)$r['page'], array_slice($competing, 1));
            $items[] = $item;
        }
        $recent = gmdate('Y-m-d', strtotime('-27 days')); $prevFrom = gmdate('Y-m-d', strtotime('-55 days'));
        foreach ($db->fetchAll("SELECT project_id, keyword, SUM(CASE WHEN `date` >= :r THEN clicks ELSE 0 END) now_clicks, SUM(CASE WHEN `date` < :r2 THEN clicks ELSE 0 END) prev_clicks, SUM(CASE WHEN `date` >= :r3 THEN impressions ELSE 0 END) imp"
            . " FROM `$kw` WHERE `date` >= :pf" . ($projectId > 0 ? ' AND project_id = :p' : '') . ' GROUP BY project_id, keyword HAVING prev_clicks >= 10 AND now_clicks <= 0.6 * prev_clicks',
            ['r' => $recent, 'r2' => $recent, 'r3' => $recent, 'pf' => $prevFrom] + $q) as $r) {
            $it = $this->item('falling', ['project_id' => $r['project_id'], 'page' => '', 'query' => $r['keyword'], 'impressions' => $r['imp'], 'clicks' => $r['now_clicks'], 'ctr' => 0, 'position' => 0], (int)$r['prev_clicks'] - (int)$r['now_clicks']);
            $it['previous_clicks'] = (int)$r['prev_clicks'];
            $items[] = $it;
        }
        $summary = array_fill_keys(self::TYPES, 0); $summary['potential_clicks'] = 0;
        foreach ($items as $i) { $summary[$i['type']]++; $summary['potential_clicks'] += $i['potential_clicks']; }
        if ($type !== null) { $items = array_values(array_filter($items, static fn (array $i): bool => $i['type'] === $type)); }
        usort($items, static fn (array $a, array $b): int => [$b['potential_clicks'], $b['impressions']] <=> [$a['potential_clicks'], $a['impressions']]);
        return ['has_data' => (bool)$has, 'items' => array_slice($items, 0, max(1, $limit)), 'summary' => $summary];
    }

    private function item(string $type, array $r, int $potential): array
    {
        return ['type' => $type, 'project_id' => (int)$r['project_id'], 'query' => (string)$r['query'], 'page' => (string)$r['page'], 'impressions' => (int)$r['impressions'], 'clicks' => (int)$r['clicks'],
            'ctr' => round((float)$r['ctr'], 2), 'position' => round((float)$r['position'], 1), 'potential_clicks' => max(0, $potential), 'advice' => self::ADVICE[$type]];
    }
}
