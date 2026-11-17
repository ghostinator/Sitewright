<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** Search Console connection management (admin role). */
final class GscController
{
    public function status(array $params): never
    {
        $db = Database::getInstance();
        $rows = $db->fetchAll('SELECT id, property_url, site_id, created_at, last_sync_at, last_status, last_error, rows_synced FROM `' . $db->t('gsc_connections') . '` ORDER BY id');
        Response::success('Search Console', ['configured' => Gsc::configured(), 'redirect_uri' => (string)Config::get('google.redirect_uri'), 'connections' => $rows]);
    }

    public function connect(array $params): never
    {
        try { $url = Gsc::authUrl($_SESSION); } catch (GscException $e) { Response::error($e->getMessage(), 409); }
        Response::success('Redirect to Google', ['url' => $url]);
    }

    /** Google sends the browser here (top-level GET). Everything is checked against the session-bound state. */
    public function callback(array $params): never
    {
        $to = App::basePath() . '/analytics';
        try {
            Gsc::handleCallback($_SESSION, Request::allQuery(), (int)Auth::user()['id']);
            $to .= '?gsc=connected';
        } catch (GscException $e) {
            Logger::warn('gsc_callback_failed', ['reason' => $e->getMessage()]);
            $to .= '?gsc=error';
        }
        throw new ResponseException(302, '', ['Location' => $to, 'Cache-Control' => 'no-store']);
    }

    public function properties(array $params): never
    {
        try { Response::success('Properties', Gsc::properties(Validator::id($params['id'] ?? null))); } catch (GscException $e) { Response::error($e->getMessage(), 502); }
    }

    public function setProperty(array $params): never
    {
        $url = Validator::str(Request::json(), 'site_url', 255, true);
        try { Gsc::setProperty(Validator::id($params['id'] ?? null), $url); } catch (GscException $e) { Response::error($e->getMessage(), 400); }
        Response::success('Property saved');
    }

    public function setSite(array $params): never
    {
        $id = Validator::id($params['id'] ?? null);
        $site = Validator::siteId(Request::json(), 'site_id');
        try { Gsc::setSite($id, $site); } catch (GscException $e) { Response::notFound($e->getMessage()); }
        Response::success('Site saved');
    }

    public function sync(array $params): never
    {
        $id = Validator::id($params['id'] ?? null);
        Response::success('Sync queued', ['job_id' => Jobs::enqueue('gsc_sync', ['connection_id' => $id], (int)Auth::user()['id'])], 202);
    }

    public function disconnect(array $params): never
    {
        try { Gsc::disconnect(Validator::id($params['id'] ?? null)); } catch (GscException $e) { Response::notFound($e->getMessage()); }
        Response::success('Disconnected');
    }
}
