<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** The worklist of images without alt text (administrators only). */
final class MediaFillController
{
    private function site(array $params): int
    {
        $id = Validator::id($params['id'] ?? null);
        $db = Database::getInstance();
        if (!$db->fetchOne('SELECT 1 x FROM `' . $db->t('sites') . '` WHERE id = :i', ['i' => $id])) { Response::notFound('Site not found'); }
        return $id;
    }

    private static function uid(): ?int
    {
        try { return (int)(Auth::user()['id'] ?? 0) ?: null; } catch (\Throwable) { return null; }
    }

    public function scan(array $params): never
    {
        $id = $this->site($params);
        MediaWriteback::ready($id, 'media_list');   // say now, in words, if the plugin or the owner's tick is missing, instead of failing in the background
        Response::success('Reading the media library', ['job_id' => MediaFill::queueUnlessOpen($id, self::uid())], 202);
    }

    public function show(array $params): never
    {
        $id = $this->site($params);
        $conf = (string)Request::query('confidence', 'all');
        Response::success('Images without alt text', ['summary' => MediaFill::summary($id), 'items' => MediaFill::items($id, $conf, Request::queryInt('limit', 50, 1, 200), Request::queryInt('offset', 0, 0, 1000000))]);
    }

    public function apply(array $params): never
    {
        $id = $this->site($params);
        $r = MediaFill::apply($id, is_array(Request::json()['items'] ?? null) ? Request::json()['items'] : [], self::uid());
        if ($r['applied'] > 0) { Audit::log('media_text_applied', null, '', $r['applied'] . ' image(s) on site #' . $id . ' (worklist)'); }
        Response::success($r['applied'] . ' image' . ($r['applied'] === 1 ? '' : 's') . ' updated on the site', $r);
    }

    public function skip(array $params): never
    {
        $id = $this->site($params);
        $conf = Request::json()['confidence'] ?? null;
        $n = is_string($conf) ? MediaFill::skipConfidence($id, $conf) : MediaFill::skip($id, is_array(Request::json()['ids'] ?? null) ? Request::json()['ids'] : []);
        Response::success($n . ' image' . ($n === 1 ? '' : 's') . ' left as they are', ['skipped' => $n]);
    }
}
