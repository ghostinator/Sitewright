<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** Suggested redirects for the 404 log. Paths only (no visitor data), so every signed-in role may read them; accepting one is an ordinary rule add (editor). */
final class SuggestionController
{
    public function redirects(array $params): never
    {
        Response::success('Suggestions', self::compute(Request::queryInt('project_id', 0, 0, PHP_INT_MAX)));
    }

    /** @return array{has_inventory:bool,source:string,items:array,unmatched:int} */
    public static function compute(int $project): array
    {
        $db = Database::getInstance();
        $rows = $db->fetchAll('SELECT id, project_id, uri, hits FROM `' . $db->t('404_monitor') . '`' . ($project > 0 ? ' WHERE project_id = :p' : '') . ' ORDER BY hits DESC, last_accessed DESC, id LIMIT 300', $project > 0 ? ['p' => $project] : []);
        $handled = [];
        foreach ($db->fetchAll('SELECT project_id, source_url FROM `' . $db->t('redirections') . "` WHERE status = 'active'") as $r) { $handled[(int)$r['project_id'] . '|' . strtolower($r['source_url'])] = true; }
        $indexes = []; $sources = []; $items = []; $unmatched = 0;
        foreach ($rows as $r) {
            $pid = (int)$r['project_id'];
            if (isset($handled[$pid . '|' . strtolower($r['uri'])])) { continue; }
            if (!isset($indexes[$pid])) { $inv = RedirectSuggester::inventory($pid); $sources[$pid] = $inv['source']; $indexes[$pid] = RedirectSuggester::index($inv['candidates']); }
            $s = $sources[$pid] === 'none' ? [] : RedirectSuggester::suggestIn($indexes[$pid], (string)$r['uri'], 3);
            if ($s === []) { $unmatched++; continue; }
            if (count($items) < 100) { $items[] = ['id' => (int)$r['id'], 'project_id' => $pid, 'uri' => $r['uri'], 'hits' => (int)$r['hits'], 'suggestions' => $s]; }
        }
        if ($project > 0 && !isset($sources[$project])) { $sources[$project] = RedirectSuggester::inventory($project)['source']; }
        $have = array_values(array_unique(array_diff($sources, ['none'])));
        return ['has_inventory' => $have !== [], 'source' => count($have) === 0 ? 'none' : (count($have) === 1 ? $have[0] : 'mixed'), 'items' => $items, 'unmatched' => $unmatched];
    }
}
