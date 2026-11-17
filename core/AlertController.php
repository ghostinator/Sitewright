<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** Alert history, pauses and presets (administrators). */
final class AlertController
{
    private const OUTCOMES = ['sent', 'suppressed', 'batched', 'digest', 'muted'];

    public function list(array $params): never
    {
        $db = Database::getInstance();
        $outcome = Validator::enum(['o' => Request::query('outcome')], 'o', self::OUTCOMES, null);
        $limit = Request::queryInt('limit', 100, 1, 500);
        $rows = $db->fetchAll('SELECT l.id, l.created_at, l.event, l.level, l.project_id, s.name AS site, l.message, l.outcome, l.channels, l.reason FROM `' . $db->t('alert_log') . '` l LEFT JOIN `' . $db->t('sites') . '` s ON s.id = l.project_id'
            . ($outcome !== null ? ' WHERE l.outcome = :o' : '') . " ORDER BY l.created_at DESC, l.id DESC LIMIT $limit", $outcome !== null ? ['o' => $outcome] : []);
        $summary = array_fill_keys(self::OUTCOMES, 0);
        foreach ($db->fetchAll('SELECT outcome, COUNT(*) n FROM `' . $db->t('alert_log') . '` WHERE created_at > NOW() - INTERVAL 24 HOUR GROUP BY outcome') as $r) { $summary[$r['outcome']] = (int)$r['n']; }
        $waiting = ['batch' => 0, 'digest' => 0];
        foreach ($db->fetchAll('SELECT mode, COUNT(*) n FROM `' . $db->t('alert_batch') . '` GROUP BY mode') as $r) { $waiting[$r['mode']] = (int)$r['n']; }
        $mutes = $db->fetchAll('SELECT m.id, m.project_id, s.name AS site, m.`until`, m.reason FROM `' . $db->t('alert_mutes') . '` m LEFT JOIN `' . $db->t('sites') . '` s ON s.id = m.project_id WHERE m.`until` > NOW() ORDER BY m.`until`');
        $a = (array)Config::get('alerts');
        Response::success('Alerts', ['rows' => $rows, 'summary' => $summary, 'waiting' => $waiting, 'mutes' => $mutes, 'channels' => Alerts::channels(), 'settings' => [
            'max_per_hour' => (int)$a['max_per_hour'], 'batch_minutes' => (int)$a['batch_minutes'], 'digest_time' => (string)$a['digest_time'], 'quiet_start' => (string)$a['quiet_start'], 'quiet_end' => (string)$a['quiet_end'],
            'timezone' => (string)Config::get('display.timezone'), 'cooldown' => (int)$a['cooldown']]]);
    }

    public function mute(array $params): never
    {
        $in = Request::json();
        $project = Validator::siteId($in);
        $hours = Validator::int($in, 'hours', 1, 720, 4) ?? 4;
        $reason = Validator::str($in, 'reason', 200) ?? '';
        $db = Database::getInstance();
        $id = (int)$db->insert('alert_mutes', ['project_id' => $project, 'until' => gmdate('Y-m-d H:i:s', time() + $hours * 3600), 'reason' => $reason, 'created_by' => PHP_SAPI === 'cli' ? null : ((int)(Auth::user()['id'] ?? 0) ?: null)]);
        Audit::log('alerts_paused', null, '', ($project > 0 ? "site $project" : 'all sites') . " for $hours h" . ($reason !== '' ? ": $reason" : ''));
        Response::success('Alerts paused', ['id' => $id], 201);
    }

    public function unmute(array $params): never
    {
        $id = Validator::id($params['id'] ?? null);
        $db = Database::getInstance();
        if ($db->delete('alert_mutes', ['id' => $id]) === 0) { Response::notFound('That pause no longer exists'); }
        Audit::log('alerts_resumed');
        Response::success('Alerts resumed');
    }

    /** Calm: real problems at once, the rest batched or in one daily digest, quiet nights. Everything: the original behaviour. */
    public function preset(array $params): never
    {
        $name = Validator::enum(['n' => Request::json()['name'] ?? null], 'n', ['calm', 'everything'], null);
        if ($name === null) { Response::error("Choose 'calm' or 'everything'.", 400); }
        $have = Alerts::channels();
        $quiet = array_values(array_diff($have, ['email'])) ?: $have;
        $changes = $name === 'calm' ? [
            'alerts.events' => ['health_down', 'health_recovered', 'watch_down', 'server_error', 'login_failures', 'mfa_disabled', 'mfa_reset', 'account_deleted'],
            'alerts.events_batch' => ['watch_regression', 'watch_recovered', 'audit_new_issues', 'site_sync_failed', 'gsc_sync_failed', 'job_failed'],
            'alerts.events_digest' => ['user_created', 'account_changed', 'setting_changed'],
            'alerts.channels_error' => $have, 'alerts.channels_warning' => $quiet, 'alerts.channels_info' => $quiet,
            'alerts.max_per_hour' => 20, 'alerts.batch_minutes' => 30, 'alerts.quiet_start' => '22:00', 'alerts.quiet_end' => '07:00', 'alerts.quiet_breakthrough' => true, 'alerts.digest_time' => '08:00',
        ] : [
            'alerts.events' => Config::defaults()['alerts']['events'], 'alerts.events_batch' => [], 'alerts.events_digest' => [],
            'alerts.channels_error' => ['email', 'teams', 'telegram'], 'alerts.channels_warning' => ['email', 'teams', 'telegram'], 'alerts.channels_info' => ['email', 'teams', 'telegram'],
            'alerts.max_per_hour' => 30, 'alerts.batch_minutes' => 15, 'alerts.quiet_start' => '', 'alerts.quiet_end' => '', 'alerts.quiet_breakthrough' => true, 'alerts.digest_time' => '08:00',
        ];
        $p = Settings::prepare($changes, true);
        if ($p['status'] !== 200) { Response::error($p['message'], $p['status'], $p['errors']); }
        $r = $p['values'] === [] ? ['changed' => []] : Settings::commit($p, PHP_SAPI === 'cli' ? 0 : (int)(Auth::user()['id'] ?? 0));
        Response::success($name === 'calm' ? 'Calm preset applied' : 'Everything preset applied', ['changed' => $r['changed']]);
    }
}
