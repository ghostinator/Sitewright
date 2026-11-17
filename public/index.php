<?php
declare(strict_types=1);

require dirname(__DIR__) . '/core/autoload.php';

use Sitewright\Core\{App, Auth, Config};

App::boot();
$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

if (!Config::hostAllowed((string)($_SERVER['HTTP_HOST'] ?? ''))) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Invalid host. Set SW_ALLOWED_HOSTS / SW_BASE_URL.');
}
foreach (App::pageHeaders() as $k => $v) { header("$k: $v"); }

$views = [
    'dashboard' => 'Dashboard', 'seo-analysis' => 'SEO Analysis', 'competitor' => 'Competitor Analysis', 'watchlist' => 'Watchlist', 'site-audit' => 'Site Audit', 'opportunities' => 'Search Opportunities', 'analytics' => 'Analytics',
    'content-ai' => 'Content AI', 'local-seo' => 'Local SEO', 'image-seo' => 'Image SEO', 'sitemaps' => 'Sitemaps',
    '404-monitor' => '404 Log', 'redirections' => 'Redirect Rules', 'account' => 'My Account', 'sites' => 'Sites', 'users' => 'Users', 'audit' => 'Audit Log', 'settings' => 'Settings',
];
$adminViews = ['sites', 'users', 'audit', 'settings'];
$slug = trim(App::relativePath(), '/');
$view = $slug === '' ? 'dashboard' : $slug;
$resetPage = $slug === 'reset';   // public: the page the password-reset e-mail links to
if (!$resetPage && !isset($views[$view])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found');
}

try {
    $user = Auth::user();
    $csrf = Auth::csrfToken();
} catch (Throwable $e) {
    error_log('index.php: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Service temporarily unavailable');
}

if ($user && in_array($view, $adminViews, true) && $user['role'] !== 'admin') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden');
}
if ($user && $resetPage) { header('Location: ' . App::basePath() . '/'); exit; }
if ($user && Auth::needsMfaEnrolment() && $view !== 'account') { header('Location: ' . App::basePath() . '/account'); exit; }
$base = App::basePath();
$config = ['basePath' => $base, 'csrfToken' => $csrf, 'view' => $resetPage ? 'reset' : $view, 'user' => $user ? ['username' => $user['username'], 'role' => $user['role']] : null, 'mfaRequired' => $user ? Auth::needsMfaEnrolment() : false];
$configJson = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Sitewright - <?= $h($user ? $views[$view] : ($resetPage ? 'Reset password' : 'Sign in')) ?></title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="<?= $h($base) ?>/assets/css/style.css?v=<?= (int)@filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <script type="application/json" id="app-config"><?= $configJson ?></script>
</head>
<body>
<?php if (!$user && $resetPage): ?>
    <main class="login-wrap">
        <form id="reset-form" class="card login-card" autocomplete="off">
            <p class="brand">Sitewright</p>
            <h2>Choose a new password</h2>
            <div id="reset-error" class="alert alert-error hidden" role="alert"></div>
            <div id="reset-done" class="alert hidden" role="status"></div>
            <div class="form-group"><label for="reset-password">New password (at least 12 characters)</label><input id="reset-password" name="password" type="password" autocomplete="new-password" required minlength="12" maxlength="1024"></div>
            <button type="submit" class="btn btn-primary">Set password</button>
            <p><a href="<?= $h(App::basePath()) ?>/">Back to sign in</a></p>
        </form>
    </main>
<?php elseif (!$user): ?>
    <main class="login-wrap">
        <form id="login-form" class="card login-card" autocomplete="on">
            <p class="brand">Sitewright</p>
            <h2>Sign in</h2>
            <div id="login-error" class="alert alert-error hidden" role="alert"></div>
            <div class="form-group"><label for="login-username">Username</label><input id="login-username" name="username" type="text" autocomplete="username" required maxlength="60"></div>
            <div class="form-group"><label for="login-password">Password</label><input id="login-password" name="password" type="password" autocomplete="current-password" required></div>
            <div id="login-mfa" class="form-group hidden"><label for="login-code">Verification code</label><input id="login-code" name="code" type="text" inputmode="text" autocomplete="one-time-code" maxlength="32" placeholder="6-digit code or recovery code"><small>Open your authenticator app, or enter an unused recovery code.</small></div>
            <button type="submit" class="btn btn-primary">Sign in</button>
            <p><button type="button" class="btn btn-link" id="forgot-toggle">Forgot your password?</button></p>
        </form>
        <form id="forgot-form" class="card login-card hidden" autocomplete="on">
            <p class="brand">Sitewright</p>
            <h2>Reset your password</h2>
            <div id="forgot-msg" class="alert hidden" role="status"></div>
            <div class="form-group"><label for="forgot-email">E-mail address</label><input id="forgot-email" name="email" type="email" autocomplete="email" required maxlength="100"></div>
            <button type="submit" class="btn btn-primary">Send reset link</button>
            <p><button type="button" class="btn btn-link" id="forgot-back">Back to sign in</button></p>
        </form>
    </main>
