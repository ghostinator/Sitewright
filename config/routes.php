<?php
/**
 * Route table: [METHOD, path, 'Class@method', options].
 * Options: public (no login required), session (start a session for CSRF even if public),
 *          limit (rate-limit group), upload (multipart body instead of JSON).
 * Everything NOT marked public requires an authenticated session (deny by default).
 */
$C = 'RankMathWebapp\\Core\\';
$M = 'RankMathWebapp\\Modules\\';

return [
    // Authentication
    ['GET',  '/api/health',        $C . 'AuthController@health', ['public' => true]],
    ['POST', '/api/auth/login',    $C . 'AuthController@login',  ['public' => true, 'session' => true]],
    ['POST', '/api/auth/logout',   $C . 'AuthController@logout'],
    ['GET',  '/api/auth/me',       $C . 'AuthController@me'],

    // SEO analysis (outbound fetch of the analysed URL)
    ['POST', '/api/seo-analysis/analyze', $M . 'SeoAnalysis\\SeoAnalysisController@analyze', ['limit' => 'fetch']],
    ['GET',  '/api/seo-analysis/history', $M . 'SeoAnalysis\\SeoAnalysisController@getHistory'],

    // Dashboard / analytics
    ['GET',  '/api/dashboard/stats',          $M . 'Analytics\\AnalyticsController@getDashboardStats'],
    ['GET',  '/api/analytics/dashboard',      $M . 'Analytics\\AnalyticsController@getDashboard'],
    ['POST', '/api/analytics/keyword',        $M . 'Analytics\\AnalyticsController@addKeyword'],
    ['POST', '/api/analytics/import-keywords', $M . 'Analytics\\AnalyticsController@importKeywords'],

    // 404 log (manual / imported entries; this app does not capture 404s by itself)
    ['GET',    '/api/404-monitor/logs',   $M . 'Monitor\\MonitorController@getLogs'],
    ['POST',   '/api/404-monitor/log',    $M . 'Monitor\\MonitorController@log404'],
    ['GET',    '/api/404-monitor/export', $M . 'Monitor\\MonitorController@exportCSV'],
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
    ['POST', '/api/image-seo/bulk-analyze', $M . 'ImageSeo\\ImageSeoController@bulkAnalyze',  ['limit' => 'fetch']],
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
    ['POST',   '/api/sitemap/crawl',        $M . 'Sitemap\\SitemapController@crawlAndGenerate', ['limit' => 'fetch']],
    ['DELETE', '/api/sitemap/{id}',         $M . 'Sitemap\\SitemapController@delete'],
];
