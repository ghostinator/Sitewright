<?php
declare(strict_types=1);

namespace Sitewright\Core;

/** The setup guide's API: read what a site says about itself, read it again, and choose per item (administrators decide; everyone signed in may read). */
final class SiteProfileController
{
    public function show(array $params): never
    {
        Response::success('Site details', SiteProfile::view(Validator::id($params['id'] ?? null)));
    }

    public function refresh(array $params): never
    {
        $id = Validator::id($params['id'] ?? null);
        SiteProfile::view($id);   // 404 for an unknown site
        Response::success('Reading the site', ['job_id' => SiteProfile::queue($id, (int)Auth::user()['id'])], 202);
    }

    public function choose(array $params): never
    {
        $id = Validator::id($params['id'] ?? null);
        $in = Request::json();
        $field = Validator::str($in, 'field', 30, true);
        $action = Validator::str($in, 'action', 10, true);
        $value = $in['value'] ?? null;
        if ($value !== null && (!is_string($value) || strlen($value) > 20000)) { Validator::fail('value', 'must be text'); }
        SiteProfile::choose($id, (string)$field, (string)$action, $value);
        Audit::log('site_profile_changed', null, '', "site $id: $field $action");
        Response::success('Saved', SiteProfile::view($id));
    }
}
