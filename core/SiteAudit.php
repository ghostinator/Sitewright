<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * A site audit: one polite crawl that analyses every page and lists concrete problems, compared with the previous audit of the same site.
 * Only extracted facts are stored (no page bodies). Like watch checks, an audit never fails its JOB: an unreachable site ends the audit as
 * "failed" with a reason, so it cannot turn the app's health check red.
 */
final class SiteAudit
{
    /** code => [severity, title, advice] */
    public const CATALOG = [
        'broken_link'           => ['high',   'Broken internal link', 'Fix or remove the link, or redirect the missing page to a relevant one.'],
        'no_https'              => ['high',   'Page is not served over HTTPS', 'Serve the page over HTTPS and redirect the http address to it.'],
        'missing_title'         => ['high',   'No page title', 'Add a unique, descriptive <title> to the page.'],
        'broken_external_link'  => ['medium', 'Broken outside link', 'Update or remove the link to a page that no longer exists (404 or 410).'],
        'missing_description'   => ['medium', 'No meta description', 'Add a meta description that summarises the page.'],
        'missing_h1'            => ['medium', 'No H1 heading', 'Give the page one H1 heading that says what it is about.'],
        'noindex'               => ['medium', 'Page is excluded from search results', 'Intended? Then ignore this. If not, remove the noindex robots tag or header.'],
        'thin_content'          => ['medium', 'Very little content', 'Add useful content, or merge the page into a stronger one.'],
        'slow_page'             => ['medium', 'Slow to load', 'The server took a long time to deliver the HTML: check hosting, caching and heavy plugins.'],
        'missing_viewport'      => ['medium', 'No mobile viewport tag', 'Add <meta name="viewport" content="width=device-width, initial-scale=1> so phones render the page properly.'],
        'duplicate_title'       => ['medium', 'Same title as another page', 'Give each page its own title so search engines can tell them apart.'],
        'duplicate_description' => ['medium', 'Same description as another page', 'Write a different meta description for each page.'],
        'orphan_page'           => ['medium', 'No page links to it', 'Link to the page from a related page or your menu, or remove it from the sitemap.'],
        'title_length'          => ['low',    'Title length is off', 'Aim for the title length set under Settings, so it is not cut off in results.'],
        'description_length'    => ['low',    'Description length is off', 'Aim for the description length set under Settings.'],
        'multiple_h1'           => ['low',    'More than one H1', 'Use a single H1 and H2/H3 for the sections below it.'],
        'images_missing_alt'    => ['low',    'Images without alt text', 'Describe each meaningful image in its alt attribute.'],
        'missing_canonical'     => ['low',    'No canonical address', 'Add a canonical link so duplicate addresses do not compete.'],
        'redirected_link'       => ['low',    'Link goes through a redirect', 'Link straight to the final address.'],
        'nap_phone_differs'     => ['medium', 'Phone number differs from yours', 'Use the same number everywhere, or remove the old one. If it is a second line on purpose, ignore this.'],
        'nap_address_differs'   => ['medium', 'Address differs from yours', 'Use the same street and postal code everywhere, including the structured data some plugins add.'],
        'nap_phone_varies'      => ['low',    'Several phone numbers across the site', 'Pick the main number in the setup guide; Sitewright then shows which pages use another one.'],
    ];
    private const SEVERITY_ORDER = ['high' => 0, 'medium' => 1, 'low' => 2];
    private const MAX_ISSUES = 5000;

    /** @return array{audit_id:int,job_id:int} @throws AuditBusyException when this site already has an audit queued or running */
    public static function start(int $projectId, string $baseUrl, ?int $userId): array
    {
        $db = Database::getInstance();
        $url = Url::normalize($baseUrl) ?? throw new \InvalidArgumentException('Invalid address');
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        foreach ($db->fetchAll('SELECT a.base_url FROM `' . $db->t('audits') . '` a JOIN `' . $db->t('jobs') . "` j ON j.id = a.job_id WHERE a.status IN ('queued','running') AND j.status IN ('queued','running')") as $open) {
            if (strtolower((string)parse_url((string)$open['base_url'], PHP_URL_HOST)) === $host) { throw new AuditBusyException('An audit of this site is already running.'); }
        }
        $id = (int)$db->insert('audits', ['project_id' => $projectId, 'base_url' => $url, 'created_by' => $userId]);
        $job = Jobs::enqueue('site_audit', ['audit_id' => $id], $userId, 0, 1);
        $db->update('audits', ['job_id' => $job], ['id' => $id]);
        return ['audit_id' => $id, 'job_id' => $job];
    }

