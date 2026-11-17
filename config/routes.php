<?php
/**
 * Route table: [METHOD, path, 'Class@method', options].
 * Options: public (no login required), session (start a session for CSRF even if public),
 *          limit (rate-limit group), upload (multipart body instead of JSON).
 * Everything NOT marked public requires an authenticated session (deny by default).
 */
$C = 'Sitewright\\Core\\';
$M = 'Sitewright\\Modules\\';

return [
    // Authentication
    ['GET',  '/api/health',        $C . 'AuthController@health', ['public' => true]],
    ['POST', '/api/auth/login',    $C . 'AuthController@login',  ['public' => true, 'session' => true]],
    ['POST', '/api/auth/mfa',      $C . 'AuthController@mfa',    ['public' => true, 'session' => true]],
    ['POST', '/api/auth/forgot',   $C . 'AuthController@forgot', ['public' => true, 'session' => true]],
    ['POST', '/api/auth/reset',    $C . 'AuthController@reset',  ['public' => true, 'session' => true]],
    // Own account: any signed-in role (including viewer)
    ['POST', '/api/auth/logout',   $C . 'AuthController@logout',         ['role' => 'viewer']],
    ['GET',  '/api/auth/me',       $C . 'AuthController@me'],
    ['POST', '/api/auth/password', $C . 'AuthController@changePassword', ['role' => 'viewer']],
    ['GET',    '/api/auth/devices',        $C . 'AuthController@devices',          ['role' => 'viewer']],
    ['DELETE', '/api/auth/devices',        $C . 'AuthController@forgetAllDevices', ['role' => 'viewer']],
    ['DELETE', '/api/auth/devices/{id}',   $C . 'AuthController@forgetDevice',     ['role' => 'viewer']],
    ['POST', '/api/auth/mfa/setup',   $C . 'AuthController@mfaSetup',     ['role' => 'viewer']],
    ['POST', '/api/auth/mfa/enable',  $C . 'AuthController@mfaEnable',   ['role' => 'viewer']],
    ['POST', '/api/auth/mfa/disable', $C . 'AuthController@mfaDisable',  ['role' => 'viewer']],
    // Administration (admin role)
    ['GET',    '/api/users',                    $C . 'UserController@list',      ['role' => 'admin']],
    ['POST',   '/api/users',                    $C . 'UserController@create',    ['role' => 'admin']],
    ['PATCH',  '/api/users/{id}',               $C . 'UserController@update',    ['role' => 'admin']],
    ['DELETE', '/api/users/{id}',               $C . 'UserController@delete',    ['role' => 'admin']],
    ['POST',   '/api/users/{id}/reset-mfa',     $C . 'UserController@resetMfa',  ['role' => 'admin']],
    ['POST',   '/api/users/{id}/send-reset',    $C . 'UserController@sendReset', ['role' => 'admin']],
    ['GET',    '/api/sites/options',            $C . 'SiteController@options',   ['role' => 'viewer']],
    ['GET',    '/api/overview',                 $C . 'OverviewController@sites', ['role' => 'viewer']],
    ['GET',    '/api/sites',                    $C . 'SiteController@list',      ['role' => 'admin']],
    ['POST',   '/api/sites',                    $C . 'SiteController@create',    ['role' => 'admin']],
    ['GET',    '/api/sites/plugin',             $C . 'SiteController@plugin',    ['role' => 'admin']],
    ['POST',   '/api/sites/{id}/sync',          $C . 'SiteController@sync',      ['role' => 'admin']],
    ['POST',   '/api/sites/{id}/rotate-secret', $C . 'SiteController@rotate',    ['role' => 'admin']],
    ['DELETE', '/api/sites/{id}',               $C . 'SiteController@delete',    ['role' => 'admin']],
    ['GET',    '/api/gsc',                      $C . 'GscController@status',      ['role' => 'admin']],
    ['POST',   '/api/gsc/connect',              $C . 'GscController@connect',     ['role' => 'admin']],
    ['GET',    '/api/gsc/callback',             $C . 'GscController@callback',    ['role' => 'admin']],
    ['GET',    '/api/gsc/{id}/properties',      $C . 'GscController@properties',  ['role' => 'admin']],
    ['POST',   '/api/gsc/{id}/property',        $C . 'GscController@setProperty', ['role' => 'admin']],
    ['POST',   '/api/gsc/{id}/site',            $C . 'GscController@setSite',     ['role' => 'admin']],
    ['POST',   '/api/gsc/{id}/sync',            $C . 'GscController@sync',        ['role' => 'admin']],
    ['DELETE', '/api/gsc/{id}',                 $C . 'GscController@disconnect',  ['role' => 'admin']],
    ['GET',    '/api/settings',                         $C . 'SettingsController@list',    ['role' => 'admin']],
    ['PUT',    '/api/settings',                         $C . 'SettingsController@save',    ['role' => 'admin']],
    ['POST',   '/api/settings/test/{name}',             $C . 'SettingsController@test',    ['role' => 'admin']],
    ['POST',   '/api/settings/{key}/promote',           $C . 'SettingsController@promote', ['role' => 'admin']],
    ['DELETE', '/api/settings/{key}',                   $C . 'SettingsController@reset',   ['role' => 'admin']],
    ['GET',    '/api/alerts',                   $C . 'AlertController@list',     ['role' => 'admin']],
    ['POST',   '/api/alerts/mute',              $C . 'AlertController@mute',     ['role' => 'admin']],
    ['DELETE', '/api/alerts/mute/{id}',         $C . 'AlertController@unmute',   ['role' => 'admin']],
    ['POST',   '/api/alerts/preset',            $C . 'AlertController@preset',   ['role' => 'admin']],
    ['GET',    '/api/audit',                    $C . 'AuditController@list',     ['role' => 'admin']],

    // Watchlist: pages re-checked on a schedule (viewer reads, editor changes and runs)
    ['GET',    '/api/watch',                $C . 'WatchController@list',    ['role' => 'viewer']],
    ['POST',   '/api/watch',                $C . 'WatchController@create',  ['role' => 'editor']],
    ['GET',    '/api/watch/{id}/history',   $C . 'WatchController@history', ['role' => 'viewer']],
    ['POST',   '/api/watch/{id}/run',       $C . 'WatchController@run',     ['role' => 'editor']],
    ['PATCH',  '/api/watch/{id}',           $C . 'WatchController@update',  ['role' => 'editor']],
    ['DELETE', '/api/watch/{id}',           $C . 'WatchController@delete',  ['role' => 'editor']],

    // Core Web Vitals through Google PageSpeed Insights (opt-in under Settings)
    ['GET',    '/api/pagespeed/status',          $C . 'PageSpeedController@status',  ['role' => 'viewer']],
    ['GET',    '/api/pagespeed',                 $C . 'PageSpeedController@history', ['role' => 'viewer']],
    ['POST',   '/api/pagespeed/run',             $C . 'PageSpeedController@run',     ['role' => 'editor', 'limit' => 'fetch']],

    // Search opportunities (from Search Console data)
    ['GET',    '/api/opportunities',             $C . 'OpportunityController@list', ['role' => 'viewer']],

    // CSV downloads (read-only; none contains visitor addresses)
    ['GET',    '/api/export/audit/{id}',           $C . 'ExportController@audit',              ['role' => 'viewer']],
    ['GET',    '/api/export/watch',                $C . 'ExportController@watch',              ['role' => 'viewer']],
    ['GET',    '/api/export/watch/{id}/history',   $C . 'ExportController@watchHistory',       ['role' => 'viewer']],
    ['GET',    '/api/export/overview',             $C . 'ExportController@overview',           ['role' => 'viewer']],
    ['GET',    '/api/export/opportunities',        $C . 'ExportController@opportunities',      ['role' => 'viewer']],
    ['GET',    '/api/export/analyses',             $C . 'ExportController@analyses',           ['role' => 'viewer']],
    ['GET',    '/api/export/keywords',             $C . 'ExportController@keywords',           ['role' => 'viewer']],
    ['GET',    '/api/export/redirect-suggestions', $C . 'ExportController@redirectSuggestions', ['role' => 'viewer']],

    // Site audits (crawl + per-page checks)
    ['GET',    '/api/site-audits',               $C . 'SiteAuditController@list',   ['role' => 'viewer']],
    ['POST',   '/api/site-audits',               $C . 'SiteAuditController@start',  ['role' => 'editor', 'limit' => 'fetch']],
    ['GET',    '/api/site-audits/{id}',          $C . 'SiteAuditController@show',   ['role' => 'viewer']],
    ['GET',    '/api/site-audits/{id}/issues',   $C . 'SiteAuditController@issues', ['role' => 'viewer']],
    ['GET',    '/api/site-audits/{id}/pages',    $C . 'SiteAuditController@pages',  ['role' => 'viewer']],
    ['DELETE', '/api/site-audits/{id}',          $C . 'SiteAuditController@delete', ['role' => 'editor']],

    // SEO analysis (outbound fetch of the analysed URL)
    ['POST', '/api/seo-analysis/analyze', $M . 'SeoAnalysis\\SeoAnalysisController@analyze', ['limit' => 'fetch']],
    ['GET',  '/api/seo-analysis/history', $M . 'SeoAnalysis\\SeoAnalysisController@getHistory'],

    // Dashboard / analytics
    ['GET',  '/api/dashboard/stats',          $M . 'Analytics\\AnalyticsController@getDashboardStats'],
    ['GET',  '/api/analytics/dashboard',      $M . 'Analytics\\AnalyticsController@getDashboard'],
    ['POST', '/api/analytics/keyword',        $M . 'Analytics\\AnalyticsController@addKeyword'],
    ['POST', '/api/analytics/import-keywords', $M . 'Analytics\\AnalyticsController@importKeywords'],

    // 404 log (manual / imported entries; this app does not capture 404s by itself)
    ['GET',    '/api/404-monitor/logs',   $M . 'Monitor\\MonitorController@getLogs',   ['role' => 'editor']],   // contains visitor IPs and user agents
    ['POST',   '/api/404-monitor/log',    $M . 'Monitor\\MonitorController@log404'],
    ['GET',    '/api/404-monitor/suggestions', $C . 'SuggestionController@redirects', ['role' => 'viewer']],
    ['GET',    '/api/404-monitor/export', $M . 'Monitor\\MonitorController@exportCSV', ['role' => 'editor']],
    ['POST',   '/api/404-monitor/clear',  $M . 'Monitor\\MonitorController@clearLogs'],
    ['DELETE', '/api/404-monitor/{id}',   $M . 'Monitor\\MonitorController@deleteLog'],

    // Redirect rule manager (rules are exported to your web server; they are not enforced by this app)
    ['GET',    '/api/redirections',        $M . 'Redirections\\RedirectionsController@getAll'],
    ['POST',   '/api/redirections',        $M . 'Redirections\\RedirectionsController@add'],
    ['GET',    '/api/redirections/check',  $M . 'Redirections\\RedirectionsController@checkRedirect'],
    ['GET',    '/api/redirections/export', $M . 'Redirections\\RedirectionsController@export'],
    ['POST',   '/api/redirections/import', $M . 'Redirections\\RedirectionsController@importCSV', ['upload' => true]],
    ['PUT',    '/api/redirections/{id}',   $M . 'Redirections\\RedirectionsController@update'],
    ['DELETE', '/api/redirections/{id}',   $M . 'Redirections\\RedirectionsController@delete'],

    // Local SEO
    ['GET',    '/api/local-seo/locations',               $M . 'LocalSeo\\LocalSeoController@getLocations'],
    ['POST',   '/api/local-seo/locations',               $M . 'LocalSeo\\LocalSeoController@addLocation'],
    ['GET',    '/api/local-seo/locations/{id}',          $M . 'LocalSeo\\LocalSeoController@getLocation'],
    ['PUT',    '/api/local-seo/locations/{id}',          $M . 'LocalSeo\\LocalSeoController@updateLocation'],
    ['DELETE', '/api/local-seo/locations/{id}',          $M . 'LocalSeo\\LocalSeoController@deleteLocation'],
    ['GET',    '/api/local-seo/locations/{id}/schema',   $M . 'LocalSeo\\LocalSeoController@getSchema'],
    ['GET',    '/api/local-seo/nearby',                  $M . 'LocalSeo\\LocalSeoController@searchNearby'],

    // Image SEO
    ['GET',  '/api/image-seo/images',       $M . 'ImageSeo\\ImageSeoController@getImages'],
    ['POST', '/api/image-seo/analyze',      $M . 'ImageSeo\\ImageSeoController@analyzeImage', ['limit' => 'fetch']],
    ['POST', '/api/image-seo/bulk-analyze', $C . 'JobController@bulkImages',  ['limit' => 'fetch']],
    ['GET',  '/api/image-seo/suggest-alt',  $M . 'ImageSeo\\ImageSeoController@suggestAltText'],
    ['PUT',  '/api/image-seo/{id}',         $M . 'ImageSeo\\ImageSeoController@updateImage'],
    ['GET',  '/api/image-seo/{id}/tips',    $M . 'ImageSeo\\ImageSeoController@getOptimizationTips'],

    // Content AI
    ['GET',  '/api/content-ai/status',      $M . 'ContentAi\\ContentAiController@status'],
    ['POST', '/api/content-ai/generate',    $M . 'ContentAi\\ContentAiController@generateContent', ['limit' => 'ai']],
    ['POST', '/api/content-ai/rewrite',     $M . 'ContentAi\\ContentAiController@rewriteContent',  ['limit' => 'ai']],
    ['GET',  '/api/content-ai/suggestions', $M . 'ContentAi\\ContentAiController@getSuggestions'],
    ['GET',  '/api/content-ai/history',     $M . 'ContentAi\\ContentAiController@getHistory'],
    ['GET',  '/api/content-ai/research',    $M . 'ContentAi\\ContentAiController@researchKeyword'],

    // Sitemaps
    ['GET',    '/api/sitemap',              $M . 'Sitemap\\SitemapController@getAll'],
    ['POST',   '/api/sitemap',              $M . 'Sitemap\\SitemapController@add'],
    ['GET',    '/api/sitemap/generate-xml', $M . 'Sitemap\\SitemapController@generateXML'],
    ['POST',   '/api/sitemap/crawl',        $C . 'JobController@crawl', ['limit' => 'fetch']],
    ['GET',    '/api/jobs/{id}',            $C . 'JobController@status'],
    ['DELETE', '/api/sitemap/{id}',         $M . 'Sitemap\\SitemapController@delete'],
];
