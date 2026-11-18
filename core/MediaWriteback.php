<?php
declare(strict_types=1);

namespace Sitewright\Core;

/**
 * Pushes the alt text and title of images (edited in Image SEO) to a connected WordPress site, and undoes it. The only thing here that CHANGES a site, so:
 * the site's owner must have ticked "Change image text" in the plugin (checked on the site itself, every time, not remembered); only the images the user
 * chose, only non-empty text; only alt text and title; and every change is logged with the previous values so it can be put back.
 */
final class MediaWriteback
{
    public const MIN_PLUGIN = '1.2.0';
    private const ROUTE = '/sitewright/v1/media-text';

    /** @return array{site:array,secret:string} the site, after checking the plugin is there, new enough and allowed to write @throws ResponseException */
    private static function ready(int $siteId): array
    {
        $db = Database::getInstance();
        $site = $db->fetchOne('SELECT * FROM `' . $db->t('sites') . '` WHERE id = :i', ['i' => $siteId]) ?? Response::notFound('Site not found');
        if (!(int)$site['sync_enabled']) { Response::error('This site does not use the Sitewright plugin, so its image text cannot be changed from here.', 409); }
        try {
            $secret = Crypto::decrypt((string)$site['secret_enc'], 'site:' . $siteId);
            $status = SiteSync::call($site, $secret, 'GET', '/sitewright/v1/status');
        } catch (JobRetryException | JobFailedException | ConfigException $e) {
            Response::error('Could not reach the site: ' . $e->getMessage(), 502);
        }
        if (version_compare((string)($status['plugin_version'] ?? '0'), self::MIN_PLUGIN, '<')) { Response::error('Update the Sitewright plugin on the site to ' . self::MIN_PLUGIN . ' or newer first (download it from the Sites page).', 409); }
        if (($status['features']['media_write'] ?? false) !== true) { Response::error('The site owner has not allowed this. In WordPress open Settings, Sitewright and tick "Change image text".', 409); }
        return ['site' => $site, 'secret' => $secret];
    }

    /** @param list<array<string,string>> $items @return list<array<string,mixed>> the plugin's answer per item */
    private static function send(array $site, string $secret, array $items): array
    {
        $out = [];
        foreach (array_chunk($items, 50) as $chunk) {
            try { $r = SiteSync::call($site, $secret, 'POST', self::ROUTE, ['items' => $chunk]); }
            catch (JobRetryException | JobFailedException $e) { Response::error('The site did not accept the change: ' . $e->getMessage(), 502); }
            $res = is_array($r['results'] ?? null) ? array_values($r['results']) : [];
            foreach ($chunk as $i => $_) { $out[] = is_array($res[$i] ?? null) ? $res[$i] : ['ok' => false, 'error' => 'no answer']; }
        }
        return $out;
    }

    /** @param list<int> $imageIds rows of image_seo that belong to this site @return array{applied:int,failed:list<array{url:string,error:string}>,skipped:int} */
    public static function apply(int $siteId, array $imageIds, ?int $userId): array
    {
        $imageIds = array_values(array_unique(array_filter(array_map('intval', $imageIds), static fn (int $i): bool => $i > 0)));
        if (!$imageIds || count($imageIds) > 200) { Validator::fail('image_ids', 'choose between 1 and 200 images'); }
        $db = Database::getInstance();
        $in = implode(',', $imageIds);
        $rows = $db->fetchAll('SELECT id, image_url, alt_text, title FROM `' . $db->t('image_seo') . "` WHERE id IN ($in) AND project_id = :p ORDER BY id", ['p' => $siteId]);
        $items = []; $meta = [];
        foreach ($rows as $r) {
            $alt = trim((string)$r['alt_text']); $title = trim((string)$r['title']);
            if ($alt === '' && $title === '') { continue; }   // nothing typed in: nothing to send (an empty box never blanks a site's text)
            $items[] = array_filter(['url' => (string)$r['image_url'], 'alt' => $alt !== '' ? $alt : null, 'title' => $title !== '' ? $title : null], static fn ($v) => $v !== null);
            $meta[] = $r;
        }
        $skipped = count($imageIds) - count($items);
        if (!$items) { return ['applied' => 0, 'failed' => [], 'skipped' => $skipped]; }
        $ctx = self::ready($siteId);
        $results = self::send($ctx['site'], $ctx['secret'], $items);
        $applied = 0; $failed = [];
        foreach ($results as $i => $res) {
            if (($res['ok'] ?? false) !== true) { $failed[] = ['url' => (string)$items[$i]['url'], 'error' => mb_substr((string)($res['error'] ?? 'refused'), 0, 120)]; continue; }
            $prev = is_array($res['previous'] ?? null) ? $res['previous'] : [];
            $db->insert('media_changes', ['site_id' => $siteId, 'image_url' => mb_substr((string)$items[$i]['url'], 0, 1000), 'attachment_id' => (int)($res['id'] ?? 0), 'old_alt' => mb_substr((string)($prev['alt'] ?? ''), 0, 600),
                'new_alt' => $items[$i]['alt'] ?? null, 'old_title' => mb_substr((string)($prev['title'] ?? ''), 0, 300), 'new_title' => $items[$i]['title'] ?? null, 'applied_by' => $userId]);
            $applied++;
        }
        return ['applied' => $applied, 'failed' => $failed, 'skipped' => $skipped];
    }

    /** Put the previous text back. @throws ResponseException */
    public static function undo(int $changeId, ?int $userId): void
    {
        $db = Database::getInstance();
        $c = $db->fetchOne('SELECT * FROM `' . $db->t('media_changes') . '` WHERE id = :i', ['i' => $changeId]) ?? Response::notFound('Change not found');
        if ($c['undone_at'] !== null) { Response::error('That change was already undone.', 409); }
        $ctx = self::ready((int)$c['site_id']);
        $item = ['url' => (string)$c['image_url']];
        if ($c['new_alt'] !== null) { $item['alt'] = (string)$c['old_alt']; }
        if ($c['new_title'] !== null && trim((string)$c['old_title']) !== '') { $item['title'] = (string)$c['old_title']; }
        $res = self::send($ctx['site'], $ctx['secret'], [$item])[0];
        if (($res['ok'] ?? false) !== true) { Response::error('The site did not accept it: ' . mb_substr((string)($res['error'] ?? 'refused'), 0, 120), 502); }
        $db->update('media_changes', ['undone_at' => gmdate('Y-m-d H:i:s')], ['id' => $changeId]);
    }

    /** @return list<array<string,mixed>> */
    public static function recent(int $siteId, int $limit = 30): array
    {
        $db = Database::getInstance();
        return $db->fetchAll('SELECT id, image_url, old_alt, new_alt, old_title, new_title, applied_at, undone_at FROM `' . $db->t('media_changes') . '` WHERE site_id = :s ORDER BY id DESC LIMIT ' . (int)$limit, ['s' => $siteId]);
    }
}
