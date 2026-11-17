<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Proposes live pages for addresses that return 404. Pure matching plus an inventory of live pages (the site's newest finished audit,
 * then its sitemap list). Scores: 100 same address apart from case, slash, extension or query; 85 same page name in another folder;
 * up to 90 similar page names (shared words, ignoring filler words and years); 75 a title that contains the words of the address;
 * 40 the closest section page as a last resort. Nothing below 55 is offered except that fallback, and never the home page.
 */
final class RedirectSuggester
{
    private const STOP = ['the', 'a', 'an', 'and', 'of', 'to', 'for', 'in', 'on', 'with', 'page', 'html', 'htm', 'php'];
    private const THRESHOLD = 55;

    /** @param array<int,array{path:string,title:?string}> $candidates @return array<int,array{target:string,score:int,reason:string}> */
    public static function suggest(string $path, array $candidates, int $limit = 3): array
    {
        return self::suggestIn(self::index($candidates), $path, $limit);
    }

    /** @param array<int,array{path:string,title:?string}> $candidates */
    public static function index(array $candidates): array
    {
        $idx = ['norm' => [], 'slug' => [], 'token' => [], 'title' => []];
        foreach ($candidates as $c) {
            $p = (string)($c['path'] ?? '');
            if ($p === '' || $p[0] !== '/' || $p === '/') { continue; }
            $n = self::norm($p);
            if ($n === '/' || isset($idx['norm'][$n])) { continue; }
            $c = ['path' => $p, 'norm' => $n, 'slug' => self::slug($n), 'tokens' => self::tokens(self::slug($n)), 'ttokens' => self::tokens((string)($c['title'] ?? ''))];
            $idx['norm'][$n] = $c; $idx['slug'][$c['slug']][] = $c;
            foreach ($c['tokens'] as $t) { $idx['token'][$t][$n] = $c; }
            foreach ($c['ttokens'] as $t) { $idx['title'][$t][$n] = $c; }
        }
        return $idx;
    }

    /** @return array<int,array{target:string,score:int,reason:string}> */
    public static function suggestIn(array $idx, string $path, int $limit = 3): array
    {
        $np = self::norm($path);
        if ($np === '/') { return []; }
        $slug = self::slug($np); $tp = self::tokens($slug);
        $found = [];   // norm => [score, reason, path]
        $offer = static function (array $c, int $score, string $reason) use (&$found, $path): void {
            if ($c['path'] === $path) { return; }   // the 404 address itself
            if (!isset($found[$c['norm']]) || $found[$c['norm']][0] < $score) { $found[$c['norm']] = [$score, $reason, $c['path']]; }
        };
        if (isset($idx['norm'][$np])) { $offer($idx['norm'][$np], 100, 'Same address apart from capital letters, a trailing slash, a file extension or a query string'); }
        foreach ($idx['slug'][$slug] ?? [] as $c) { if ($c['norm'] !== $np) { $offer($c, 85, 'Same page name in a different folder'); } }
        $pool = [];
        foreach ($tp as $t) { foreach ($idx['token'][$t] ?? [] as $n => $c) { $pool[$n] = $c; } }
        foreach ($pool as $n => $c) {
            if ($n === $np || $c['slug'] === $slug) { continue; }
            $inter = count(array_intersect($tp, $c['tokens'])); $union = count(array_unique(array_merge($tp, $c['tokens'])));
            if ($inter === 0 || $union === 0) { continue; }
            similar_text(implode(' ', $tp), implode(' ', $c['tokens']), $sim);
            $score = min(90, (int)round(0.65 * ($inter / $union) * 100 + 0.35 * $sim));
            if ($score >= self::THRESHOLD) { $offer($c, $score, 'Similar page name'); }
        }
        if ($tp) {
            $hits = [];
            foreach ($tp as $t) { foreach ($idx['title'][$t] ?? [] as $n => $c) { $hits[$n] = $c; } }
            foreach ($hits as $n => $c) {
                $jt = count(array_intersect($tp, $c['ttokens'])) / count($tp);
                if ($jt >= 0.75 && $n !== $np) { $offer($c, (int)round(75 * $jt), 'The page title contains the words of the old address'); }
            }
        }
        if ($found === []) {
            $segs = explode('/', trim($np, '/'));
            for ($n = count($segs) - 1; $n >= 1; $n--) {
                $parent = '/' . implode('/', array_slice($segs, 0, $n));
                if (isset($idx['norm'][$parent])) { $offer($idx['norm'][$parent], 40, 'Closest section page (low confidence)'); break; }
            }
        }
        uasort($found, static fn (array $x, array $y): int => [$y[0], strlen($x[2]), $x[2]] <=> [$x[0], strlen($y[2]), $y[2]]);
        $out = [];
        foreach (array_slice($found, 0, max(1, $limit)) as $f) { $out[] = ['target' => $f[2], 'score' => $f[0], 'reason' => $f[1]]; }
        return $out;
    }

    /** Live pages of a site: the newest finished audit (HTTP 200, indexable), then its sitemap list. @return array{source:string,candidates:array} */
    public static function inventory(int $projectId): array
    {
        $db = Database::getInstance();
        $out = []; $source = 'none';
        $audit = $db->fetchOne('SELECT id FROM `' . $db->t('audits') . "` WHERE project_id = :p AND status = 'done' ORDER BY id DESC LIMIT 1", ['p' => $projectId]);
        if ($audit) {
            $source = 'audit';
            foreach ($db->fetchAll('SELECT url, title FROM `' . $db->t('audit_pages') . '` WHERE audit_id = :a AND noindex = 0 LIMIT 5000', ['a' => (int)$audit['id']]) as $r) { $out[] = ['path' => self::pathOf((string)$r['url']), 'title' => (string)$r['title']]; }
        }
        $seen = array_column($out, 'path');
        foreach ($db->fetchAll('SELECT url FROM `' . $db->t('sitemaps') . '` WHERE project_id = :p ORDER BY id LIMIT 5000', ['p' => $projectId]) as $r) {
            $p = self::pathOf((string)$r['url']);
            if (!in_array($p, $seen, true)) { $out[] = ['path' => $p, 'title' => null]; if ($source === 'none') { $source = 'sitemap'; } }
        }
        return ['source' => $source, 'candidates' => $out];
    }

    public static function pathOf(string $url): string
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $q = parse_url($url, PHP_URL_QUERY);
        return ($path === '' ? '/' : $path) . ($q ? '?' . $q : '');
    }

    public static function norm(string $p): string
    {
        $p = strtolower(rawurldecode((string)(strtok($p, '?#') ?: '')));
        $p = preg_replace('/\.(html?|php|aspx?|jsp)$/', '', $p) ?? $p;
        $p = rtrim($p, '/');
        return $p === '' ? '/' : $p;
    }

    private static function slug(string $norm): string
    {
        $i = strrpos($norm, '/');
        return $i === false ? $norm : substr($norm, $i + 1);
    }

    /** @return string[] distinct lower-case words, without filler words, single characters and four-digit years */
    private static function tokens(string $s): array
    {
        $out = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s)) ?: [] as $w) {
            if ($w === '' || mb_strlen($w) < 2 || in_array($w, self::STOP, true) || preg_match('/^(19|20)\d\d$/D', $w)) { continue; }
            $out[$w] = true;
        }
        return array_keys($out);
    }
}
