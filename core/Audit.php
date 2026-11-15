<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Append-only record of security-relevant events (who, what, from where). Never stores passwords, API keys, prompt text or
 * request bodies. A failing audit write is reported to the PHP error log and never breaks the user's request.
 */
final class Audit
{
    public static function log(string $action, ?int $userId = null, string $username = '', string $detail = ''): void
    {
        try {
            $clean = static fn (string $s, int $max): string => mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? ''), 0, $max);
            if ($userId === null && $username === '' && PHP_SAPI !== 'cli') {  // CLI has no session; callers pass the actor
                $u = Auth::user();
                if ($u) { $userId = $u['id']; $username = $u['username']; }
            }
            Database::getInstance()->insert('audit_log', [
                'user_id' => $userId, 'username' => $clean($username, 60), 'action' => $clean($action, 40),
                'ip' => PHP_SAPI === 'cli' ? 'cli' : mb_substr(Request::ip(), 0, 45), 'detail' => $clean($detail, 255),
            ]);
        } catch (\Throwable $e) {
            error_log('audit write failed: ' . get_class($e));
        }
    }

    /** Remove rows older than the retention window. @return int rows removed */
    public static function purge(?int $days = null): int
    {
        $days ??= (int)Config::get('retention_days_audit', 365);
        $db = Database::getInstance();
        return $db->query('DELETE FROM `' . $db->t('audit_log') . '` WHERE created_at < (NOW() - INTERVAL :d DAY)', ['d' => max(1, $days)])->rowCount();
    }
}
