<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** Read-only view of the audit log (admin role). Keyset pagination: pass before_id from the previous page. */
final class AuditController
{
    public function list(array $params): never
    {
        $limit = Request::queryInt('limit', 100, 1, 500);
        $before = Request::queryInt('before_id', 0, 0, PHP_INT_MAX);
        $action = Request::query('action');
        $where = []; $bind = [];
        if ($before > 0) { $where[] = 'id < :b'; $bind['b'] = $before; }
        if (is_string($action) && preg_match('/^[a-z0-9_]{1,40}$/D', $action)) { $where[] = 'action = :a'; $bind['a'] = $action; }
        $db = Database::getInstance();
        $rows = $db->fetchAll('SELECT id, created_at, username, action, ip, detail FROM `' . $db->t('audit_log') . '`' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT :l', $bind + ['l' => $limit]);
        Response::success('Audit log', ['rows' => $rows, 'next_before_id' => count($rows) === $limit ? (int)end($rows)['id'] : null]);
    }
}
