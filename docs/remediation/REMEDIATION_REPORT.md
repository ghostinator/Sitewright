# Remediation report

Branch `remediation/audit-2026-11` (start tag `pre-remediation-24209d6`). Baseline audit: `docs/audit/AUDIT_REPORT.md`; brief: `docs/audit/REMEDIATION_PROMPT.md`.

## 1. REMEDIATION SUMMARY

* **Structure**: one document root `public/`; everything else (core, modules, config, database, bin, docs, tests, storage) is outside it. The two competing front-end trees, the web installer and the dev/disclosure utilities were removed.
* **Security**: session authentication with a deny-by-default route table; CSRF token + same-origin + Host allow-list + JSON-only; no CORS; one guarded outbound client (`SafeHttpClient`) for every network call; SQL identifiers validated and everything bound; no HTML-string sinks in JS plus a script/style CSP without `unsafe-inline`; generic error handling with request ids; CLI-only installer with locking.
* **Honesty**: Content AI reports its true provenance; rewrite/suggestions/alt-text/keyword research no longer pose as AI or data; redirects and the 404 log are explicitly a rule manager and a manual log; News/Video/Image sitemaps and GSC/SEMrush claims removed.
* **Data integrity**: migrations with a tracking table, unique keys via hashed generated columns, atomic upserts, transactions, null-free project scoping (0 = none), unused tables dropped.
* **Not done on purpose** (documented, not faked): redirect enforcement, 404 capture/ingest endpoint, GSC/OAuth, hours/edit UI for locations.

## 2. SECURITY STATUS

**Critical remaining: 0 / High remaining: 1 / Medium remaining: 3 (mitigated) / Low remaining: 2 (mitigated)**

* High: `SEC-003` — the code/doc fix is done, but the key is still in git history and upstream; it stays exposed until the owner revokes it.
* Medium (MITIGATED, not fully resolved): `DOS-001`, `FUNC-004`, `FUNC-005`. Low (MITIGATED): `SEC-013`, `FUNC-015`.

## 3. FILES CHANGED

(`git diff --name-status -M 24209d6..HEAD`; browser artifacts excluded)

Commit grouping note: commit `fb42620` bundles auth, CSRF, the `public/` move, the controller rewrite, the installer and migrations (the brief asked for one concern per commit). History was not rewritten (not authorised).

