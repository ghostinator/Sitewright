<?php
declare(strict_types=1);

namespace RankMathWebapp\Core;

/** Fixed-window counter stored in {prefix}rate_limits (shared between PHP workers). */
final class RateLimiter
{
    /** Count one hit. @return array{allowed:bool,remaining:int,retry_after:int} */
    public static function hit(string $key, int $max, int $windowSeconds): array
    {
        $db = Database::getInstance();
        $now = time();
        $bucket = hash('sha256', $key);
        $t = $db->t('rate_limits');

        $db->query(
            "INSERT INTO `$t` (bucket, window_start, hits) VALUES (:b, :now, 1)
             ON DUPLICATE KEY UPDATE
               hits = IF(window_start <= :cut, 1, hits + 1),
               window_start = IF(window_start <= :cut2, :now2, window_start)",
            ['b' => $bucket, 'now' => $now, 'cut' => $now - $windowSeconds, 'cut2' => $now - $windowSeconds, 'now2' => $now]
        );
        $row = $db->fetchOne("SELECT hits, window_start FROM `$t` WHERE bucket = :b", ['b' => $bucket]) ?? ['hits' => 1, 'window_start' => $now];

        if (random_int(1, 200) === 1) { // opportunistic cleanup
            $db->query("DELETE FROM `$t` WHERE window_start < :old", ['old' => $now - 86400]);
        }
        $hits = (int)$row['hits'];
        return [
            'allowed'     => $hits <= $max,
            'remaining'   => max(0, $max - $hits),
            'retry_after' => max(1, (int)$row['window_start'] + $windowSeconds - $now),
        ];
    }

    public static function clear(string $key): void
    {
        $db = Database::getInstance();
        $db->query('DELETE FROM `' . $db->t('rate_limits') . '` WHERE bucket = :b', ['b' => hash('sha256', $key)]);
    }

    /** Enforce a limit: throws 429 with Retry-After when exceeded. */
    public static function enforce(string $key, int $max, int $windowSeconds, string $what = 'Too many requests'): void
    {
        $r = self::hit($key, $max, $windowSeconds);
        if (!$r['allowed']) {
            Response::error($what . '. Please wait and try again.', 429, [], ['Retry-After' => (string)$r['retry_after']]);
        }
    }
}
