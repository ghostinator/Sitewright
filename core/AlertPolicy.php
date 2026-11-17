<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * One decision for every alert, whichever code path raised it: send now, batch, add to the daily digest, or hold back, always with the reason
 * (the reason is what Alert history shows). Order: event enabled > mute window > channels for the level > cooldown > quiet hours > mode > hourly cap.
 * Down/recovered style events are exempt from the cooldown because they only fire on a state change.
 * Deciding consumes the counters (cooldown, hourly cap), so call it once per alert.
 */
final class AlertPolicy
{
    private const COOLDOWN_EXEMPT = ['health_down', 'health_recovered', 'test', 'alert_batch', 'alert_digest'];
    private const LEVELS = ['error', 'warning', 'info'];

    /** @return array{action:string,channels:string[],reason:string} action is send | batch | digest | suppress */
    public static function decide(string $event, string $level, int $projectId = 0, string $dedupe = '', ?int $now = null): array
    {
        $now ??= time();
        $a = (array)Config::get('alerts');
        $level = in_array($level, self::LEVELS, true) ? $level : 'warning';
        $mode = in_array($event, (array)($a['events'] ?? []), true) ? 'now'
            : (in_array($event, (array)($a['events_batch'] ?? []), true) ? 'batch' : (in_array($event, (array)($a['events_digest'] ?? []), true) ? 'digest' : null));
        if ($mode === null) { return self::out('suppress', [], 'event not enabled in Settings'); }
        if (($why = self::muted($projectId, $now)) !== null) { return self::out('suppress', [], 'muted: ' . $why); }
        $channels = array_values(array_intersect(Alerts::channels(), (array)($a['channels_' . $level] ?? ['email', 'teams', 'telegram'])));
        if ($channels === []) { return self::out('suppress', [], "no channel is set up for $level alerts"); }
        if (!in_array($event, self::COOLDOWN_EXEMPT, true) && ($cd = self::cooldownFor($event)) > 0) {
            if (!RateLimiter::hit('alert:' . $event . ':' . $dedupe, 1, $cd)['allowed']) { return self::out('suppress', [], 'cooldown: the same alert was sent less than ' . self::human($cd) . ' ago'); }
        }
        if (self::inQuietHours($now) && !($level === 'error' && (bool)($a['quiet_breakthrough'] ?? true))) { return self::out('batch', $channels, 'held for quiet hours'); }
        if ($mode !== 'now') { return self::out($mode, $channels, $mode === 'batch' ? 'batched' : 'daily digest'); }
        $max = (int)($a['max_per_hour'] ?? 0);
        if ($max > 0) {
            $ok = [];
            foreach ($channels as $ch) { if (RateLimiter::hit('alertcap:' . $ch, $max, 3600)['allowed']) { $ok[] = $ch; } }
            if ($ok === []) { return self::out('batch', $channels, "hourly limit of $max reached: held for the next batch"); }
            $channels = $ok;
        }
        return self::out('send', $channels, '');
    }

    public static function inQuietHours(int $now): bool
    {
        $a = (array)Config::get('alerts');
        $s = self::minutes((string)($a['quiet_start'] ?? '')); $e = self::minutes((string)($a['quiet_end'] ?? ''));
        if ($s === null || $e === null || $s === $e) { return false; }
        $t = self::localMinutes($now);
        return $s < $e ? ($t >= $s && $t < $e) : ($t >= $s || $t < $e);
    }

    /** The configured display time zone, UTC when it is missing or invalid. */
    public static function timezone(): \DateTimeZone
    {
        try { return new \DateTimeZone((string)Config::get('display.timezone') ?: 'UTC'); } catch (\Throwable) { return new \DateTimeZone('UTC'); }
    }

    public static function localMinutes(int $now): int
    {
        $d = (new \DateTimeImmutable('@' . $now))->setTimezone(self::timezone());
        return (int)$d->format('G') * 60 + (int)$d->format('i');
    }

    public static function human(int $seconds): string
    {
        return $seconds < 120 ? "$seconds s" : ($seconds < 86400 ? intdiv($seconds, 60) . ' min' : round($seconds / 3600, 1) . ' h');
    }

    private static function cooldownFor(string $event): int
    {
        foreach ((array)Config::get('alerts.cooldowns') as $line) {
            if (is_string($line) && preg_match('/^([a-z_]+)=(\d+)$/D', $line, $m) && $m[1] === $event) { return (int)$m[2]; }
        }
        return (int)Config::get('alerts.cooldown');
    }

    private static function muted(int $projectId, int $now): ?string
    {
        $db = Database::getInstance();
        $r = $db->fetchOne('SELECT `until`, reason FROM `' . $db->t('alert_mutes') . '` WHERE `until` > :n AND (project_id = 0' . ($projectId > 0 ? ' OR project_id = :p' : '') . ') ORDER BY `until` DESC LIMIT 1',
            ['n' => gmdate('Y-m-d H:i:s', $now)] + ($projectId > 0 ? ['p' => $projectId] : []));
        return $r ? 'paused until ' . $r['until'] . ' UTC' . ($r['reason'] !== '' ? ' (' . $r['reason'] . ')' : '') : null;
    }

    private static function minutes(string $hhmm): ?int
    {
        return preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/D', $hhmm, $m) ? (int)$m[1] * 60 + (int)$m[2] : null;
    }

    /** @param string[] $channels @return array{action:string,channels:string[],reason:string} */
    private static function out(string $action, array $channels, string $reason): array
    {
        return ['action' => $action, 'channels' => $channels, 'reason' => $reason];
    }
}