<?php else: ?>
    <div class="app-container">
        <aside class="sidebar">
            <div class="logo"><h2>Sitewright</h2><p>SEO toolkit</p></div>
<?php
$navGroups = ['SEO tools' => [], 'Admin' => []];
foreach ($views as $slugName => $title) {
    if (in_array($slugName, $adminViews, true) && $user['role'] !== 'admin') { continue; }
    if (Auth::needsMfaEnrolment() && $slugName !== 'account') { continue; }
    if ($slugName === 'account') { continue; }
    $navGroups[in_array($slugName, $adminViews, true) ? 'Admin' : 'SEO tools'][$slugName] = $title;
}
$navLink = function (string $slugName, string $title) use ($h, $base): string {
    return '<a href="' . $h($base . ($slugName === 'dashboard' ? '/' : '/' . $slugName)) . '" class="nav-item" data-view="' . $h($slugName) . '">' . $h($title) . '</a>';
};
?>
            <nav class="nav-menu" aria-label="Main">
<?php foreach ($navGroups as $groupName => $items): if (!$items) { continue; } ?>
                <p class="nav-heading"><?= $h($groupName) ?></p>
<?php foreach ($items as $slugName => $title): ?>
                <?= $navLink($slugName, $title) ?>

<?php endforeach; endforeach; ?>
            </nav>
            <div class="nav-foot"><?= $navLink('account', $views['account']) ?></div>
        </aside>

        <main class="main-content">
            <header class="top-bar">
                <h1 id="page-title"><?= $h($views[$view]) ?></h1>
                <div class="user-actions"><label class="visually-hidden" for="site-switch">Site</label><select id="site-switch" class="site-switch"><option value="0">All sites</option></select> <span class="user-name"><?= $h($user['username']) ?></span> <button type="button" class="btn btn-secondary btn-sm" data-action="logout">Sign out</button></div>
            </header>

            <p id="print-title" class="print-only" aria-hidden="true"></p>
            <div class="content-area" id="content-area">
                <div class="view" id="view-dashboard">
                    <div class="stats-grid">
                        <div class="stat-card" data-card="seo"><div class="stat-info"><h3>SEO Score (your site)</h3><p class="stat-value" id="stat-seo">--</p></div></div>
                        <div class="stat-card" data-card="keywords"><div class="stat-info"><h3>Tracked Keywords</h3><p class="stat-value" id="stat-keywords">--</p></div></div>
                        <div class="stat-card" data-card="impressions"><div class="stat-info"><h3>Impressions (30d)</h3><p class="stat-value" id="stat-impressions">--</p></div></div>
                        <div class="stat-card" data-card="clicks"><div class="stat-info"><h3>Clicks (30d)</h3><p class="stat-value" id="stat-clicks">--</p></div></div>
                    </div>
                    <p id="dashboard-note" class="text-muted"></p>
                    <div class="card" id="overview-card"><h3>Your sites</h3><div id="overview-list"></div></div>
                    <div class="dashboard-grid">
                        <div class="card"><h3>Quick Actions</h3><div class="quick-actions">
                            <button type="button" class="btn btn-primary" data-goto="seo-analysis">Run SEO Analysis</button>
                            <button type="button" class="btn btn-secondary" data-goto="competitor">Analyze Competitor</button>
                            <button type="button" class="btn btn-secondary" data-goto="content-ai">Generate Content</button>
                            <button type="button" class="btn btn-secondary" data-goto="404-monitor">View 404 Log</button>
                        </div></div>
                        <div class="card"><h3>Recent Activity</h3><div id="recent-activity"><p class="text-muted">No recent activity</p></div></div>
                    </div>
                </div>

                <div class="view" id="view-seo-analysis"><div class="card">
                    <h3>Website SEO Analysis</h3>
                    <p class="text-muted">Fetches the page once from this server and runs heuristic on-page checks. Not a substitute for Lighthouse or Search Console.</p>
                    <?php if ($user && $user['role'] === 'admin'): ?><p class="text-muted settings-link-note"><small>Titles are judged against <?= (int)Config::get('seo.title_min') ?>–<?= (int)Config::get('seo.title_max') ?> characters and descriptions against <?= (int)Config::get('seo.meta_min') ?>–<?= (int)Config::get('seo.meta_max') ?>. <a href="<?= $h($base) ?>/settings#seo">Change in Settings</a></small></p><?php endif; ?>
                    <p class="no-print"><a class="btn btn-secondary btn-sm export-link" data-export="/analyses" data-scoped="1" href="<?= $h($base) ?>/api/export/analyses">Download past analyses (CSV)</a></p>
                    <form id="seo-analysis-form" class="form"><div class="form-group"><label for="seo-url">Website URL</label><input type="url" id="seo-url" placeholder="https://example.com" required maxlength="2048"></div><button type="submit" class="btn btn-primary">Analyze Website</button></form>
                    <div id="seo-results" class="results-area"></div>
                </div></div>

                <div class="view" id="view-competitor"><div class="card">
                    <h3>Competitor Analysis</h3>
                    <?php if ($user && $user['role'] === 'admin'): ?><p class="text-muted settings-link-note"><small>Titles are judged against <?= (int)Config::get('seo.title_min') ?>–<?= (int)Config::get('seo.title_max') ?> characters and descriptions against <?= (int)Config::get('seo.meta_min') ?>–<?= (int)Config::get('seo.meta_max') ?>. <a href="<?= $h($base) ?>/settings#seo">Change in Settings</a></small></p><?php endif; ?>
                    <form id="competitor-form" class="form"><div class="form-group"><label for="competitor-url">Competitor URL</label><input type="url" id="competitor-url" placeholder="https://competitor.com" required maxlength="2048"></div><button type="submit" class="btn btn-primary">Analyze Competitor</button></form>
                    <div id="competitor-results" class="results-area"></div>
                </div></div>

                <div class="view" id="view-watchlist"><div class="card">
                    <h3>Watchlist</h3>
                    <p class="text-muted">Pages that are re-checked on a schedule. You are alerted when a page's score drops, it gains <code>noindex</code> or loses its title, goes down, and when it recovers. The site chosen in the header decides which pages are listed and which site a new page belongs to.</p>
                    <?php if ($user && $user['role'] !== 'viewer'): ?>
                    <form id="watch-form" class="form form-inline" autocomplete="off">
                        <div class="form-group"><label for="watch-url">Page address</label><input type="url" id="watch-url" placeholder="https://example.com/important-page" required maxlength="500"></div>
                        <div class="form-group"><label for="watch-label">Name (optional)</label><input id="watch-label" maxlength="100"></div>
                        <div class="form-group"><label for="watch-interval">Check</label><select id="watch-interval"><option value="6">every 6 hours</option><option value="12">every 12 hours</option><option value="24" selected>daily</option><option value="72">every 3 days</option><option value="168">weekly</option></select></div>
                        <div class="form-group"><label><input type="checkbox" id="watch-psi"> Also measure Core Web Vitals weekly (needs Settings, Integrations)</label></div>
                        <button type="submit" class="btn btn-primary">Add to watchlist</button>
                    </form>
                    <?php endif; ?>
                    <div id="watch-list" class="list-area"></div>
                    <div id="watch-history" class="list-area"></div>
                </div></div>

                <div class="view" id="view-site-audit"><div class="card">
                    <h3>Site audit</h3>
                    <p class="text-muted">Crawls a whole site politely, checks every page and lists what to fix: broken links, missing titles and descriptions, duplicates, thin pages, slow pages, pages nothing links to. Each audit is compared with the previous one, and new high-priority problems raise an alert.</p>
                    <?php if ($user && $user['role'] !== 'viewer'): ?>
                    <form id="audit-form" class="form form-inline" autocomplete="off">
                        <div class="form-group"><label for="audit-url">Site address</label><input type="url" id="audit-url" placeholder="https://example.com" required maxlength="500"></div>
                        <button type="submit" class="btn btn-primary">Run audit</button>
                    </form>
                    <p class="text-muted"><small>With a site chosen in the header its address is used. A site is audited by one run at a time.</small></p>
                    <?php endif; ?>
                    <div id="audit-runs" class="list-area"></div>
                    <div id="audit-report" class="list-area"></div>
                </div></div>

                <div class="view" id="view-opportunities"><div class="card">
                    <h3>Search opportunities</h3>
                    <p class="text-muted">Found in your Search Console data (last 28 days): results people see but rarely click, queries just below page one, pages competing for the same query, and keywords losing clicks. The site chosen in the header decides which data is used.</p>
                    <label class="inline-label" for="opp-type">Show</label>
                    <select id="opp-type"><option value="">All types</option><option value="ctr_gap">Low click-through</option><option value="striking_distance">Within reach of page one</option><option value="cannibalisation">Pages competing</option><option value="falling">Losing clicks</option></select>
                    <div id="opp-list" class="list-area"></div>
                </div></div>

                <div class="view" id="view-analytics"><div class="card">
                    <h3>Keyword Analytics</h3>
                    <p class="text-muted">Keyword data comes from Google Search Console (read-only, connect below) and from anything you add or import through the API.</p>
                    <p class="no-print"><a class="btn btn-secondary btn-sm export-link" data-export="/keywords" data-scoped="1" href="<?= $h($base) ?>/api/export/keywords">Download keywords (CSV, last 28 days)</a></p>
                    <div id="gsc-box" class="list-area"></div>
                    <div id="analytics-dashboard"></div>
                </div></div>

                <div class="view" id="view-content-ai"><div class="card">
                    <h3>Content Generator</h3>
                    <div id="ai-status" class="alert hidden"></div>
                    <form id="content-ai-form" class="form">
                        <div class="form-group"><label for="ai-keyword">Target Keyword</label><input type="text" id="ai-keyword" placeholder="e.g., digital marketing" required maxlength="200"></div>
                        <div class="form-group"><label for="ai-content-type">Content Type</label><select id="ai-content-type"><option value="paragraph">Paragraph</option><option value="title">Title</option><option value="meta_description">Meta Description</option><option value="heading">Heading</option><option value="conclusion">Conclusion</option></select></div>
                        <div class="form-group"><label for="ai-tone">Tone</label><select id="ai-tone"><option value="professional">Professional</option><option value="casual">Casual</option><option value="friendly">Friendly</option><option value="formal">Formal</option></select></div>
                        <button type="submit" class="btn btn-primary">Generate</button>
                    </form>
                    <div id="ai-results" class="results-area"></div>
                </div></div>

                <div class="view" id="view-local-seo"><div class="card">
                    <h3>Local SEO Locations</h3>
                    <p class="text-muted">Add or edit locations with opening hours, and copy the LocalBusiness JSON-LD for your site's <code>&lt;head&gt;</code>.</p>
                    <button type="button" class="btn btn-primary" data-action="toggle-form" data-target="location-form">Add New Location</button>
                    <form id="location-form" class="form hidden">
                        <input type="hidden" id="loc-id" name="loc_id">
                        <h4 id="loc-form-title">New location</h4>
                        <div class="form-row">
                            <div class="form-group"><label for="loc-name">Name *</label><input id="loc-name" name="name" required maxlength="255"></div>
                            <div class="form-group"><label for="loc-phone">Phone</label><input id="loc-phone" name="phone" maxlength="50"></div>
                        </div>
                        <div class="form-group"><label for="loc-address">Street address</label><input id="loc-address" name="address" maxlength="500"></div>
                        <div class="form-row">
                            <div class="form-group"><label for="loc-city">City</label><input id="loc-city" name="city" maxlength="100"></div>
                            <div class="form-group"><label for="loc-state">State / region</label><input id="loc-state" name="state" maxlength="100"></div>
                            <div class="form-group"><label for="loc-postal">Postal code</label><input id="loc-postal" name="postal_code" maxlength="20"></div>
                            <div class="form-group"><label for="loc-country">Country</label><input id="loc-country" name="country" maxlength="100"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label for="loc-email">Email</label><input id="loc-email" name="email" type="email" maxlength="254"></div>
                            <div class="form-group"><label for="loc-website">Website</label><input id="loc-website" name="website" type="url" maxlength="500"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label for="loc-lat">Latitude</label><input id="loc-lat" name="latitude" inputmode="decimal"></div>
                            <div class="form-group"><label for="loc-lng">Longitude</label><input id="loc-lng" name="longitude" inputmode="decimal"></div>
                        </div>
                        <fieldset class="hours-grid"><legend>Opening hours (leave a day empty, or tick closed, if it has none)</legend>