| Change | File | Purpose |
|---|---|---|
| modified | `.gitignore` | Ignore real config, env, logs, storage, node_modules |
| deleted | `.htaccess` | Removed: duplicate tree / web installer / dev or disclosure utility |
| deleted | `QUICKSTART.md` | Removed (merged into README; contained unsafe guidance) |
| modified | `README.md` | Rewritten to describe only what exists |
| deleted | `api.php` | Removed: duplicate tree / web installer / dev or disclosure utility |
| deleted | `assets/js/app.js` | Removed: duplicate tree / web installer / dev or disclosure utility |
| created | `bin/check-env.php` | CLI tools (install, migrate, env check, sample data, purge, ledger check) |
| created | `bin/check-ledger.sh` | CLI tools (install, migrate, env check, sample data, purge, ledger check) |
| created | `bin/generate-sample-data.php` | CLI tools (install, migrate, env check, sample data, purge, ledger check) |
| created | `bin/install.php` | CLI tools (install, migrate, env check, sample data, purge, ledger check) |
| created | `bin/migrate.php` | CLI tools (install, migrate, env check, sample data, purge, ledger check) |
| created | `bin/purge-404.php` | CLI tools (install, migrate, env check, sample data, purge, ledger check) |
| deleted | `check.php` | Removed: duplicate tree / web installer / dev or disclosure utility |
| deleted | `config/api-keys.php` | Removed: tracked config with defaults/dead settings |
| deleted | `config/app.php` | Removed: tracked config with defaults/dead settings |
| created | `config/config.example.php` | Config template (no secrets) |
| deleted | `config/database.php` | Removed: tracked config with defaults/dead settings |
| created | `config/routes.php` | Route table (deny-by-default; public/limit/upload flags) |
| created | `core/App.php` | Front controller: host/origin/auth/content-type/CSRF/rate-limit middleware, headers, error handling |
| created | `core/Auth.php` | Session authentication, CSRF token, login throttle, health |
| created | `core/AuthController.php` | Session authentication, CSRF token, login throttle, health |
| created | `core/Config.php` | Framework core (config, request/response seams, router table, validation, HTML helpers) |
| modified | `core/Database.php` | PDO wrapper: validated identifiers, typed binds, generic errors |
| created | `core/Exceptions.php` | Framework core (config, request/response seams, router table, validation, HTML helpers) |
| created | `core/Html.php` | Framework core (config, request/response seams, router table, validation, HTML helpers) |
| created | `core/Installer.php` | Migrations, CLI installer logic, rate limiter |
| created | `core/Migrator.php` | Migrations, CLI installer logic, rate limiter |
| created | `core/RateLimiter.php` | Migrations, CLI installer logic, rate limiter |
| created | `core/Request.php` | Framework core (config, request/response seams, router table, validation, HTML helpers) |
| modified | `core/Response.php` | Framework core (config, request/response seams, router table, validation, HTML helpers) |
| modified | `core/Router.php` | Framework core (config, request/response seams, router table, validation, HTML helpers) |
| created | `core/SafeHttpClient.php` | Central guarded outbound HTTP client (SSRF, size/time caps, TLS) |
| created | `core/Url.php` | RFC 3986 resolver/normaliser |
| created | `core/Validator.php` | Framework core (config, request/response seams, router table, validation, HTML helpers) |
| created | `core/autoload.php` | Framework core (config, request/response seams, router table, validation, HTML helpers) |
| moved | `database/schema.sql → database/migrations/001_initial.sql` | Versioned schema (baseline, integrity/auth, cleanup) |
| created | `database/migrations/002_integrity_auth.sql` | Versioned schema (baseline, integrity/auth, cleanup) |
| created | `database/migrations/003_drop_unused.sql` | Versioned schema (baseline, integrity/auth, cleanup) |
| modified | `docs/API_CONFIGURATION.md` | Rewritten documentation |
| modified | `docs/DEPLOYMENT_GUIDE.md` | Rewritten documentation |
| created | `docs/NETWORK_AND_PRIVACY.md` | Rewritten documentation |
| created | `docs/audit/AUDIT_REPORT.md` | Audit report and remediation brief (baseline, added untracked->tracked) |
| created | `docs/audit/REMEDIATION_PROMPT.md` | Audit report and remediation brief (baseline, added untracked->tracked) |
| moved | `API_ERROR_FIX.md → docs/history/API_ERROR_FIX.md` | Stale reports moved here with a "historical, inaccurate" banner |
| moved | `FINAL_AUDIT_REPORT.md → docs/history/FINAL_AUDIT_REPORT.md` | Stale reports moved here with a "historical, inaccurate" banner |
| moved | `FIXES_APPLIED.md → docs/history/FIXES_APPLIED.md` | Stale reports moved here with a "historical, inaccurate" banner |
| moved | `PROJECT_SUMMARY.md → docs/history/PROJECT_SUMMARY.md` | Stale reports moved here with a "historical, inaccurate" banner |
| moved | `REAL_AI_SETUP.md → docs/history/REAL_AI_SETUP.md` | Stale reports moved here with a "historical, inaccurate" banner |
| created | `docs/remediation/LEDGER.tsv` | Remediation ledger and this report |
| moved | `generate-sample-data.php → bin/generate-sample-data.php` | CLI-only sample data generator (rewritten, rows flagged is_sample) |
| deleted | `index.php` | Removed: duplicate tree / web installer / dev or disclosure utility |
| deleted | `install-handler.php` | Removed: duplicate tree / web installer / dev or disclosure utility |
| deleted | `install.php` | Removed: duplicate tree / web installer / dev or disclosure utility |
| modified | `modules/Analytics/AnalyticsController.php` | Controller rewritten (validation, SafeHttpClient, honest labelling, parameter binding) |
| modified | `modules/ContentAi/ContentAiController.php` | Controller rewritten (validation, SafeHttpClient, honest labelling, parameter binding) |
| modified | `modules/ImageSeo/ImageSeoController.php` | Controller rewritten (validation, SafeHttpClient, honest labelling, parameter binding) |
| modified | `modules/LocalSeo/LocalSeoController.php` | Controller rewritten (validation, SafeHttpClient, honest labelling, parameter binding) |
| modified | `modules/Monitor/MonitorController.php` | Controller rewritten (validation, SafeHttpClient, honest labelling, parameter binding) |
| modified | `modules/Redirections/RedirectionsController.php` | Controller rewritten (validation, SafeHttpClient, honest labelling, parameter binding) |
| modified | `modules/SeoAnalysis/SeoAnalysisController.php` | Controller rewritten (validation, SafeHttpClient, honest labelling, parameter binding) |
| modified | `modules/Sitemap/SitemapController.php` | Controller rewritten (validation, SafeHttpClient, honest labelling, parameter binding) |
| deleted | `phpinfo.php` | Removed: duplicate tree / web installer / dev or disclosure utility |
| created | `public/.htaccess` | Rewrite rules, deny rules, security headers, no indexes |
| created | `public/api.php` | API front controller |
| moved | `assets/css/style.css → public/assets/css/style.css` | Stylesheet (moved from assets/, additions for forms/alerts) |
| created | `public/assets/js/app.js` | Front end rewritten with DOM nodes only (no innerHTML), delegated events, single apiRequest |
| created | `public/index.php` | Single SPA shell: login or app, CSP-safe markup, real 404 for unknown views |
| created | `storage/.gitkeep` | Runtime state directory (install lock) kept out of the web root |
| deleted | `test-api.html` | Removed: duplicate tree / web installer / dev or disclosure utility |
| created | `tests/cases/01_url.php` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| created | `tests/cases/02_safe_http_client.php` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| created | `tests/cases/03_migrator.php` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| created | `tests/cases/04_http_security.php` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| created | `tests/cases/05_seo_image.php` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| created | `tests/cases/06_sitemap_ai_local.php` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| created | `tests/cases/07_data_controllers.php` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| created | `tests/cases/08_installer.php` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| created | `tests/cases/09_limits_sessions.php` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| created | `tests/docker/Dockerfile` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| created | `tests/docker/Dockerfile.apache` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| created | `tests/docker/apache-root.conf` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| created | `tests/docker/apache-sub.conf` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| created | `tests/docker/run.sh` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| created | `tests/docker/smoke.sh` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| created | `tests/fixtures/site/about.html` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| created | `tests/fixtures/site/img/pixel.png` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| created | `tests/fixtures/site/index.html` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| created | `tests/fixtures/site/robots.txt` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| created | `tests/fixtures/site/xss.html` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| created | `tests/js/.gitignore` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| created | `tests/js/package-lock.json` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| created | `tests/js/package.json` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| created | `tests/js/render.test.js` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| created | `tests/lib.php` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| created | `tests/router.php` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| created | `tests/run.php` | Test harness, suites, Docker/Apache smoke environment, fixtures |
| deleted | `webapp/.htaccess` | Removed: duplicate tree / web installer / dev or disclosure utility |
| deleted | `webapp/AUTO_DETECTION.md` | Removed: duplicate tree / web installer / dev or disclosure utility |
| deleted | `webapp/api.php` | Removed: duplicate tree / web installer / dev or disclosure utility |
| deleted | `webapp/assets/js/app.js` | Removed: duplicate tree / web installer / dev or disclosure utility |
| deleted | `webapp/config/config.php` | Removed: duplicate tree / web installer / dev or disclosure utility |
| deleted | `webapp/core/Router.php` | Removed: duplicate tree / web installer / dev or disclosure utility |
| deleted | `webapp/index.php` | Removed: duplicate tree / web installer / dev or disclosure utility |

