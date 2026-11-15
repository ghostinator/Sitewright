<?php
declare(strict_types=1);

namespace Sitewright\Modules\Monitor;

use Sitewright\Core\{Audit, Config, Database, Request, Response, Validator};

/**
 * 404 log. This application does NOT detect 404s on your site by itself: entries are added manually
 * (POST /api/404-monitor/log) or imported from your server logs. Stored data: URI, referer, user agent, IP.
 */
class MonitorController
{
    private const ORDER_COLUMNS = ['last_accessed', 'hits', 'created_at', 'uri', 'id'];
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getLogs(array $params): never
    {
        $orderBy = in_array(Request::query('order_by'), self::ORDER_COLUMNS, true) ? Request::query('order_by') : 'last_accessed';
        $order = strtoupper((string)Request::query('order', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $page = Request::queryInt('page', 1, 1, 1000000);
        $perPage = Request::queryInt('per_page', 20, 1, 100);
        $projectId = Request::queryInt('project_id', 0, 0, PHP_INT_MAX);

        $t = $this->db->t('404_monitor');
        $where = $projectId > 0 ? ' WHERE project_id = :project_id' : '';
        $q = $projectId > 0 ? ['project_id' => $projectId] : [];

        $total = (int)($this->db->fetchOne("SELECT COUNT(*) AS c FROM `$t`$where", $q)['c'] ?? 0);
        $logs = $this->db->fetchAll(
            "SELECT id, project_id, uri, referer, user_agent, ip_address, hits, last_accessed, created_at, is_sample
             FROM `$t`$where ORDER BY `$orderBy` $order, id DESC LIMIT :limit OFFSET :offset",
            $q + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage]
        );

        Response::success('404 logs retrieved', [
            'logs' => $logs,
            'pagination' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => max(1, (int)ceil($total / $perPage))],
        ]);
    }

    public function log404(array $params): never
    {
        $in = Request::json();
        $uri = Validator::str($in, 'uri', 1000, true);
        if ($uri[0] !== '/') { Validator::fail('uri', 'must start with "/"'); }
        $ip = Validator::str($in, 'ip_address', 45);
        if ($ip !== null && !filter_var($ip, FILTER_VALIDATE_IP)) { Validator::fail('ip_address', 'must be an IP address'); }
        $projectId = Validator::projectId($in);
        $t = $this->db->t('404_monitor');

        $this->db->query(
            "INSERT INTO `$t` (project_id, uri, referer, user_agent, ip_address, hits, last_accessed)
             VALUES (:p, :uri, :ref, :ua, :ip, 1, NOW())
             ON DUPLICATE KEY UPDATE hits = hits + 1, last_accessed = NOW()",
            ['p' => $projectId, 'uri' => $uri, 'ref' => Validator::str($in, 'referer', 1000), 'ua' => Validator::str($in, 'user_agent', 500), 'ip' => $ip]
        );
        $this->purgeExpired();
        $row = $this->db->fetchOne("SELECT id FROM `$t` WHERE project_id = :p AND uri_hash = SHA2(:uri, 256)", ['p' => $projectId, 'uri' => $uri]);
        Response::success('404 logged', ['id' => (int)($row['id'] ?? 0)]);
    }

    public function deleteLog(array $params): never
    {
        $id = Validator::id($params['id'] ?? null);
        $n = $this->db->delete('404_monitor', ['id' => $id]);
        if ($n) { Audit::log('404_entry_deleted', null, '', "id $id"); }
        $n ? Response::success('404 log deleted') : Response::notFound('Log entry not found');
    }

    public function clearLogs(array $params): never
    {
        $in = Request::json();
        if (($in['confirm'] ?? false) !== true) {
            Response::error('Confirmation required: send {"confirm": true}', 400);
        }
        $projectId = Validator::projectId($in);
        $t = $this->db->t('404_monitor');
        // DELETE (not TRUNCATE): works with a least-privilege DB account and honours project_id.
        $n = $projectId > 0
            ? $this->db->query("DELETE FROM `$t` WHERE project_id = :p", ['p' => $projectId])->rowCount()
            : $this->db->query("DELETE FROM `$t`")->rowCount();
        Audit::log('404_log_cleared', null, '', "$n rows" . ($projectId > 0 ? ", project $projectId" : ''));
        Response::success('404 logs cleared', ['deleted' => $n]);
    }

    public function exportCSV(array $params): never
    {
        $projectId = Request::queryInt('project_id', 0, 0, PHP_INT_MAX);
        $t = $this->db->t('404_monitor');
        $rows = $this->db->fetchAll(
            "SELECT id, uri, referer, hits, last_accessed, created_at FROM `$t`" . ($projectId > 0 ? ' WHERE project_id = :p' : '') . ' ORDER BY last_accessed DESC LIMIT 100000',
            $projectId > 0 ? ['p' => $projectId] : []
        );
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['ID', 'URI', 'Referer', 'Hits', 'Last Accessed', 'Created At'], ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($out, [$r['id'], Validator::csvCell($r['uri']), Validator::csvCell($r['referer']), $r['hits'], $r['last_accessed'], $r['created_at']], ',', '"', '');
        }
        rewind($out);
        Response::raw((string)stream_get_contents($out), 'text/csv; charset=utf-8', [
            'Content-Disposition' => 'attachment; filename="404-logs-' . gmdate('Y-m-d') . '.csv"',
        ]);
    }

    /** Retention (PRIV-003): drop entries older than retention_days_404 (0 = keep). Also callable from bin/purge-404.php. */
    public function purgeExpired(): int
    {
        $days = (int)Config::get('retention_days_404', 90);
        if ($days <= 0) { return 0; }
        return $this->db->query('DELETE FROM `' . $this->db->t('404_monitor') . '` WHERE last_accessed < (NOW() - INTERVAL :d DAY)', ['d' => $days])->rowCount();
    }
}
