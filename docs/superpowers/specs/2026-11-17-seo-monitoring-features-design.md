# SEO monitoring features: design and progress ledger

Owner request (2026-11-17): build all seven proposed features, "in full", including extending the Search Console import. The owner will run Sitewright across **all of their business websites** for SEO optimisation and monitoring, so the multi-site view matters as much as any single page. Internal use only; Teams and e-mail alerts work; Telegram and Google are not connected yet. Ordering was agreed: 7 → 1 → 2 → 3 → 4 → 6 → 5.

## Goals
* One place to see every site's health at a glance (overview table), and to drill into one site (global site switcher).
* Pages are re-checked on a schedule; a regression or an outage is a Teams/e-mail alert, once, not a flood.
* A crawl-based audit of a whole site with a list of concrete issues and a diff against the previous audit.
* 404s turn into suggested redirects that can be accepted in one click.
* Search Console data becomes a list of things worth fixing, per site.
* Real Core Web Vitals on request (opt-in, Google PageSpeed Insights).
* Everything exportable.

## Non-goals / anti-goals
* No new third-party PHP dependencies (no Composer today). No hosted service.
* A failing watched site must never turn the app's own health red or spam `job_failed` alerts.
* Scheduled work must be polite: staggered, one request at a time per site, never a burst.

## Decisions (my calls; the owner did not choose these)
| Topic | Decision | Why |
|---|---|---|
| Site key | `project_id` = `sites.id` (already used by 404 log, redirects, sitemaps, image checks). 0 = "not tied to a site". New tables require 0 or an existing site. | No second concept; existing data keeps working. Deleting a site keeps its rows (as today). |
| Global scope | A header **Site** switcher (All sites / one site), remembered in the browser, sent as `project_id` by every page. "All sites" shows the **overview table** on the dashboard. | Matches "all my business websites". |
| Shared services | `SeoAnalyzer::analyzeUrl()` (fetch → analyse → score → store) and `Crawler` (same-origin crawl with callbacks) are extracted; controllers, the watchlist and the audit all call them. | One behaviour, one set of tests. |
| Search Console | Keep the date+query request. Add a second request (query+page, no date, last 28 days) into `search_pages`, replaced per site inside a transaction. `gsc_connections.site_id` ties a connection to a site; `analytics_keywords` rows get that `project_id`. | Adding `page` to the first request multiplies rows. |
| Failed checks | Watch runs and PageSpeed runs always finish as **done**; the failure is stored on the row. Alerts fire on transitions (down after N consecutive failures, recovered, score drop, noindex appeared). Never on the first run. | `healthcheck.php` goes red at 5 failed jobs/hour and every failed job alerts. |
| Long jobs | `Jobs::touch()` refreshes a running job's heartbeat inside the audit loop. | `claim()` re-queues jobs silent for `job_stale_seconds` (600). |
| Scheduling | Worker tick (every 60 s) enqueues due watch items, at most `watch.max_per_tick` (5) at a time, with `userId = null`. Audits optionally repeat every `audit.repeat_days` days (0 = off). | Politeness; `enqueue` with a user id can emit HTTP 429 inside the worker. |
| History key | `seo_analysis.watch_id` (not the URL, which is the post-redirect final URL). | Stable trend per watched page. |
| Alert events | New events (`watch_regression`, `watch_down`, `watch_recovered`, `audit_new_issues`) are added to the built-in defaults. The VM has no saved event list (only the Teams webhook), so they take effect there. A saved list replaces defaults (documented behaviour), so the Settings page says so. | Advisor review. |
| PDF | CSV downloads plus a print-styled report; "Save as PDF" is the browser's print dialog. No server-side PDF library. | No dependency, no attack surface from rendering fetched content, correct for any script. Say so plainly to the owner. |
| CSV safety | One `Csv` helper neutralises spreadsheet formulas (`= + - @` prefix). | CSV injection. |
| PageSpeed | Off by default. Settings: enable, API key (secret, optional), strategy. Sends the URL to Google. A 429 is recorded, not thrown. The key is in the query string, so no log or exception prints the URL. | Privacy doc updated. |
| Roles | viewer reads; editor runs, adds, accepts suggestions; admin changes settings. Every route has an explicit role (role-matrix test). | Existing convention. |
| Retention | `retention.watch_days` (365) for scheduled analyses; keep the last `retention.audits_keep` (10) audits per site; purge in `Jobs::maintenance()`. | Disk. |

## Data model (migrations 010-014)
* 010: `sites.sync_enabled` (a site without the WordPress plugin is analysed and monitored but never synced), `gsc_connections.site_id`, `seo_analysis.watch_id` + indexes.
* 011: `watch_items` (project_id, url, label, interval_hours, enabled, pagespeed, last_run_at, next_run_at, last_score, last_status new/ok/down, last_error, fail_count, last_noindex, created_by) unique (project_id, url_hash).
* 012: `audits`, `audit_pages`, `audit_issues` (code, severity, url, detail).
* 013: `search_pages` (project_id, page, query, impressions, clicks, ctr, position, window) unique (project_id, page_hash, query_hash).
* 014: `pagespeed_runs`.

## Progress ledger (update with every commit)
| # | Feature | Status | Commit | Tests |
|---|---------|--------|--------|-------|
| 0 | Shared refactors (SeoAnalyzer, Crawler, Jobs::touch; migration 010; `Validator::csvCell` already exists, reused for CSV) | done | (this commit) | PHP 457/457 (8.3) |
| 7 | Per-site scoping, site switcher, overview; sites without the plugin (`sync_enabled`) | done | (next commit) | PHP 466, JS 33, browser 13 |
| 1 | Watchlist, score history, regression alerts (migration 011, Watchlist/WatchController, settings group Monitoring) | done | (next commit) | PHP 482, JS 37, browser 14 |
| 2 | Site audit (+ scheduled repeat, diff; migration 012) | done | (next commit) | PHP 502, JS 40, browser 15, WP e2e green |
| 3 | Redirect suggestions from the 404 log (RedirectSuggester; no migration) | done | (next commit) | PHP 512, JS 42, browser 16 |
| 4 | Search Console: pages import, opportunities | not started | | |
| 6 | PageSpeed / Core Web Vitals (opt-in) | not started | | |
| 5 | Export: CSV everywhere + print report | not started | | |
| - | Docs, deploy to VM, full matrix | not started | | |
