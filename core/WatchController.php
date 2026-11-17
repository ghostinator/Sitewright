<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** Watchlist API: viewers read, editors add, change, run and delete. */
final class WatchController
{
    public function list(array $params): never
    {
        $db = Database::getInstance();
        $project = Request::queryInt('project_id', 0, 0, PHP_INT_MAX);
        $w = $project > 0 ? ' WHERE w.project_id = :p' : '';
        $items = $db->fetchAll('SELECT w.id, w.project_id, s.name AS site, w.url, w.label, w.interval_hours, w.enabled, w.last_run_at, w.next_run_at, w.last_score, w.last_status, w.last_error, w.fail_count'
            . ' FROM `' . $db->t('watch_items') . '` w LEFT JOIN `' . $db->t('sites') . "` s ON s.id = w.project_id$w ORDER BY w.last_status = 'down' DESC, s.name, w.url", $project > 0 ? ['p' => $project] : []);
        $trend = [];
        if ($items) {
            $ids = implode(',', array_map(static fn (array $i): int => (int)$i['id'], $items));
            foreach ($db->fetchAll('SELECT watch_id, score FROM (SELECT watch_id, score, ROW_NUMBER() OVER (PARTITION BY watch_id ORDER BY analyzed_at DESC, id DESC) rn FROM `' . $db->t('seo_analysis')
                . "` WHERE watch_id IN ($ids)) x WHERE rn <= 12 ORDER BY watch_id, rn DESC") as $r) { $trend[(int)$r['watch_id']][] = $r['score'] === null ? null : (int)$r['score']; }
        }
        foreach ($items as &$i) {
            $i['enabled'] = (bool)(int)$i['enabled']; $i['last_score'] = $i['last_score'] === null ? null : (int)$i['last_score']; $i['trend'] = $trend[(int)$i['id']] ?? [];
            $i['interval_hours'] = (int)$i['interval_hours']; $i['fail_count'] = (int)$i['fail_count'];
        }
        Response::success('Watchlist', ['items' => $items]);
    }

    public function create(array $params): never
    {
        $in = Request::json();
        $url = Validator::url($in, 'url', 500);
        $project = Validator::siteId($in);
        $hours = Validator::int($in, 'interval_hours', 1, 720, 24) ?? 24;
        $label = Validator::str($in, 'label', 100) ?? '';
        $db = Database::getInstance(); $t = $db->t('watch_items');
        if ((int)$db->fetchOne("SELECT COUNT(*) n FROM `$t`")['n'] >= (int)Config::get('watch.max_items')) { Response::error('The watchlist is full (' . (int)Config::get('watch.max_items') . ' pages). Remove one or raise the limit in Settings.', 409); }
        if ($db->fetchOne("SELECT 1 x FROM `$t` WHERE project_id = :p AND url_hash = SHA2(:u, 256)", ['p' => $project, 'u' => $url])) { Response::error('That page is already on the watchlist.', 409); }
        $id = (int)$db->insert('watch_items', ['project_id' => $project, 'url' => $url, 'label' => $label, 'interval_hours' => $hours, 'created_by' => $this->userId()]);
        Audit::log('watch_added', null, '', $url);
        Response::success('Added to the watchlist. The first check runs within a minute or two.', ['id' => $id], 201);
    }

    public function update(array $params): never
    {
        $id = Validator::id($params['id'] ?? null); $in = Request::json();
        $this->find($id);
        $set = [];
        if (array_key_exists('enabled', $in)) { $set['enabled'] = filter_var($in['enabled'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0; }
        if (array_key_exists('interval_hours', $in)) { $set['interval_hours'] = Validator::int($in, 'interval_hours', 1, 720); }
        if (array_key_exists('label', $in)) { $set['label'] = Validator::str($in, 'label', 100) ?? ''; }
        if ($set === []) { Response::error('Nothing to change', 400); }
        Database::getInstance()->update('watch_items', $set, ['id' => $id]);
        Response::success('Saved');
    }

    public function run(array $params): never
    {
        $id = Validator::id($params['id'] ?? null);
        $this->find($id);
        Response::success('Check queued', ['job_id' => Jobs::enqueue('watch_run', ['watch_id' => $id], $this->userId())], 202);
    }

    public function delete(array $params): never
    {
        $id = Validator::id($params['id'] ?? null);
        $w = $this->find($id);
        $db = Database::getInstance();
        $db->delete('seo_analysis', ['watch_id' => $id]);
        $db->delete('watch_items', ['id' => $id]);
        Audit::log('watch_removed', null, '', $w['url']);
        Response::success('Removed from the watchlist');
    }

    public function history(array $params): never
    {
        $id = Validator::id($params['id'] ?? null);
        $this->find($id);
        $limit = Request::queryInt('limit', 120, 1, 500);
        $db = Database::getInstance();
        $rows = $db->fetchAll('SELECT analyzed_at, score, results FROM `' . $db->t('seo_analysis') . '` WHERE watch_id = :i ORDER BY analyzed_at DESC, id DESC LIMIT :l', ['i' => $id, 'l' => $limit]);
        $points = [];
        foreach (array_reverse($rows) as $r) {
            $x = json_decode((string)$r['results'], true) ?: [];
            $points[] = ['at' => $r['analyzed_at'], 'score' => $r['score'] === null ? null : (int)$r['score'], 'load_time' => $x['performance']['load_time'] ?? null,
                'word_count' => $x['basic']['word_count'] ?? null, 'noindex' => (bool)($x['basic']['noindex'] ?? false), 'title_length' => $x['basic']['title_length'] ?? null];
        }
        Response::success('History', ['points' => $points]);
    }

    /** The CLI (in-process tests) has no session. */
    private function userId(): ?int
    {
        return PHP_SAPI === 'cli' ? null : ((int)(Auth::user()['id'] ?? 0) ?: null);
    }

    private function find(int $id): array
    {
        $db = Database::getInstance();
        return $db->fetchOne('SELECT id, url FROM `' . $db->t('watch_items') . '` WHERE id = :i', ['i' => $id]) ?? Response::notFound('Not on the watchlist');
    }
}
