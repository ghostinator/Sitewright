<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * CSV downloads for the monitoring data. UTF-8 with a byte-order mark (so Excel reads accented titles), one fixed file name per export
 * (no user input in headers), every text cell neutralised against spreadsheet formulas. None of these contain visitor IP addresses.
 * "PDF" is the browser's own Save as PDF from the print-styled report, not a server-side renderer.
 */
final class ExportController
{
    private const OPP_LABELS = ['ctr_gap' => 'Low click-through', 'striking_distance' => 'Within reach of page one', 'cannibalisation' => 'Pages competing', 'falling' => 'Losing clicks'];

    public function audit(array $params): never
    {
        $db = Database::getInstance();
        $id = Validator::id($params['id'] ?? null);
        if (!$db->fetchOne('SELECT 1 x FROM `' . $db->t('audits') . '` WHERE id = :i', ['i' => $id])) { Response::notFound('Audit not found'); }
        $part = Validator::enum(['p' => Request::query('part')], 'p', ['issues', 'pages'], 'issues');
        if ($part === 'pages') {
            $rows = array_map(static fn (array $r): array => [$r['url'], $r['title'], $r['score'], $r['word_count'], $r['load_ms'], $r['inbound'], $r['noindex'] ? 'Yes' : 'No'],
                $db->fetchAll('SELECT url, title, score, word_count, load_ms, inbound, noindex FROM `' . $db->t('audit_pages') . '` WHERE audit_id = :a ORDER BY score IS NULL, score, url LIMIT 100000', ['a' => $id]));
            $this->send('audit-pages', ['Page', 'Title', 'Score', 'Words', 'Fetch time (ms)', 'Linked from', 'Noindex'], $rows);
        }
        $rows = array_map(static fn (array $r): array => [ucfirst($r['severity']), SiteAudit::CATALOG[$r['code']][1] ?? $r['code'], $r['url'], $r['detail']],
            $db->fetchAll('SELECT code, severity, url, detail FROM `' . $db->t('audit_issues') . "` WHERE audit_id = :a ORDER BY FIELD(severity, 'high', 'medium', 'low'), code, url LIMIT 100000", ['a' => $id]));
        $this->send('audit-issues', ['Priority', 'Problem', 'Page', 'Detail'], $rows);
    }

    public function watch(array $params): never
    {
        $db = Database::getInstance();
        $project = Request::queryInt('project_id', 0, 0, PHP_INT_MAX);
        $rows = array_map(static fn (array $r): array => [$r['site'], $r['url'], $r['label'], $r['last_status'], $r['last_score'], $r['interval_hours'], $r['last_run_at'], $r['last_error']],
            $db->fetchAll('SELECT s.name AS site, w.url, w.label, w.last_status, w.last_score, w.interval_hours, w.last_run_at, w.last_error FROM `' . $db->t('watch_items') . '` w LEFT JOIN `' . $db->t('sites') . '` s ON s.id = w.project_id'
                . ($project > 0 ? ' WHERE w.project_id = :p' : '') . ' ORDER BY s.name, w.url LIMIT 100000', $project > 0 ? ['p' => $project] : []));
        $this->send('watchlist', ['Site', 'Page', 'Name', 'Status', 'Score', 'Checked every (hours)', 'Last check', 'Last error'], $rows);
    }

    public function watchHistory(array $params): never
    {
        $db = Database::getInstance();
        $id = Validator::id($params['id'] ?? null);
        if (!$db->fetchOne('SELECT 1 x FROM `' . $db->t('watch_items') . '` WHERE id = :i', ['i' => $id])) { Response::notFound('Not on the watchlist'); }
        $rows = [];
        foreach ($db->fetchAll('SELECT analyzed_at, score, results FROM `' . $db->t('seo_analysis') . '` WHERE watch_id = :i ORDER BY analyzed_at DESC, id DESC LIMIT 20000', ['i' => $id]) as $r) {
            $x = json_decode((string)$r['results'], true) ?: [];
            $rows[] = [$r['analyzed_at'], $r['score'], $x['performance']['load_time'] ?? '', $x['basic']['word_count'] ?? '', !empty($x['basic']['noindex']) ? 'Yes' : 'No'];
        }
        $this->send('watch-history', ['Checked', 'Score', 'Fetch time (s)', 'Words', 'Noindex'], $rows);
    }

    public function overview(array $params): never
    {
        $rows = array_map(static fn (array $r): array => [$r['name'], $r['base_url'], $r['last_score'], $r['watched'], $r['down'], $r['impressions'], $r['clicks'], $r['not_found']], OverviewController::rows());
        $this->send('sites-overview', ['Site', 'Address', 'Latest score', 'Watched pages', 'Pages down', 'Impressions (28d)', 'Clicks (28d)', '404s (30d)'], $rows);
    }

