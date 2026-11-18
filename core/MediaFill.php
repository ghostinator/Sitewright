<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * A worklist of the images in a connected site's media library that have no alt text. A background job reads the plugin's image list (the owner's
 * separate "image list" approval) page by page and stores each image with a suggestion from AltText; people review the list, correct what needs it,
 * and apply in bulk through MediaWriteback (which needs the owner's further "change image text" approval and logs every change so it can be undone).
 * Everything the site sends is untrusted: text is cleaned and capped, and an address that is not on the site's own host is dropped.
 */
final class MediaFill
{
    private const MAX_PAGES = 120;       // 12,000 images
    private const BUDGET_SECONDS = 420;  // below job_stale_seconds (600), with Jobs::touch() per page

    /** Job: payload {site_id}. */
    public static function handleJob(array $p): array
    {
        $siteId = (int)($p['site_id'] ?? 0);
        $db = Database::getInstance();
        try { $ctx = MediaWriteback::ready($siteId, 'media_list'); }
        catch (ResponseException $e) { throw new JobFailedException((string)(json_decode($e->body, true)['message'] ?? 'The site is not ready'), $e->status); }
        $site = $ctx['site']; $host = self::bare((string)parse_url((string)$site['base_url'], PHP_URL_HOST));
        $t = $db->t('media_missing'); $started = gmdate('Y-m-d H:i:s'); $deadline = microtime(true) + self::BUDGET_SECONDS;
        $seen = 0; $cursor = 0; $complete = false; $seenIds = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            if (microtime(true) > $deadline) { break; }
            Jobs::touch();
            $r = SiteSync::call($site, $ctx['secret'], 'GET', '/sitewright/v1/media-list', null, ['cursor' => $cursor, 'limit' => 100]);
            $rows = [];
            foreach (is_array($r['items'] ?? null) ? $r['items'] : [] as $it) {
                if (!is_array($it) || !is_int($it['id'] ?? null) || $it['id'] <= 0 || !is_string($it['url'] ?? null)) { continue; }
                $url = Url::normalize($it['url']);
                if ($url === null || self::bare((string)parse_url($url, PHP_URL_HOST)) !== $host || strlen($url) > 500) { continue; }   // only the site's own pictures
                $title = SiteProfile::clean($it['title'] ?? null, 200) ?? ''; $parent = SiteProfile::clean($it['parent_title'] ?? null, 200) ?? '';
                $pu = is_string($it['parent_url'] ?? null) ? Url::normalize($it['parent_url']) : null;
                $s = AltText::suggest($title, $url, $parent);
                $rows[] = [(int)$it['id'], $url, $title, $parent, $pu !== null && strlen($pu) <= 500 && self::bare((string)parse_url($pu, PHP_URL_HOST)) === $host ? $pu : null, $s['text'] !== '' ? $s['text'] : null, $s['confidence']];
            }
            if ($rows) {
                $ph = []; $bind = [];
                foreach ($rows as $n => [$id, $url, $title, $parent, $pu, $text, $conf]) { $ph[] = "(:s$n, :i$n, :u$n, :t$n, :p$n, :pu$n, :x$n, :c$n, :n$n)"; $bind += ["s$n" => $siteId, "n$n" => $started, "i$n" => $id, "u$n" => $url, "t$n" => $title, "p$n" => $parent, "pu$n" => $pu, "x$n" => $text, "c$n" => $conf]; }
                // a row that is already there keeps what a person typed in (suggestion) and its status; the descriptive fields are refreshed
                $db->query("INSERT INTO `$t` (site_id, attachment_id, url, title, parent_title, parent_url, suggestion, confidence, scanned_at) VALUES " . implode(',', $ph)
                    . ' ON DUPLICATE KEY UPDATE url = VALUES(url), title = VALUES(title), parent_title = VALUES(parent_title), parent_url = VALUES(parent_url), scanned_at = VALUES(scanned_at)', $bind);
            }
            $seen += count($rows); foreach ($rows as $row) { $seenIds[$row[0]] = true; }
            $next = $r['next'] ?? null;
            if (!is_int($next) || $next <= $cursor) { $complete = true; break; }
            $cursor = $next;
        }
        if ($complete) {   // an open image the site no longer lists has alt text now (fixed elsewhere): take it off the list
            $gone = array_values(array_filter(array_map('intval', array_column($db->fetchAll("SELECT attachment_id FROM `$t` WHERE site_id = :s AND status = 'open'", ['s' => $siteId]), 'attachment_id')), static fn (int $i): bool => !isset($seenIds[$i])));
            foreach (array_chunk($gone, 500) as $chunk) { $db->query("DELETE FROM `$t` WHERE site_id = :s AND status = 'open' AND attachment_id IN (" . implode(',', $chunk) . ')', ['s' => $siteId]); }
        }
        return ['message' => 'Found ' . $seen . ' images without alt text' . ($complete ? '' : ' (stopped early; scan again to continue)'), 'data' => ['seen' => $seen, 'complete' => $complete]];
    }

    private static function bare(string $h): string { return preg_replace('/^www\./', '', strtolower($h)) ?? $h; }

    public static function queueUnlessOpen(int $siteId, ?int $userId): int
    {
        $db = Database::getInstance();
        $open = $db->fetchOne('SELECT id FROM `' . $db->t('jobs') . "` WHERE type = 'media_scan' AND status IN ('queued','running') AND payload = :p", ['p' => (string)json_encode(['site_id' => $siteId])]);
        return $open ? (int)$open['id'] : Jobs::enqueue('media_scan', ['site_id' => $siteId], $userId, 0, 1);
    }

    /** @return array{open:int,good:int,fair:int,none:int,applied:int,skipped:int,scanned_at:?string} */
    public static function summary(int $siteId): array
    {
        $db = Database::getInstance();
        $r = $db->fetchOne('SELECT SUM(status = \'open\') open_n, SUM(status = \'open\' AND confidence = \'good\') good, SUM(status = \'open\' AND confidence = \'fair\') fair, SUM(status = \'open\' AND confidence = \'none\') none_n,
            SUM(status = \'applied\') applied, SUM(status = \'skipped\') skipped, MAX(scanned_at) at FROM `' . $db->t('media_missing') . '` WHERE site_id = :s', ['s' => $siteId]) ?? [];
        return ['open' => (int)($r['open_n'] ?? 0), 'good' => (int)($r['good'] ?? 0), 'fair' => (int)($r['fair'] ?? 0), 'none' => (int)($r['none_n'] ?? 0), 'applied' => (int)($r['applied'] ?? 0), 'skipped' => (int)($r['skipped'] ?? 0), 'scanned_at' => $r['at'] ?? null];
    }

    /** @return list<array<string,mixed>> open images, optionally of one confidence */
    public static function items(int $siteId, string $confidence, int $limit, int $offset): array
    {
        $db = Database::getInstance();
        $w = in_array($confidence, ['good', 'fair', 'none'], true) ? ' AND confidence = :c' : '';
        $q = ['s' => $siteId] + ($w !== '' ? ['c' => $confidence] : []);
        return $db->fetchAll('SELECT attachment_id id, url, title, parent_title, parent_url, suggestion, confidence FROM `' . $db->t('media_missing') . "` WHERE site_id = :s AND status = 'open'$w ORDER BY FIELD(confidence, 'good', 'fair', 'none'), attachment_id LIMIT " . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset), $q);
    }

    /**
     * Apply reviewed text. $items: [{id: attachment id, alt: text}]. Each is stored with the text (so a person's edit is kept), pushed through the
     * write-back (owner approval, change log, undo), and marked applied only if the site accepted it. @return array{applied:int,failed:list<array{url:string,error:string}>,skipped:int}
     * @throws ResponseException
     */
    public static function apply(int $siteId, array $items, ?int $userId): array
    {
        if (!$items || count($items) > 200) { Validator::fail('items', 'choose between 1 and 200 images'); }
        $db = Database::getInstance(); $t = $db->t('media_missing'); $ids = []; $urlOf = [];
        foreach ($items as $it) {
            $id = is_array($it) ? (int)($it['id'] ?? 0) : 0; $alt = is_array($it) && is_string($it['alt'] ?? null) ? (SiteProfile::clean($it['alt'], 300) ?? '') : '';
            if ($id <= 0 || $alt === '') { continue; }
            $row = $db->fetchOne("SELECT url FROM `$t` WHERE site_id = :s AND attachment_id = :a AND status = 'open'", ['s' => $siteId, 'a' => $id]);
            if (!$row) { continue; }
            $alt = mb_substr($alt, 0, 300);
            $db->update('media_missing', ['suggestion' => $alt], ['site_id' => $siteId, 'attachment_id' => $id]);
            $db->query('INSERT INTO `' . $db->t('image_seo') . '` (project_id, image_url, alt_text) VALUES (:p, :u, :a) ON DUPLICATE KEY UPDATE alt_text = VALUES(alt_text)', ['p' => $siteId, 'u' => $row['url'], 'a' => $alt]);
            $img = $db->fetchOne('SELECT id FROM `' . $db->t('image_seo') . '` WHERE project_id = :p AND image_url = :u', ['p' => $siteId, 'u' => $row['url']]);
            if ($img) { $ids[] = (int)$img['id']; $urlOf[$row['url']] = $id; }
        }
        if (!$ids) { return ['applied' => 0, 'failed' => [], 'skipped' => count($items)]; }
        $r = MediaWriteback::apply($siteId, $ids, $userId);
        foreach ($r['ok_urls'] as $u) { if (isset($urlOf[$u])) { $db->update('media_missing', ['status' => 'applied'], ['site_id' => $siteId, 'attachment_id' => $urlOf[$u]]); } }
        return ['applied' => $r['applied'], 'failed' => $r['failed'], 'skipped' => count($items) - count($ids)];
    }

    /** Leave these images alone (decorative, or to be handled by hand). @param list<int> $attachmentIds */
    public static function skip(int $siteId, array $attachmentIds): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $attachmentIds), static fn (int $i): bool => $i > 0)));
        if (!$ids || count($ids) > 500) { Validator::fail('ids', 'choose between 1 and 500 images'); }
        $db = Database::getInstance();
        return $db->query('UPDATE `' . $db->t('media_missing') . "` SET status = 'skipped' WHERE site_id = :s AND status = 'open' AND attachment_id IN (" . implode(',', $ids) . ')', ['s' => $siteId])->rowCount();
    }
}