    /** Called by the worker every minute: at most one new automatic audit per call. @return int audits queued */
    public static function scheduleDue(): int
    {
        $days = (int)Config::get('audit.repeat_days');
        if ($days <= 0) { return 0; }
        $db = Database::getInstance();
        $a = $db->t('audits'); $j = $db->t('jobs');
        foreach ($db->fetchAll('SELECT id, base_url FROM `' . $db->t('sites') . '` ORDER BY id') as $s) {
            $last = $db->fetchOne("SELECT MAX(created_at) at FROM `$a` WHERE project_id = :p", ['p' => (int)$s['id']]);
            if ($last && $last['at'] !== null && strtotime((string)$last['at']) > time() - $days * 86400) { continue; }
            try { self::start((int)$s['id'], (string)$s['base_url'], null); return 1; } catch (AuditBusyException | \InvalidArgumentException) { continue; }
        }
        return 0;
    }

    /** Job handler: payload {audit_id}. */
    public static function handleJob(array $p): array
    {
        $db = Database::getInstance();
        $a = $db->fetchOne('SELECT * FROM `' . $db->t('audits') . '` WHERE id = :i', ['i' => (int)($p['audit_id'] ?? 0)]);
        if (!$a || $a['status'] !== 'queued') { return ['message' => 'Skipped', 'data' => ['skipped' => true]]; }
        $id = (int)$a['id'];
        $db->update('audits', ['status' => 'running', 'started_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
        try {
            $r = self::crawl($a);
        } catch (\Throwable $e) {
            Logger::error('audit_crashed', ['audit' => $id, 'exception' => get_class($e), 'error' => $e->getMessage()]);
            $r = ['error' => 'The audit stopped because of an internal error.'];
        }
        if (isset($r['error'])) {
            $db->update('audits', ['status' => 'failed', 'error' => mb_substr($r['error'], 0, 255), 'finished_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
            return ['message' => 'Audit failed', 'data' => ['audit_id' => $id, 'error' => $r['error']]];
        }
        return ['message' => 'Audit finished', 'data' => ['audit_id' => $id, 'pages' => $r['pages'], 'issues' => $r['issues']]];
    }

    /** @return array{pages:int,issues:int}|array{error:string} */
    private static function crawl(array $a): array
    {
        $db = Database::getInstance();
        $id = (int)$a['id']; $base = (string)$a['base_url']; $project = (int)$a['project_id'];
        $max = max(1, min((int)Config::get('audit.max_pages'), (int)Config::get('limits.max_pages')));
        $budget = max(60, (int)Config::get('audit.max_seconds')); $deadline = microtime(true) + $budget;
        set_time_limit($budget + 120);
        $thin = (int)Config::get('audit.thin_words'); $slow = (float)Config::get('seo.load_ok');

        $pages = []; $issues = []; $nap = []; $fetches = []; $linksFrom = []; $external = []; $lastTouch = 0;
        $seeds = array_column($db->fetchAll('SELECT url FROM `' . $db->t('sitemaps') . '` WHERE project_id = :p ORDER BY id LIMIT 500', ['p' => $project]), 'url');
        $out = Crawler::run($base, $max, $deadline, [
            'seeds' => $seeds,
            'on_tick' => static function () use (&$lastTouch): void { if (time() - $lastTouch >= 5) { $lastTouch = time(); Jobs::touch(); } },
            'on_fetch' => static function (string $url, ?array $res, ?string $err) use (&$fetches): void {
                $fetches[$url] = ['status' => $res['status'] ?? 0, 'final' => $res['final_url'] ?? null, 'redirected' => !empty($res['redirects'])];
            },
            'on_links' => static function (string $url, array $internal, array $ext) use (&$linksFrom, &$external): void {
                $linksFrom[$url] = $internal;
                foreach ($ext as $e) { if (count($external) < 2000 || isset($external[$e])) { $external[$e] ??= $url; } }
            },
            'on_page' => static function (string $url, array $res, \DOMDocument $dom) use (&$pages, &$issues, &$nap, $thin, $slow): void {
                $r = SeoAnalyzer::analyseHtml($res);
                $score = SeoAnalyzer::calculateScore($r);
                $title = $r['basic']['title']; $desc = $r['meta']['description'];
                $pages[$url] = ['title' => $title, 'title_len' => $r['basic']['title_length'], 'desc' => $desc, 'desc_len' => $r['meta']['description_length'], 'h1' => $r['headings']['h1_count'],
                    'words' => $r['basic']['word_count'], 'noindex' => $r['basic']['noindex'], 'score' => $score, 'load_ms' => (int)round($r['performance']['load_time'] * 1000)];
                $add = static function (string $code, string $detail = '') use (&$issues, $url): void { $issues[] = [$code, $url, $detail]; };
                if (!$r['security']['uses_https']) { $add('no_https'); }
                if ($title === '') { $add('missing_title'); } elseif (!$r['basic']['title_optimal']) { $add('title_length', $r['basic']['title_length'] . ' characters'); }
                if ($desc === '') { $add('missing_description'); } elseif (!$r['meta']['description_optimal']) { $add('description_length', $r['meta']['description_length'] . ' characters'); }
                if ($r['headings']['h1_count'] === 0) { $add('missing_h1'); } elseif ($r['headings']['h1_count'] > 1) { $add('multiple_h1', $r['headings']['h1_count'] . ' H1 headings'); }
                if ($r['basic']['noindex']) { $add('noindex'); }
                if ($r['basic']['word_count'] < $thin) { $add('thin_content', $r['basic']['word_count'] . ' words'); }
                if ($r['images']['images_without_alt'] > 0) { $add('images_missing_alt', $r['images']['images_without_alt'] . ' of ' . $r['images']['total_images'] . ' images'); }
                if ($r['performance']['load_time'] > $slow) { $add('slow_page', $r['performance']['load_time'] . ' s'); }
                if (!$r['viewport']['has_viewport']) { $add('missing_viewport'); }
                if ($r['basic']['canonical'] === '') { $add('missing_canonical'); }
                $n = Nap::extract($dom); if ($n['phones'] || $n['addresses']) { $nap[$url] = $n; }
            },
        ]);
        if ($pages === []) { return ['error' => $out['error'] ?? 'No page of the site could be read.']; }

        // ---- cross-page checks
        foreach (['title' => 'duplicate_title', 'desc' => 'duplicate_description'] as $field => $code) {
            $groups = [];
            foreach ($pages as $url => $f) { if ($f[$field] !== '') { $groups[mb_strtolower($f[$field])][] = $url; } }
            foreach ($groups as $urls) { if (count($urls) > 1) { foreach ($urls as $u) { $issues[] = [$code, $u, 'Shared with ' . (count($urls) - 1) . ' other page(s)']; } } }
        }
        foreach (Nap::issues($nap, $project > 0 ? SiteProfile::values($project) : []) as $i) { $issues[] = $i; }
        // a page that did not load while the crawl was busy is looked at once more before it is called broken (a slow answer is not a broken link)
        $retry = array_keys(array_filter($fetches, static fn (array $f): bool => $f['status'] === 0 || $f['status'] === 429 || $f['status'] >= 500));
        if ($retry !== []) {
            $client = SafeHttpClient::default(); $delay = (int)Config::get('limits.crawl_delay_ms') * 1000; $retryDeadline = microtime(true) + min(90, $budget);
            foreach (array_slice($retry, 0, 40) as $u) {
                if (microtime(true) > $retryDeadline) { break; }
                usleep(max($delay, 500000)); Jobs::touch();
                try { $res = $client->get($u, ['max_bytes' => (int)Config::get('fetch.max_html_bytes'), 'timeout' => 25, 'deadline' => $retryDeadline]); } catch (FetchException) { continue; }
                $fetches[$u] = ['status' => $res['status'], 'final' => $res['final_url'], 'redirected' => !empty($res['redirects'])];
            }
        }
        $finalOf = [];
        foreach ($fetches as $req => $f) { if ($f['final'] !== null) { $finalOf[$req] = Url::normalize($f['final']) ?? $f['final']; } }
        $inbound = []; $groups = ['broken_link' => [], 'redirected_link' => []];
        foreach ($linksFrom as $src => $targets) {
            foreach ($targets as $t) {
                $f = $fetches[$t] ?? null;
                if ($f !== null && ($f['status'] >= 400 || $f['status'] === 0)) { $issues[] = ['broken_link', $src, 'Links to ' . $t . ' (' . ($f['status'] ? 'HTTP ' . $f['status'] : 'not reachable') . ')']; $groups['broken_link'][$t][$src] = $f['status']; }
                elseif ($f !== null && $f['redirected'] && isset($finalOf[$t]) && $finalOf[$t] !== $t) { $issues[] = ['redirected_link', $src, 'Links to ' . $t . ', which redirects to ' . $finalOf[$t]]; $groups['redirected_link'][$t][$src] = 1; }
                $dest = $finalOf[$t] ?? $t;
                if ($dest !== $src) { $inbound[$dest][$src] = true; }
            }
        }
        $startFinal = isset($fetches[Url::normalize($base) ?? '']['final']) ? (Url::normalize((string)$fetches[Url::normalize($base)]['final']) ?? '') : '';
        if ($seeds !== []) {
            foreach ($pages as $url => $f) { if (empty($inbound[$url]) && $url !== $startFinal && $url !== Url::normalize($base)) { $issues[] = ['orphan_page', $url, 'Listed in your sitemap, but no page links to it']; } }
        }
        $cap = (int)Config::get('audit.external_links_max');
        if ($cap > 0 && $external !== []) {
            $client = SafeHttpClient::default(); $delay = (int)Config::get('limits.crawl_delay_ms') * 1000; $extDeadline = microtime(true) + min(180, $budget);
            foreach (array_slice(array_keys($external), 0, $cap) as $ext) {
                if (microtime(true) > $extDeadline) { break; }
                usleep($delay); Jobs::touch();
                try { $res = $client->request('HEAD', $ext, ['timeout' => 8, 'deadline' => $extDeadline]); } catch (FetchException) { continue; }
                if (in_array($res['status'], [404, 410], true)) { $issues[] = ['broken_external_link', $external[$ext], 'Links to ' . $ext . ' (HTTP ' . $res['status'] . ')']; }
            }
        }

        // ---- store
        $truncated = count($issues) > self::MAX_ISSUES; $issues = array_slice($issues, 0, self::MAX_ISSUES);
        $byCode = []; $bySev = ['high' => 0, 'medium' => 0, 'low' => 0]; $keys = [];
        foreach ($issues as [$code, $url, $detail]) { $sev = self::CATALOG[$code][0]; $byCode[$code] = ($byCode[$code] ?? 0) + 1; $bySev[$sev]++; $keys[$code . '|' . $url . '|' . $detail] = $sev; }
        $db->transaction(function (Database $db) use ($id, $pages, $inbound, $issues): void {
            foreach (array_chunk(array_keys($pages), 200) as $chunk) {
                $ph = []; $bind = [];
                foreach ($chunk as $n => $url) {
                    $f = $pages[$url];
                    $ph[] = "(:a$n, :u$n, :t$n, :tl$n, :dl$n, :h$n, :w$n, :ni$n, :s$n, :l$n, :in$n)";
                    $bind += ["a$n" => $id, "u$n" => mb_substr($url, 0, 500), "t$n" => mb_substr($f['title'], 0, 255), "tl$n" => $f['title_len'], "dl$n" => $f['desc_len'], "h$n" => $f['h1'], "w$n" => $f['words'], "ni$n" => (int)$f['noindex'], "s$n" => $f['score'], "l$n" => $f['load_ms'], "in$n" => count($inbound[$url] ?? [])];
                }
                $db->query('INSERT INTO `' . $db->t('audit_pages') . '` (audit_id, url, title, title_len, desc_len, h1_count, word_count, noindex, score, load_ms, inbound) VALUES ' . implode(',', $ph), $bind);
            }
            foreach (array_chunk($issues, 200) as $chunk) {
                $ph = []; $bind = [];
                foreach ($chunk as $n => [$code, $url, $detail]) { $ph[] = "(:a$n, :c$n, :v$n, :u$n, :d$n)"; $bind += ["a$n" => $id, "c$n" => $code, "v$n" => self::CATALOG[$code][0], "u$n" => mb_substr($url, 0, 500), "d$n" => mb_substr($detail, 0, 500)]; }
                $db->query('INSERT INTO `' . $db->t('audit_issues') . '` (audit_id, code, severity, url, detail) VALUES ' . implode(',', $ph), $bind);
            }
        });
        $fixOnce = self::fixOnce($groups, $pages, $finalOf);
        $summary = ['pages' => count($pages), 'actions_skipped' => (int)($out['actions_skipped'] ?? 0), 'fix_once' => $fixOnce, 'fetched' => $out['fetched'], 'stopped_early' => $out['stopped_early'], 'issues_truncated' => $truncated, 'by_severity' => $bySev, 'by_code' => $byCode,
            'avg_score' => (int)round(array_sum(array_column($pages, 'score')) / count($pages)), 'previous_audit_id' => null, 'new' => null, 'fixed' => null, 'new_high' => 0];

        // ---- compare with the previous audit of the same site and alert about NEW high-priority problems
        $prev = $db->fetchOne('SELECT id FROM `' . $db->t('audits') . "` WHERE project_id = :p AND status = 'done' AND id < :i AND base_url = :b ORDER BY id DESC LIMIT 1", ['p' => $project, 'i' => $id, 'b' => $base]);
        if ($prev) {
            $old = [];
            foreach ($db->fetchAll('SELECT code, url, detail FROM `' . $db->t('audit_issues') . '` WHERE audit_id = :a', ['a' => (int)$prev['id']]) as $r) { $old[$r['code'] . '|' . $r['url'] . '|' . $r['detail']] = true; }
            $new = array_diff_key($keys, $old);
            $summary['previous_audit_id'] = (int)$prev['id']; $summary['new'] = count($new); $summary['fixed'] = count(array_diff_key($old, $keys));
            $summary['new_high'] = count(array_filter($new, static fn (string $sev): bool => $sev === 'high'));
        }
        $db->update('audits', ['status' => 'done', 'pages' => count($pages), 'stopped_early' => (int)$out['stopped_early'], 'summary' => json_encode($summary), 'finished_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
        if ($summary['new_high'] > 0) {
            Alerts::notify('audit_new_issues', 'Audit of ' . $base . ' found ' . $summary['new_high'] . ' new high-priority issue(s) (' . $summary['fixed'] . ' fixed since the last audit). Open Site Audit in Sitewright to see them.', 'warning', 'a' . $project . ':' . $base, $project);
        }
        return ['pages' => count($pages), 'issues' => count($issues)];
    }

    /**
     * The same link found on many pages is one thing to fix (a menu item, a footer link), not hundreds. For each target linked from at least three
     * pages: how many pages, where it ends up, and for a missing page the closest live page to redirect it to.
     * @param array<string,array<string,array<string,int>>> $groups code => target => [source page => status]
     * @param array<string,array{title:string}> $pages @param array<string,string> $finalOf
     * @return list<array<string,mixed>>
     */
    private static function fixOnce(array $groups, array $pages, array $finalOf): array
    {
        $idx = null; $out = [];
        foreach ($groups as $code => $targets) {
            uasort($targets, static fn (array $a, array $b): int => count($b) <=> count($a));
            foreach (array_slice($targets, 0, 8, true) as $target => $sources) {
                if (count($sources) < 3) { break; }
                $row = ['code' => $code, 'target' => $target, 'pages' => count($sources), 'sample' => array_slice(array_keys($sources), 0, 3)];
                if ($code === 'redirected_link') { $row['destination'] = $finalOf[$target] ?? null; }
                else {
                    $status = (int)reset($sources); $row['status'] = $status;
                    if ($status === 404 || $status === 410) {
                        $idx ??= RedirectSuggester::index(array_map(static fn (string $u, array $p): array => ['path' => RedirectSuggester::pathOf($u), 'title' => $p['title']], array_keys($pages), $pages));
                        $s = RedirectSuggester::suggestIn($idx, RedirectSuggester::pathOf($target), 1);
                        if ($s) { $row['suggestion'] = $s[0]; }
                    }
                }
                $out[] = $row;
            }
        }
        usort($out, static fn (array $a, array $b): int => $b['pages'] <=> $a['pages']);
        return array_slice($out, 0, 12);
    }

    /** Keep the newest audit.keep per site; mark audits whose job vanished as failed. @return int audits deleted */
    public static function purge(): int
    {
        $db = Database::getInstance();
        $a = $db->t('audits'); $j = $db->t('jobs');
        $db->query("UPDATE `$a` a LEFT JOIN `$j` j ON j.id = a.job_id SET a.status = 'failed', a.error = 'The worker stopped before the audit finished', a.finished_at = NOW() WHERE a.status IN ('queued','running') AND a.created_at < NOW() - INTERVAL 1 HOUR AND (j.id IS NULL OR j.status IN ('failed','done'))");
        $keep = max(1, (int)Config::get('audit.keep'));
        $drop = [];
        foreach ($db->fetchAll("SELECT project_id, id FROM `$a` ORDER BY project_id, id DESC") as $r) {
            $seen[$r['project_id']] = ($seen[$r['project_id']] ?? 0) + 1;
            if ($seen[$r['project_id']] > $keep) { $drop[] = (int)$r['id']; }
        }
        if ($drop) {
            $in = implode(',', $drop);
            $db->query('DELETE FROM `' . $db->t('audit_issues') . "` WHERE audit_id IN ($in)"); $db->query('DELETE FROM `' . $db->t('audit_pages') . "` WHERE audit_id IN ($in)"); $db->query("DELETE FROM `$a` WHERE id IN ($in)");
        }
        return count($drop);
    }
}