    public function opportunities(array $params): never
    {
        $type = Validator::enum(['t' => Request::query('type')], 't', Opportunities::TYPES, null);
        $found = (new Opportunities())->find(Request::queryInt('project_id', 0, 0, PHP_INT_MAX), $type, 500);
        $db = Database::getInstance();
        $names = array_column($db->fetchAll('SELECT id, name FROM `' . $db->t('sites') . '`'), 'name', 'id');
        $rows = array_map(static fn (array $i): array => [self::OPP_LABELS[$i['type']] ?? $i['type'], $names[$i['project_id']] ?? '', $i['query'], $i['page'], $i['impressions'], $i['clicks'], $i['ctr'], $i['position'], $i['potential_clicks'], $i['advice']], $found['items']);
        $this->send('search-opportunities', ['Type', 'Site', 'Query', 'Page', 'Views', 'Clicks', 'Click-through %', 'Position', 'Could gain (clicks)', 'What to do'], $rows);
    }

    public function analyses(array $params): never
    {
        $db = Database::getInstance();
        $type = Validator::enum(['t' => Request::query('type')], 't', ['site', 'competitor'], 'site');
        $project = Request::queryInt('project_id', 0, 0, PHP_INT_MAX);
        $rows = [];
        foreach ($db->fetchAll('SELECT url, analysis_type, score, results, watch_id, analyzed_at FROM `' . $db->t('seo_analysis') . '` WHERE analysis_type = :t' . ($project > 0 ? ' AND project_id = :p' : '')
            . ' ORDER BY analyzed_at DESC, id DESC LIMIT 5000', ['t' => $type] + ($project > 0 ? ['p' => $project] : [])) as $r) {
            $x = json_decode((string)$r['results'], true) ?: [];
            $rows[] = [$r['analyzed_at'], $r['url'], $r['analysis_type'], $r['score'], $x['basic']['title_length'] ?? '', $x['meta']['description_length'] ?? '', $x['headings']['h1_count'] ?? '', $x['basic']['word_count'] ?? '',
                $x['performance']['load_time'] ?? '', isset($x['security']['uses_https']) ? ($x['security']['uses_https'] ? 'Yes' : 'No') : '', !empty($x['basic']['noindex']) ? 'Yes' : 'No', $r['watch_id'] ? 'Yes' : 'No'];
        }
        $this->send('seo-analyses', ['Analysed', 'Page', 'Type', 'Score', 'Title length', 'Description length', 'H1 count', 'Words', 'Fetch time (s)', 'HTTPS', 'Noindex', 'From the watchlist'], $rows);
    }

    public function keywords(array $params): never
    {
        $db = Database::getInstance();
        $project = Request::queryInt('project_id', 0, 0, PHP_INT_MAX);
        $days = Request::queryInt('days', 28, 1, 365);
        $rows = array_map(static fn (array $r): array => [$r['keyword'], $r['imp'], $r['clk'], $r['pos'] === null ? '' : round((float)$r['pos'], 1), (int)$r['imp'] > 0 ? round((int)$r['clk'] / (int)$r['imp'] * 100, 2) : ''],
            $db->fetchAll('SELECT keyword, SUM(impressions) imp, SUM(clicks) clk, AVG(position) pos FROM `' . $db->t('analytics_keywords') . '` WHERE `date` >= CURDATE() - INTERVAL ' . $days . ' DAY' . ($project > 0 ? ' AND project_id = :p' : '')
                . ' GROUP BY keyword_hash, keyword ORDER BY imp DESC LIMIT 50000', $project > 0 ? ['p' => $project] : []));
        $this->send('keywords', ['Keyword', 'Views', 'Clicks', 'Average position', 'Click-through %'], $rows);
    }

    public function redirectSuggestions(array $params): never
    {
        $rows = [];
        foreach (SuggestionController::compute(Request::queryInt('project_id', 0, 0, PHP_INT_MAX))['items'] as $i) {
            foreach ($i['suggestions'] as $s) { $rows[] = [$i['uri'], $i['hits'], $s['target'], $s['score'], $s['reason']]; }
        }
        $this->send('redirect-suggestions', ['404 address', 'Hits', 'Suggested page', 'Match %', 'Why'], $rows);
    }

    /** @param string[] $header @param iterable<array<int,mixed>> $rows */
    private function send(string $name, array $header, iterable $rows): never
    {
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header, ',', '"', '');
        foreach ($rows as $row) { fputcsv($out, array_map(static fn (mixed $v): string|int|float => is_int($v) || is_float($v) ? $v : Validator::csvCell($v), $row), ',', '"', ''); }
        rewind($out);
        Response::raw((string)stream_get_contents($out), 'text/csv; charset=utf-8', ['Content-Disposition' => 'attachment; filename="' . $name . '-' . gmdate('Y-m-d') . '.csv"', 'X-Content-Type-Options' => 'nosniff']);
    }
}
