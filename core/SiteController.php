<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** Connected WordPress sites (admin only). The signing secret is shown ONCE, stored encrypted, and never returned again. */
final class SiteController
{
    public function list(array $params): never
    {
        $db = Database::getInstance();
        $rows = $db->fetchAll('SELECT id, name, base_url, created_at, last_sync_at, last_status, last_error, plugin_version FROM `' . $db->t('sites') . '` ORDER BY id');
        Response::success('Sites', ['sites' => $rows, 'encryption_ready' => Crypto::configured()]);
    }

    /** Id + name only, for the site selector (editors need it; the full list with URLs and errors is admin-only). */
    public function options(array $params): never
    {
        $db = Database::getInstance();
        Response::success('Sites', $db->fetchAll('SELECT id, name FROM `' . $db->t('sites') . '` ORDER BY name'));
    }

    public function create(array $params): never
    {
        $in = Request::json();
        if (!Crypto::configured()) { Response::error('Set app_key first (php bin/gen-key.php); it encrypts the site secrets', 409); }
        $name = Validator::str($in, 'name', 100, true);
        $url = rtrim(Validator::url($in, 'base_url'), '/');
        $parts = parse_url($url);
        if (!empty($parts['query']) || !empty($parts['fragment'])) { Validator::fail('base_url', 'must not contain a query string or fragment'); }
        $db = Database::getInstance();
        if ($db->fetchOne('SELECT id FROM `' . $db->t('sites') . '` WHERE base_url = :u', ['u' => $url])) { Response::error('That site is already connected', 409); }
        $secret = bin2hex(random_bytes(32));
        $id = (int)$db->insert('sites', ['name' => $name, 'base_url' => $url, 'secret_enc' => 'pending']);
        $db->update('sites', ['secret_enc' => Crypto::encrypt($secret, 'site:' . $id)], ['id' => $id]);
        Audit::log('site_added', null, '', "$name ($url)");
        Response::success('Site added. Copy the secret into the plugin now; it is not shown again.', ['id' => $id, 'secret' => $secret], 201);
    }

    public function rotate(array $params): never
    {
        $id = Validator::id($params['id'] ?? null);
        $site = $this->find($id);
        $secret = bin2hex(random_bytes(32));
        Database::getInstance()->update('sites', ['secret_enc' => Crypto::encrypt($secret, 'site:' . $id), 'last_status' => 'never'], ['id' => $id]);
        Audit::log('site_secret_rotated', null, '', $site['name']);
        Response::success('New secret created. Put it into the plugin; syncing resumes once it matches.', ['secret' => $secret]);
    }

    public function sync(array $params): never
    {
        $id = Validator::id($params['id'] ?? null);
        $this->find($id);
        Response::success('Sync queued', ['job_id' => Jobs::enqueue('site_sync', ['site_id' => $id, 'force' => true], (int)Auth::user()['id'], 0, 1)], 202);
    }

    public function delete(array $params): never
    {
        $id = Validator::id($params['id'] ?? null);
        $site = $this->find($id);
        Database::getInstance()->delete('sites', ['id' => $id]);
        Audit::log('site_removed', null, '', $site['name']);
        Response::success('Site removed (its rules and 404 entries are kept)');
    }

    /** The plugin file itself, so the operator never has to hunt for it. */
    public function plugin(array $params): never
    {
        $f = dirname(__DIR__) . '/agent/wordpress/sitewright-agent.php';
        if (!is_file($f)) { Response::notFound('Plugin file missing'); }
        throw new ResponseException(200, (string)file_get_contents($f), [
            'Content-Type' => 'application/x-httpd-php; charset=utf-8', 'Content-Disposition' => 'attachment; filename="sitewright-agent.php"', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function find(int $id): array
    {
        $db = Database::getInstance();
        return $db->fetchOne('SELECT id, name, base_url FROM `' . $db->t('sites') . '` WHERE id = :i', ['i' => $id]) ?? Response::notFound('Site not found');
    }
}
