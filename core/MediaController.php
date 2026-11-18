<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** Image text written back to a connected WordPress site (administrators only; the site's owner must have allowed it in the plugin). */
final class MediaController
{
    public function apply(array $params): never
    {
        $site = Validator::id($params['id'] ?? null);
        $ids = is_array(Request::json()['image_ids'] ?? null) ? Request::json()['image_ids'] : [];
        $r = MediaWriteback::apply($site, $ids, self::uid());
        if ($r['applied'] > 0) { Audit::log('media_text_applied', null, '', $r['applied'] . ' image(s) on site #' . $site); }
        Response::success($r['applied'] . ' image' . ($r['applied'] === 1 ? '' : 's') . ' updated on the site', $r);
    }

    /** The signed-in user's id, for the change log (null when there is none, as in a command-line run). */
    private static function uid(): ?int
    {
        try { return (int)(Auth::user()['id'] ?? 0) ?: null; } catch (\Throwable) { return null; }
    }

    public function changes(array $params): never
    {
        Response::success('Changes', ['changes' => MediaWriteback::recent(Validator::id($params['id'] ?? null))]);
    }

    public function undo(array $params): never
    {
        $id = Validator::id($params['id'] ?? null);
        MediaWriteback::undo($id, self::uid());
        Audit::log('media_text_undone', null, '', 'change #' . $id);
        Response::success('The previous text is back on the site');
    }
}