<?php foreach (['monday' => 'Mon', 'tuesday' => 'Tue', 'wednesday' => 'Wed', 'thursday' => 'Thu', 'friday' => 'Fri', 'saturday' => 'Sat', 'sunday' => 'Sun'] as $d => $abbr): ?>
                            <div class="hours-row"><span class="hours-day"><?= $abbr ?></span>
                                <input type="time" name="open_<?= $d ?>" aria-label="<?= $abbr ?> opens"> – <input type="time" name="close_<?= $d ?>" aria-label="<?= $abbr ?> closes">
                                <label><input type="checkbox" name="closed_<?= $d ?>"> closed</label></div>
<?php endforeach; ?>
                        </fieldset>
                        <button type="submit" class="btn btn-primary" id="loc-save">Save Location</button>
                        <button type="button" class="btn btn-secondary hidden" id="loc-cancel" data-action="loc-cancel">Cancel edit</button>
                    </form>
                    <pre id="loc-schema" class="hidden break-all"></pre>
                    <div id="locations-list" class="list-area"></div>
                </div></div>

                <div class="view" id="view-image-seo"><div class="card">
                    <h3>Image SEO</h3>
                    <p class="text-muted">Reads <code>&lt;img&gt;</code> tags from the page's HTML (not JavaScript-rendered images), downloads up to 50 images and reports alt text, size and dimensions.</p>
                    <form id="image-bulk-form" class="form"><div class="form-group"><label for="image-url">Page URL</label><input type="url" id="image-url" placeholder="https://example.com" required maxlength="2048"></div><button type="submit" class="btn btn-primary">Analyze Images</button></form>
                    <div id="image-results" class="results-area"></div>
                </div></div>

                <div class="view" id="view-sitemaps"><div class="card">
                    <h3>Sitemap Generator</h3>
                    <p class="text-muted">Crawls same-site HTML pages (max 500, respects robots.txt) and builds a plain XML sitemap.</p>
                    <form id="sitemap-crawl-form" class="form"><div class="form-group"><label for="sitemap-url">Website URL to crawl</label><input type="url" id="sitemap-url" placeholder="https://example.com" required maxlength="2048"></div><button type="submit" class="btn btn-primary">Crawl &amp; Generate</button></form>
                    <div id="sitemap-results" class="results-area"></div>
                    <div id="sitemap-list" class="list-area"></div>
                </div></div>

                <div class="view" id="view-404-monitor"><div class="card">
                    <h3>404 Log</h3>
                    <p class="text-muted">This app does not detect 404s on your site. Add entries here or import them from your server logs.</p>
                    <?php if ($user && $user['role'] === 'admin'): ?><p class="text-muted settings-link-note"><small><?= count((array)Config::get('monitor.ignore_patterns')) ?> ignore pattern(s) active. <a href="<?= $h($base) ?>/settings#site">Change in Settings</a></small></p><?php endif; ?>
                    <button type="button" class="btn btn-secondary" data-action="refresh-404">Refresh</button>
                    <a class="btn btn-secondary" id="export-404" href="<?= $h($base) ?>/api/404-monitor/export">Export CSV</a>
                    <button type="button" class="btn btn-danger" data-action="clear-404">Clear All</button>
                    <form id="log404-form" class="form form-inline"><div class="form-group"><label for="log404-uri">Add entry (path)</label><input id="log404-uri" placeholder="/missing-page" required maxlength="1000"></div><button type="submit" class="btn btn-primary">Add</button></form>
                    <div id="404-logs" class="list-area"></div>
                </div>
                <div class="card">
                    <h3>Suggested redirects</h3>
                    <p class="text-muted">Each 404 address is matched against the live pages found by the newest site audit and the sitemap list. One click creates a permanent (301) rule.</p>
                    <div id="redirect-suggestions" class="list-area"></div>
                </div></div>

                <div class="view" id="view-redirections"><div class="card">
                    <h3>Redirect Rules</h3>
                    <p class="text-muted">Rules are enforced on a site that has the Sitewright plugin installed (see <strong>Sites</strong>); for other servers, export them as Apache, nginx or CSV.</p>
                    <button type="button" class="btn btn-primary" data-action="toggle-form" data-target="redirection-form">Add Rule</button>
                    <a class="btn btn-secondary export-rules" data-format="apache" href="<?= $h($base) ?>/api/redirections/export?format=apache">Export Apache</a>
                    <a class="btn btn-secondary export-rules" data-format="nginx" href="<?= $h($base) ?>/api/redirections/export?format=nginx">Export nginx</a>
                    <a class="btn btn-secondary export-rules" data-format="csv" href="<?= $h($base) ?>/api/redirections/export?format=csv">Export CSV</a>
                    <form id="redirection-form" class="form hidden">
                        <div class="form-row">
                            <div class="form-group"><label for="redir-source">Source path</label><input id="redir-source" placeholder="/old-page" required maxlength="1000"></div>
                            <div class="form-group"><label for="redir-target">Target (path or URL)</label><input id="redir-target" placeholder="/new-page or https://…" maxlength="1000"></div>
                            <div class="form-group"><label for="redir-type">Type</label><select id="redir-type"><option>301</option><option>302</option><option>307</option><option>308</option><option>410</option><option>451</option></select></div>
                        </div>
                        <button type="submit" class="btn btn-primary">Save Rule</button>
                    </form>
                    <div id="redirections-list" class="list-area"></div>
                </div></div>

                <div class="view" id="view-account">
