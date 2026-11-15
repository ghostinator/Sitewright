<?php
declare(strict_types=1);

/**
 * Demo data generator (CLI only). Every row is flagged is_sample = 1 and shown as "sample data" in the UI.
 * Usage: php bin/generate-sample-data.php --yes
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/core/autoload.php';

use RankMathWebapp\Core\Database;

if (!in_array('--yes', $argv, true)) { fwrite(STDERR, "This inserts FABRICATED demo rows (flagged is_sample). Re-run with --yes to confirm.\n"); exit(1); }

$db = Database::getInstance();
if ((int)$db->fetchOne('SELECT COUNT(*) AS c FROM `' . $db->t('analytics_keywords') . '` WHERE is_sample = 1')['c'] > 0) { echo "Sample data already present.\n"; exit(0); }

$db->transaction(function (Database $db): void {
    $kw = [['seo optimization', 5000, 350, 3.5], ['digital marketing', 8000, 640, 2.8], ['content marketing', 4500, 270, 4.2], ['keyword research', 3200, 192, 5.1], ['local seo', 5500, 440, 3.2]];
    for ($i = 30; $i >= 0; $i--) {
        foreach ($kw as [$k, $imp, $clk, $pos]) {
            $v = (90 + random_int(0, 20)) / 100;
            $db->insert('analytics_keywords', ['keyword' => $k, 'impressions' => (int)round($imp * $v), 'clicks' => (int)round($clk * $v), 'position' => round($pos + random_int(-10, 10) / 10, 2),
                'ctr' => round($clk / $imp * 100, 2), 'date' => gmdate('Y-m-d', strtotime("-$i days")), 'is_sample' => 1]);
        }
    }
    foreach ([['https://example.com/', 85, 'site'], ['https://example.org/', 78, 'site'], ['https://competitor.example/', 92, 'competitor']] as [$u, $s, $t]) {
        $db->insert('seo_analysis', ['url' => $u, 'analysis_type' => $t, 'score' => $s, 'results' => json_encode(['sample' => true]), 'is_sample' => 1]);
    }
    foreach (['/old-page' => 3, '/missing-image.png' => 12, '/blog/typo-url' => 5] as $uri => $hits) {
        $db->insert('404_monitor', ['uri' => $uri, 'hits' => $hits, 'is_sample' => 1]);
    }
    foreach ([['/old-page', '/new-page', '301'], ['/promo', '/', '302']] as [$s, $t, $c]) {
        $db->insert('redirections', ['source_url' => $s, 'target_url' => $t, 'redirect_type' => $c, 'is_sample' => 1]);
    }
    $db->insert('local_locations', ['name' => 'Sample Coffee Shop', 'address' => '1 Example Street', 'city' => 'Springfield', 'country' => 'US', 'latitude' => 39.7817, 'longitude' => -89.6501, 'is_sample' => 1]);
});
echo "Sample data inserted (flagged is_sample). Delete with: DELETE FROM <prefix>... WHERE is_sample = 1\n";
