<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** Site audits: viewers read, editors start and delete. */
final class SiteAuditController
{
    public function start(array $params): never
    {
        $in = Request::json();
        $project = Validator::siteId($in);
        $db = Database::getInstance();
        if ($project > 0) {
            $site = $db->fetchOne('SELECT base_url FROM `' . $db->t('sites') . '` WHERE id = :i', ['i' => $project]);
            $url = (string)$site['base_url'];
        } else {
            $url = (string)Validator::url($in, 'base_url');
        }
        try {
            $r = SiteAudit::start($project, $url, PHP_SAPI === 'cli' ? null : ((int)(Auth::user()['id'] ?? 0) ?: null));
        } catch (AuditBusyException $e) {
            Response::error($e->getMessage(), 409);
        } catch (\InvalidArgumentException) {
            Validator::fail('base_url', 'is not a valid address');
        }
        Audit::log('site_audit_started', null, '', $url);
        Response::success('Audit queued', $r, 202);
    }

    public function list(array $params): never
    {
        $db = Database::getInstance();
        $project = Request::queryInt('project_id', 0, 0, PHP_INT_MAX);
        $rows = $db->fetchAll('SELECT a.id, a.project_id, s.name AS site, a.base_url, a.status, a.pages, a.error, a.summary, a.created_at, a.finished_at FROM `' . $db->t('audits') . '` a LEFT JOIN `' . $db->t('sites')
            . '` s ON s.id = a.project_id' . ($project > 0 ? ' WHERE a.project_id = :p' : '') . ' ORDER BY a.id DESC LIMIT 50', $project > 0 ? ['p' => $project] : []);
        foreach ($rows as &$r) {
            $s = json_decode((string)$r['summary'], true) ?: [];
            $r['by_severity'] = $s['by_severity'] ?? null; $r['new'] = $s['new'] ?? null; $r['fixed'] = $s['fixed'] ?? null; $r['avg_score'] = $s['avg_score'] ?? null; $r['pages'] = (int)$r['pages'];
            unset($r['summary']);
        }
        Response::success('Audits', ['audits' => $rows]);
    }

    public function show(array $params): never
    {
        $a = $this->find(Validator::id($params['id'] ?? null));
        $s = json_decode((string)$a['summary'], true) ?: [];
        $types = [];
        foreach (($s['by_code'] ?? []) as $code => $n) {
            [$sev, $title, $advice] = SiteAudit::CATALOG[$code] ?? ['low', $code, ''];
            $types[] = ['code' => $code, 'severity' => $sev, 'title' => $title, 'advice' => $advice, 'count' => (int)$n];
        }
        $order = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($types, static fn (array $x, array $y): int => [$order[$x['severity']], -$x['count']] <=> [$order[$y['severity']], -$y['count']]);
        unset($a['summary']);
        Response::success('Audit', ['audit' => $a + ['summary' => $s], 'types' => $types]);
    }

    public function issues(array $params): never
    {
        $id = $this->find(Validator::id($params['id'] ?? null))['id'];
        $q = ['a' => $id]; $w = '';
        $code = Request::query('code');
        if ($code !== null && $code !== '') {
            if (!isset(SiteAudit::CATALOG[$code])) { Validator::fail('code', 'unknown issue type'); }
            $w .= ' AND code = :c'; $q['c'] = $code;
        }
        $limit = Request::queryInt('limit', 100, 1, 500); $offset = Request::queryInt('offset', 0, 0, 1000000);
        $db = Database::getInstance();
        $rows = $db->fetchAll('SELECT code, severity, url, detail FROM `' . $db->t('audit_issues') . "` WHERE audit_id = :a$w ORDER BY FIELD(severity, 'high', 'medium', 'low'), code, url LIMIT $limit OFFSET $offset", $q);
        Response::success('Issues', ['issues' => $rows]);
    }

    public function pages(array $params): never
    {
        $id = $this->find(Validator::id($params['id'] ?? null))['id'];
        $limit = Request::queryInt('limit', 50, 1, 500);
        $db = Database::getInstance();
        $rows = $db->fetchAll('SELECT url, title, title_len, desc_len, h1_count, word_count, noindex, score, load_ms, inbound FROM `' . $db->t('audit_pages') . "` WHERE audit_id = :a ORDER BY score IS NULL, score ASC, url LIMIT $limit", ['a' => $id]);
        Response::success('Pages', ['pages' => $rows]);
    }

    public function delete(array $params): never
    {
        $id = $this->find(Validator::id($params['id'] ?? null))['id'];
        $db = Database::getInstance();
        foreach (['audit_issues', 'audit_pages'] as $t) { $db->delete($t, ['audit_id' => $id]); }
        $db->delete('audits', ['id' => $id]);
        Response::success('Audit deleted');
    }

    private function find(int $id): array
    {
        $db = Database::getInstance();
        $a = $db->fetchOne('SELECT a.id, a.project_id, s.name AS site, a.base_url, a.status, a.pages, a.stopped_early, a.error, a.summary, a.created_at, a.started_at, a.finished_at FROM `' . $db->t('audits') . '` a LEFT JOIN `' . $db->t('sites') . '` s ON s.id = a.project_id WHERE a.id = :i', ['i' => $id]) ?? Response::notFound('Audit not found');
        $a['id'] = (int)$a['id']; $a['pages'] = (int)$a['pages'];
        return $a;
    }
}
