<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Remembers whether the app was last declared healthy, and how many unhealthy checks in a row there have been, so that the health check
 * reports ONE "down" after health.bad_checks bad minutes and ONE "recovered". The state lives in the database (the kv table), not in a file:
 * a file the service user cannot write made every unhealthy minute look like a new outage. If the database itself is unreachable it
 * falls back to a file in the temp directory.
 */
final class Health
{
    /** @return array{transition:?string,streak:int} transition is "down", "recovered" or null */
    public static function record(bool $ok): array
    {
        try {
            $state = self::get('health_state') ?? 'ok'; $streak = (int)(self::get('health_bad_streak') ?? 0);
            $r = self::step($state, $streak, $ok);
            self::set('health_state', $r['state']); self::set('health_bad_streak', (string)$r['streak']);
        } catch (\Throwable) {
            $f = sys_get_temp_dir() . '/sitewright-health-' . substr(hash('sha256', Config::root()), 0, 12) . '.state';
            [$state, $streak] = is_file($f) ? array_pad(explode(' ', trim((string)file_get_contents($f))), 2, '0') : ['ok', '0'];
            $r = self::step($state, (int)$streak, $ok);
            @file_put_contents($f, $r['state'] . ' ' . $r['streak']);
        }
        return ['transition' => $r['transition'], 'streak' => $r['streak']];
    }

    /** @return array{state:string,streak:int,transition:?string} */
    private static function step(string $state, int $streak, bool $ok): array
    {
        if ($ok) { return ['state' => 'ok', 'streak' => 0, 'transition' => $state === 'bad' ? 'recovered' : null]; }
        $streak++;
        if ($state !== 'bad' && $streak >= max(1, (int)Config::get('health.bad_checks'))) { return ['state' => 'bad', 'streak' => $streak, 'transition' => 'down']; }
        return ['state' => $state, 'streak' => $streak, 'transition' => null];
    }

    private static function get(string $k): ?string
    {
        $db = Database::getInstance();
        $r = $db->fetchOne('SELECT v FROM `' . $db->t('kv') . '` WHERE k = :k', ['k' => $k]);
        return $r ? (string)$r['v'] : null;
    }

    private static function set(string $k, string $v): void
    {
        $db = Database::getInstance();
        $db->query('INSERT INTO `' . $db->t('kv') . '` (k, v) VALUES (:k, :v) ON DUPLICATE KEY UPDATE v = VALUES(v)', ['k' => $k, 'v' => $v]);
    }
}