## 4. FINDING-BY-FINDING REMEDIATION LEDGER

| FINDING ID | ORIGINAL SEVERITY | DESCRIPTION | FIX STATUS | FILES CHANGED | TEST / VERIFICATION | RESIDUAL RISK |
|---|---|---|---|---|---|---|
| SEC-001 | CRITICAL | Installer RCE / re-run / .htaccess overwrite / SQL injection via db_name,prefix | FIXED | core/Installer.php, bin/install.php, bin/migrate.php; removed install.php, install-handler.php | tests 08_installer (payload rejection, var_export round-trip with token scan, lock/second run refused, prefix placeholder, CLI port payload) | CLI only; DB admin password is read from env/file/prompt (prompt echoes). |
| AUTH-001 | CRITICAL | No authentication/authorization | FIXED | core/Auth.php, AuthController.php, App.php, RateLimiter.php, config/routes.php, public/index.php | 04_http_security: all 44 protected routes answer 401 unauthenticated (real table iterated); the 2 public routes verified not to demand auth; login/logout/throttle/regeneration/cookie flags; 09: idle/absolute timeout, pw-change invalidation, rate limit | Single role (every user is admin). No MFA, no password reset flow, no per-user audit log. |
| SQLI-001 | CRITICAL | SQL injection in 404 getLogs | FIXED | modules/Monitor/MonitorController.php, core/Database.php | 04_http_security: 13 payloads (ORDER BY/LIMIT/OFFSET/SLEEP/UNION) answered 200 in <3 s with clamped values; 07: identifier rejection | None known. All other SQL reviewed: values are bound, identifiers validated or code-defined. |
| SSRF-001 | HIGH | SEO analysis SSRF | FIXED | core/SafeHttpClient.php, Html.php, modules/SeoAnalysis/* | 02_safe_http_client (102 cases: IP classes, scheme/credential/port/numeric-host forms, DNS->private, redirect revalidation/limit, size, timeout, curl pinning against local server); 05 (internal URLs never reach transport) | Guard is application-level: DNS answered by the OS resolver is validated and pinned, but egress firewalling is still advised. IPv6 zone IDs rejected. |
| SSRF-002 | HIGH | Image SEO SSRF / stream wrappers / second-order | FIXED | modules/ImageSeo/ImageSeoController.php | 05: file/phar/php/internal URLs refused with zero transport calls; bulk page pointing at 10.0.0.5 and 127.0.0.1 skipped; getimagesizefromstring only | Images are downloaded up to 10 MB each (50 max per run). |
| SSRF-003 | HIGH | Sitemap crawler SSRF + unbounded crawl | FIXED | modules/Sitemap/SitemapController.php, core/Url.php | 06: internal bases rejected pre-request; max_pages=1000000 clamped to server cap; time budget; robots.txt | Crawl is synchronous in the web request (capped at 2 min). |
| SEC-003 | HIGH | Real-looking Google API key published in docs | BLOCKED - REQUIRES USER ACTION | docs/API_CONFIGURATION.md (key replaced by placeholder) | secret scan: no AIza/GOCSPX/PEM patterns in tree | KEY STILL EXISTS IN EVERY COMMIT BEFORE 1b8c068 (including 4f0b58d on this branch) and upstream; pushing this branch republishes it. Owner must revoke/rotate it and scan history. Not tested/rotated by me. |
| CSRF-001 | HIGH | Wildcard CORS + no CSRF protection | FIXED | core/App.php, Auth.php, Request.php | 04: no CORS headers (OPTIONS 405); missing/forged token 403; foreign Origin/null/Sec-Fetch-Site 403; wrong Content-Type 415; foreign Host 400 | Same-origin assumption: reverse proxies that rewrite Host must be configured in RMW_ALLOWED_HOSTS. |
| XSS-001 | HIGH | Stored XSS via innerHTML | FIXED | public/assets/js/app.js (rewritten) | tests/js/render.test.js: 10 payloads x 10 renderers (+dashboard) inert; source greps for forbidden sinks; browser E2E with hostile 404 URI/location name | Relies on CSP + DOM APIs; untested on legacy browsers. |
| XSS-002 | HIGH | XSS from third-party content / inline onclick | FIXED | public/assets/js/app.js, public/index.php; removed test-api.html | jsdom tests incl. copy-from-variable; E2E analysis of a hostile fixture page (title/meta/H1/alt) showed text only, 0 injected nodes; CSP without unsafe-inline, no inline handlers/styles in page (04) | As XSS-001. |
| FUNC-001 | HIGH | Content AI misrepresents provenance / dead model | FIXED | modules/ContentAi/ContentAiController.php, core/Config.php | 06 with mocked transport: 200, 403, 404, 503, malformed JSON, empty/safety, timeout; key header-only; credits 0 on fallback; status endpoint makes no call | Never run against live Gemini (rule 7). Default model gemini-3.6-flash taken from Google docs on 2026-11-15; endpoint form/header name not shown on the fetched pages. Verify with your key. |
| DOS-001 | MEDIUM | Resource exhaustion / amplification | MITIGATED | SafeHttpClient, all fetching controllers, App.php, RateLimiter.php | 05/06/09: single fetch, bulk cap, crawl cap + budget, import row caps (413), body limit 413, 429 after 10/min | Per-minute DB counters; no CPU/memory quotas; crawl/bulk run inside the request. |
| SEC-004 | MEDIUM | phpinfo.php / check.php disclosure | FIXED | deleted phpinfo.php, check.php; added bin/check-env.php (CLI only) | file listing; public/ contains only index.php, api.php, assets | - |
| SEC-005 | LOW | Web-executable sample data generator | FIXED | bin/generate-sample-data.php (CLI guard, --yes, is_sample flag) | lint; UI shows "sample data" badges; research/dashboard expose includes_sample_data (06,07) | - |
| SEC-006 | MEDIUM | Config hygiene | FIXED | .gitignore, config/config.example.php; removed config/database.php, api-keys.php, app.php from git | git ls-files; docs/DEPLOYMENT_GUIDE.md least-privilege grants | Removed local default copies too (they only held placeholder values). |
| SEC-007 | LOW | TLS verification disabled | FIXED | core/SafeHttpClient.php (VERIFYPEER true, VERIFYHOST 2) | grep: only these two options exist | - |
| SEC-008 | MEDIUM | Error leakage | FIXED | core/App.php, Database.php (DatabaseException), Installer | 04: DB outage returns generic JSON 500 + request_id, no SQLSTATE/host/password in body | Details go to the PHP error log (secure it). |
| SEC-009 | LOW | Missing security headers | FIXED | public/.htaccess, App::pageHeaders/apiHeaders, docs (nginx) | 04: CSP/XFO/nosniff/Referrer/Permissions present; no X-XSS-Protection; Apache probe shows CSP on assets | HSTS is opt-in (RMW_HSTS) and commented in .htaccess. `Options -Indexes` in .htaccess needs AllowOverride Options. |
| SEC-010 | MEDIUM | Non-PHP files web-served | FIXED | public/ document root; .htaccess deny rules | Apache probe: /README.md and /.htaccess 403 (and not in docroot); docs/ and database/ outside public/ | Servers whose docroot is the repo root remain vulnerable: README forbids it. |
| SEC-011 | LOW | Host/SCRIPT_NAME reflection | FIXED | public/index.php, App::basePath | 04: script-breaking query string cannot add script tags; base path from config/SCRIPT_NAME only, HEX-escaped JSON, htmlspecialchars | - |
| SEC-012 | LOW | CSV formula injection | FIXED | Validator::csvCell, Monitor/Redirections export | 07: =,+,-,@,TAB neutralised in export | - |
| SEC-013 | LOW | Prompt injection | MITIGATED | ContentAiController::cleanKeyword/buildPrompt | 06: control chars and <> stripped, 200-char cap, delimiter test | Prompt injection cannot be fully eliminated; output is rendered as inert text. |
| PRIV-001 | MEDIUM | Undocumented Nominatim geocoding | FIXED | LocalSeoController, docs/NETWORK_AND_PRIVACY.md | 06: off by default (0 requests), when on identifying UA + 1/s + address reuse | When enabled, addresses go to OpenStreetMap. |
| PRIV-002 | LOW | Gemini key in URL / body logging | FIXED | ContentAiController::gemini | 06: key only in x-goog-api-key header; error log contains only status/code (asserted) | - |
| PRIV-003 | LOW | 404 data retention / disclosure | FIXED | MonitorController::purgeExpired, bin/purge-404.php, docs/NETWORK_AND_PRIVACY.md | 07: purge test (200-day-old row removed) | Retention default 90 days; operators must configure for their jurisdiction. |
| FUNC-002 | MEDIUM | PHP 8 syntax vs documented 7.4 | FIXED | README, bin/install.php, bin/check-env.php | all tests + php -l run on 8.1 and 8.3; installer refuses older PHP/missing extensions | PHP 8.0 untested (8.1 is the documented minimum). |
| FUNC-003 | MEDIUM | searchNearby broken (placeholder reuse) | FIXED | LocalSeoController::searchNearby | 06: results sorted, NULL coords excluded, validation 400s | - |
| FUNC-004 | MEDIUM | Redirections never enforced | MITIGATED | RedirectionsController (export apache/nginx/csv, validation), UI relabelled | 07: validation (injection chars, open-redirect style targets), exports verified | Relabelled as a rule manager rather than enforced: this app is not in front of the managed site. |
| FUNC-005 | MEDIUM | 404 Monitor does not monitor | MITIGATED | MonitorController (atomic upsert, project scoping), UI relabelled "404 Log" | 07: dedupe/hit counting; E2E add/delete | No capture/ingest endpoint was built; entries are manual/imported. |
| FUNC-006 | MEDIUM | Clear ignores scope, uses TRUNCATE | FIXED | MonitorController::clearLogs | 07: confirm required, project scoped DELETE | - |
| FUNC-007 | MEDIUM | Front-end error handling | FIXED | public/assets/js/app.js (single apiRequest, pending state, 401 handling) | browser E2E: login error display, per-view results; jsdom tests | Not covered by automated browser tests; E2E was a manual-scripted Playwright run. |
| FUNC-008 | MEDIUM | SEO metric correctness | FIXED | SeoAnalysisController, core/Html.php | 05: one fetch, final-URL HTTPS, unicode word count excl. script/style, no DB row on failure, no mobile-friendly claim | Heuristics only. |
| FUNC-009 | MEDIUM | Keyword research presents templates as data | FIXED | ContentAiController::researchKeyword | 06: metrics null with no data, templates in a separate labelled field, search_volume removed, sample flag | API-only (no UI), documented. |
| FUNC-010 | MEDIUM | Pretend AI rewrite/suggestions/alt text | FIXED | ContentAiController, ImageSeoController::suggestAltText | 05/06: 501 for simplify/expand without Gemini, rule_based label, filename_heuristic label, no fake markers | - |
| FUNC-011 | MEDIUM | Sitemap correctness | FIXED | SitemapController, Url.php, migration 002 | 06: RFC3986 URLs, scheme filtering, fragments, 200-HTML only, dedupe, XML escaping, news/video/image removed | - |
| FUNC-012 | LOW | Image URL resolution / optimized logic | FIXED | ImageSeoController, Url.php | 05: relative, ../, protocol-relative, port/path kept; unknown size => NULL | JS-rendered images not seen (documented). |
| FUNC-013 | MEDIUM | GSC/OAuth/SEMrush claimed but absent | FIXED | docs, README, config; import-gsc renamed import-keywords with validation, 5,000-row cap, transaction | 07: validation + atomic import | Integration itself intentionally not built. |
| FUNC-014 | MEDIUM | Installer correctness | FIXED | Installer, Migrator, migrations | 03/08: idempotent, legacy v1.0 adoption, {{prefix}} placeholder, extension checks | - |
| FUNC-015 | LOW | Local SEO UI/validation gaps | MITIGATED | index.php form fields, LocalSeoController validation | 06: email/phone/website/coords/hours validation; E2E add | Hours editing, edit, and schema view remain API-only (documented). |
| FUNC-016 | LOW | getLogs TypeError / division by zero | FIXED | MonitorController::getLogs | 04: page=abc, page=-5, per_page=0, per_page=100000 | - |
| FUNC-017 | LOW | Dashboard/analytics wrong data | FIXED | AnalyticsController, app.js | 07: site-only score, competitor excluded; unused pages/chart dropped | - |
| FUNC-018 | LOW | Copy button breaks on quotes/newlines | FIXED | app.js (copy from JS variable) | jsdom copy test | - |
| FUNC-019 | LOW | No global error handling | FIXED | core/App.php | 04: generic 500 + request id | - |
| ARCH-001 | MEDIUM | Two competing front-end trees / hardcoded paths | FIXED | public/ (single docroot); no /rankmath paths remain | 04: every view URL serves the shell with CSS+JS; Apache E2E at / and /seo/ (Alias) | Symlink-based sub-path deployments hit Apache AH00037 in my container test; Alias deployments work. Documented. |
| ARCH-002 | LOW | Dead config / unused tables | FIXED | deleted config/app.php, api-keys.php; migration 003; limits/session settings now enforced | 03: dropped tables absent; 09 enforces timeouts | Migration 003 drops tables (all were unused). |
| ARCH-003 | LOW | Duplicate implementations | FIXED | deleted webapp/, root index/api/app.js/Router | git diff stat | - |
| ARCH-004 | LOW | Dev/test artifacts in deployable tree | FIXED | test-api.html, check.php, phpinfo.php removed; sample generator -> bin/ | file listing | - |
| ARCH-005 | LOW | Database identifier interpolation | FIXED | core/Database.php (validated identifiers, structured WHERE) | 07: malicious identifiers rejected | - |
| DB-001 | MEDIUM | Missing unique keys / races / NULL project | FIXED | migrations 002; controllers use ON DUPLICATE KEY UPDATE and transactions | 03: unique keys; 07: dedupe/hit counting; 06: re-crawl adds 0 | project_id 0 = "no project"; no FK targets exist. |
| DB-002 | LOW | Docs recommend root / GRANT ALL; TRUNCATE needs DROP | FIXED | docs/DEPLOYMENT_GUIDE.md; clear uses DELETE | 07 clear test | Migration account needs DDL privileges by design. |
| DB-003 | LOW | No migrations | FIXED | core/Migrator.php, database/migrations, schema_migrations | 03 | No down-migrations. |
| DB-004 | LOW | Unvalidated enums/decimals | FIXED | core/Validator.php used by every controller | 05-07: 400s for bad enum/priority/date/types | - |
| DOC-001 | MEDIUM | False readiness claims | FIXED | README rewritten; stale reports archived under docs/history with banner | review | Historic files remain (bannered). |
| DOC-002 | MEDIUM | False security claims | FIXED | README security model; stale docs archived | review | - |
| DOC-003 | MEDIUM | GSC/OAuth/SEMrush documented | FIXED | README, docs/API_CONFIGURATION.md | review | - |
| DOC-004 | LOW | Stale contradictory reports | FIXED | docs/history/* | review | - |
| DOC-005 | LOW | Path/layout contradictions | FIXED | README, docs/DEPLOYMENT_GUIDE.md; QUICKSTART removed | review; layout verified by Apache run | - |
| DOC-006 | INFO | Name/licence question | BLOCKED - REQUIRES USER ACTION | README note; no LICENSE added (per instructions) | - | Owner to choose a licence and review use of the "RankMath" name. No legal determination made. |
| DOC-007 | LOW | Unsafe guidance (root, Indexes, chmod, CLI passwords) | FIXED | docs/DEPLOYMENT_GUIDE.md, README | review | - |
| MAL-001 | INFO | No malware/telemetry/obfuscation | FIXED | re-scan after changes | dangerous-function/sink/egress greps: zero hits; one curl_init (SafeHttpClient); no dynamic includes beyond autoloader/config loader | Static review only. |
| INFO-001 | INFO | Sitemap <loc> escaping | FIXED | SitemapController::buildXml (ENT_XML1, also changefreq/priority) | 06 | - |
| INFO-002 | INFO | Parameter binding | FIXED | all queries; Database typed binds | 07 + review | - |
| INFO-003 | INFO | LIMIT binding with native prepares | FIXED | Database::query binds PHP ints as PARAM_INT | 07: LIMIT/OFFSET test; 04 pagination | - |

## 5. VALIDATION RESULTS

| Check | Result |
|---|---|
| `php -l` on every PHP file (45 files) | PHP 8.1: 0 failures · PHP 8.3: 0 failures |
| PHP test suite `tests/run.php` (Docker, disposable MariaDB 10.11) | PHP 8.1: **292 pass / 0 fail** · PHP 8.3: **292 pass / 0 fail** |
| JS tests (`node --test`, jsdom 26) | **12 pass / 0 fail** |
| `node --check` on app.js and test JS | OK |
| Migrations against MariaDB incl. v1.0 legacy adoption + de-duplication | pass (suite 03) |
| Secret scan (`AIza…`, `GOCSPX-…`, PEM keys) over the tree | 0 hits |
| Dangerous-function / JS-sink scan (eval, exec, shell_exec, system, passthru, proc_open, popen, base64_decode, gzinflate, str_rot13, unserialize, create_function, fsockopen, stream_socket_client, innerHTML, outerHTML, insertAdjacentHTML, document.write, sendBeacon, WebSocket) in `core modules public bin config` | 0 hits |
| Outbound enumeration | exactly one `curl_init` (inside `SafeHttpClient`); no `file_get_contents`/`fopen`/`getimagesize` on URLs; literal URLs: Gemini endpoint (config default), Nominatim URL, XML/schema.org strings (never fetched) |
| Dynamic includes | only the autoloader and the config-file loader (paths not user-controlled) |
| TLS verification | `VERIFYPEER=true`, `VERIFYHOST=2`; no `false` anywhere |
| Route table review | 46 routes; 2 public (`GET /api/health`, `POST /api/auth/login`), 44 require a session; test iterates the real table |
| SQL review | every statement reviewed against `001`–`003` (generated hash columns, unique keys, `is_sample`, `source`); executed by the suites on real MariaDB |
| Browser E2E (Playwright, Apache 2.4 + PHP 8.3 in Docker, localhost only) | Root deployment `http://localhost:8081/` and sub-path `http://localhost:8082/seo/` (Alias): login failure message, login, every view loaded, SEO + competitor analysis of a local fixture (dev CIDR exemption), hostile fixture page rendered as text (0 injected nodes), Content AI templates banner, add location, bulk image analysis, sitemap crawl, 404 add/delete, redirect rule add, analytics view; console showed no CSP violations (only a favicon 404, fixed afterwards) |
| `git diff` review | route/response shapes the JS depends on re-checked by the E2E; no files missing after the `public/` move |

**Not run / limits**: no live Gemini or Nominatim call (rule 7), so the real Gemini request shape/model name is only verified against a mocked transport and Google's documentation; no real-internet SSRF test (the guard was tested with scripted DNS plus a real curl transport against a local server); Apache symlinked sub-path deployment failed in my container with `AH00037` (Alias works); the E2E was driven through Playwright scripts, not a permanent browser test suite; PHP 8.0, MySQL (non-Maria), nginx and Windows were not exercised.

## 6. NETWORK / PRIVACY STATUS

See `docs/NETWORK_AND_PRIVACY.md`. Remaining destinations: (1) websites entered by a logged-in user (analysis, images, sitemap crawl, robots.txt) — GET only, server IP and `RankMathWebapp-SEO/1.0 (+contact)` visible, private ranges always blocked, narrowable via `RMW_FETCH_ALLOW_HOSTS`; (2) `generativelanguage.googleapis.com` only when a key is configured — prompt text only, key in a header; (3) `nominatim.openstreetmap.org` only when `RMW_ENABLE_GEOCODING=true` — address text, ≤1 req/s. No telemetry, CDNs or third-party scripts.

## 7. USER ACTIONS REQUIRED

1. **Revoke/rotate the exposed Google API key** (`AIzaSyAP…<REDACTED>`, formerly in `docs/API_CONFIGURATION.md` line ~173) in Google AI Studio / Cloud Console, and scan history with gitleaks/trufflehog. The key remains in every commit before `1b8c068` (including `4f0b58d` on this branch) and in the public upstream repository; pushing this branch publishes it again. A history rewrite was **not** performed (needs your explicit approval; rotation matters more).
2. Create a least-privilege DB user and give the installer/migration step a separate DDL-capable account (`docs/DEPLOYMENT_GUIDE.md`).
3. Run `php bin/install.php …` to create the first admin (nothing is created automatically).
4. Set the web server document root to `public/`, configure HTTPS, `RMW_BASE_URL`, `RMW_ALLOWED_HOSTS` (and `RMW_HSTS=true`).
5. Optional: supply a Gemini key and confirm the model name against Google's current list.
6. Decide on a LICENSE and review the use of the "RankMath" name (no legal assessment made; none added).
7. Tell me if you want the history rewrite, a 404 ingest endpoint, redirect enforcement, or GSC OAuth built.

## 8. REMAINING RISKS / LIMITATIONS

* Single role: every account is a full administrator; no MFA, password reset, audit log or account management UI (create more users with SQL/CLI).
* The SSRF guard validates DNS answers from the host's resolver and pins them for the connection; a compromised resolver, or private ranges reachable through public IPs (e.g. SSRF into a public host that proxies inward), are out of scope. Add egress firewall rules.
* Analysis, crawl and image jobs run synchronously inside web requests (bounded: 15 s/request, 2 min crawl, 50 images); rate limits are per minute and DB-backed, not CPU/memory quotas.
* `Options -Indexes` in `.htaccess` requires `AllowOverride Options`; otherwise Apache returns 500 — use the vhost directive instead.
* Features relabelled rather than implemented: redirect enforcement, 404 capture, GSC, location hours/edit UI.
* The default Gemini model name is from Google's documentation of 2026-11-15 and may be retired; the endpoint path/header were not displayed on the fetched pages and are untested against the live API.
* **Reverse proxies**: login throttling and rate limits key on `REMOTE_ADDR`, and the same-origin check compares `Origin` with the `Host` header. Behind a proxy all users share one IP (the 30-per-15-minute IP login limit becomes a global lockout) and a proxy that rewrites `Host` makes every POST fail with 403. The proxy must preserve `Host`; trusted-proxy handling (`X-Forwarded-For`) is **not implemented**.
* The anonymous `/api/health` endpoint and the login page are publicly reachable by design.
* Existing databases lose the unused `projects`, `schema`, `settings`, `analytics_pages` tables (migration 003); `project_id` NULLs become 0; non-general sitemap rows become general; duplicate rows are collapsed before unique keys are added — back up first.
* Licence/name: the project uses the "RankMath" name and has no LICENSE file.

## 9. DEPLOYMENT VERDICT

**SAFE FOR STAGING** (behind HTTPS with `RMW_BASE_URL`/`RMW_ALLOWED_HOSTS` set and the exposed key rotated).

Evidence: all three Critical and all High code findings are fixed and covered by tests that ran (292 PHP + 12 JS, two PHP versions, real MariaDB, real curl against a local server, real Apache + browser). Not `READY FOR PRODUCTION`: `SEC-003` is open until the owner rotates the key; Gemini/Nominatim and internet-facing behaviour were never exercised live; auth is single-role without MFA; and several medium items are mitigated rather than eliminated.

## Sources

* Gemini model status: [Google AI Studio models page](https://aistudio.google.com/docs/models) and [deprecations page](https://aistudio.google.com/docs/deprecations) (fetched 2026-11-15; the ai.google.dev URLs redirect there).