<?php if (Auth::needsMfaEnrolment()): ?>
                    <div class="alert alert-error" role="alert"><strong>Two-step verification is required for administrators.</strong> Set it up below; the rest of the app opens as soon as it is on.</div>
<?php endif; ?>
                    <div class="card">
                        <h3>Change password</h3>
                        <form id="password-form" class="form" autocomplete="off">
                            <div class="form-row">
                                <div class="form-group"><label for="pw-current">Current password</label><input id="pw-current" type="password" autocomplete="current-password" required></div>
                                <div class="form-group"><label for="pw-new">New password (12+ characters)</label><input id="pw-new" type="password" autocomplete="new-password" required minlength="12" maxlength="1024"></div>
                            </div>
                            <button type="submit" class="btn btn-primary">Change password</button>
                            <p class="text-muted">Your other sessions are signed out.</p>
                        </form>
                    </div>
                    <div class="card">
                        <h3>Two-step verification (authenticator app)</h3>
                        <p id="mfa-state" class="text-muted">Checking…</p>
                        <form id="mfa-setup-form" class="form hidden" autocomplete="off">
                            <div class="form-group"><label for="mfa-setup-pw">Your password</label><input id="mfa-setup-pw" type="password" autocomplete="current-password" required></div>
                            <button type="submit" class="btn btn-primary">Set up two-step verification</button>
                        </form>
                        <div id="mfa-secret" class="hidden">
                            <p>Add this key to your authenticator app (choose “enter a setup key”, time-based), then type the 6-digit code it shows.</p>
                            <p><code id="mfa-secret-text" class="break-all"></code></p>
                            <p class="text-muted">Or open this link on the device with the app: <span id="mfa-uri" class="break-all"></span></p>
                            <form id="mfa-enable-form" class="form form-inline" autocomplete="off"><div class="form-group"><label for="mfa-code">6-digit code</label><input id="mfa-code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required></div><button type="submit" class="btn btn-primary">Turn on</button></form>
                        </div>
                        <div id="mfa-codes" class="hidden"></div>
                        <form id="mfa-disable-form" class="form form-inline hidden" autocomplete="off">
                            <div class="form-group"><label for="mfa-disable-pw">Password</label><input id="mfa-disable-pw" type="password" autocomplete="current-password" required></div>
                            <button type="submit" class="btn btn-danger">Turn off two-step verification</button>
                        </form>
                    </div>
                </div>

                <div class="view" id="view-sites"><div class="card">
                    <h3>Connected sites</h3>
                    <p class="text-muted">Sitewright calls your WordPress site; the site never calls Sitewright, so this server can stay private. The <strong>Sitewright Agent</strong> plugin records 404s (path and referrer only: no IPs, no user agents) and enforces the redirect rules you manage here, even if Sitewright is offline.</p>
                    <ol class="text-muted"><li><a href="<?= $h($base) ?>/api/sites/plugin">Download the plugin</a> and install it (Plugins &rarr; Add New &rarr; Upload, or into <code>wp-content/mu-plugins/</code>).</li><li>Add the site below and copy the secret it shows (once) into <code>wp-config.php</code> as <code>define('SITEWRIGHT_SECRET', '…');</code> or Settings &rarr; Sitewright.</li><li>Press <em>Sync now</em>. Status turns to <em>ok</em>.</li></ol>
                    <button type="button" class="btn btn-primary" data-action="toggle-form" data-target="site-create-form">Add site</button>
                    <form id="site-create-form" class="form hidden" autocomplete="off"><div class="form-row">
                        <div class="form-group"><label for="site-name">Name</label><input id="site-name" required maxlength="100"></div>
                        <div class="form-group"><label for="site-url">Site address</label><input id="site-url" type="url" placeholder="https://www.example.com" required maxlength="255"></div></div>
                        <div class="form-group"><label><input type="checkbox" id="site-wordpress" checked> This is a WordPress site and I will install the Sitewright plugin (needed for 404 collection and redirects). Untick it to only analyse and monitor the site.</label></div>
                        <button type="submit" class="btn btn-primary">Add site</button></form>
                    <div id="site-secret" class="hidden"></div>
                    <div id="sites-list" class="list-area"></div>
                </div></div>

                <div class="view" id="view-settings"><div class="card">
                    <h3>Settings</h3>
                    <div class="settings-toolbar">
                        <div class="form-group"><label for="settings-search">Search settings</label><input id="settings-search" type="search" autocomplete="off" placeholder="Name, key or environment variable"></div>
                        <label class="settings-only"><input type="checkbox" id="settings-only"> Only changed from default</label>
                    </div>
                    <div id="settings-error" class="alert alert-error hidden" role="alert" tabindex="-1"></div>
                    <div id="settings-root"></div>
                    <div id="settings-bar" class="settings-bar hidden" role="region" aria-label="Unsaved changes"></div>
                    <dialog id="settings-dialog" aria-labelledby="settings-dialog-title"></dialog>
                </div></div>

                <div class="view" id="view-users"><div class="card">
                    <h3>Users</h3>
                    <p class="text-muted"><strong>Viewer</strong>: read-only. <strong>Editor</strong>: run analyses and change data. <strong>Admin</strong>: also manage users and see the audit log.</p>
                    <button type="button" class="btn btn-primary" data-action="toggle-form" data-target="user-create-form">Add user</button>
                    <form id="user-create-form" class="form hidden" autocomplete="off">
                        <div class="form-row">
                            <div class="form-group"><label for="nu-username">Username</label><input id="nu-username" required minlength="3" maxlength="60"></div>
                            <div class="form-group"><label for="nu-email">E-mail</label><input id="nu-email" type="email" required maxlength="100"></div>
                            <div class="form-group"><label for="nu-role">Role</label><select id="nu-role"><option value="viewer">viewer</option><option value="editor">editor</option><option value="admin">admin</option></select></div>
                            <div class="form-group"><label for="nu-password">Password (leave empty to invite by e-mail)</label><input id="nu-password" type="password" autocomplete="new-password" maxlength="1024"></div>
                        </div>
                        <button type="submit" class="btn btn-primary">Create user</button>
                    </form>
                    <div id="users-list" class="list-area"></div>
                </div></div>

                <div class="view" id="view-audit"><div class="card">
                    <h3>Audit log</h3>
                    <p class="text-muted">Sign-ins, failures, MFA, deletions, imports and account changes. Passwords and keys are never recorded.</p>
                    <div class="form-inline"><div class="form-group"><label for="audit-action">Action filter</label><input id="audit-action" placeholder="e.g. login_failed" maxlength="40"></div>
                    <button type="button" class="btn btn-secondary" data-action="audit-refresh">Refresh</button></div>
                    <div id="audit-list" class="list-area"></div>
                    <button type="button" class="btn btn-secondary hidden" id="audit-more" data-action="audit-more">Load older entries</button>
                </div></div>
            </div>
        </main>
    </div>
    <div id="toast" class="toast" role="status" aria-live="polite"></div>
<?php endif; ?>
    <script src="<?= $h($base) ?>/assets/js/app.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
</body>
</html>
