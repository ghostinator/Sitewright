<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** Search opportunities for one site or all sites (read-only; every signed-in role). */
final class OpportunityController
{
    public function list(array $params): never
    {
        $project = Request::queryInt('project_id', 0, 0, PHP_INT_MAX);
        $type = Validator::enum(['t' => Request::query('type')], 't', Opportunities::TYPES, null);
        $r = (new Opportunities())->find($project, $type, Request::queryInt('limit', 100, 1, 500));
        $db = Database::getInstance();
        $names = array_column($db->fetchAll('SELECT id, name FROM `' . $db->t('sites') . '`'), 'name', 'id');
        foreach ($r['items'] as &$i) { $i['site'] = $names[$i['project_id']] ?? null; }
        $sync = $db->fetchOne('SELECT MAX(last_sync_at) at FROM `' . $db->t('gsc_connections') . '`' . ($project > 0 ? ' WHERE site_id = :p' : ''), $project > 0 ? ['p' => $project] : []);
        Response::success('Opportunities', $r + ['last_sync_at' => $sync['at'] ?? null,
            'note' => 'Potential clicks are a rough estimate from average click-through rates by position (about 28% at position 1, 11% at 3, 2.5% at 10). Use them to rank, not to promise.']);
    }
}
