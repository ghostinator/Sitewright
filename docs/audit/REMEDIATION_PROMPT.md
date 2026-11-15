# REMEDIATION PROMPT — RankMath-SEO-Webapp

You are a fresh Claude Opus 5.5 coding session. Your job is to **actually modify the code** of the RankMath-SEO-Webapp repository and resolve **every** finding from the completed audit (`AUDIT_REPORT.md`, supplied alongside this prompt), and then prove it.

You were not the auditor. Treat the audit as strong evidence, not gospel: re-verify each finding in the code before fixing it. If a finding turns out to be wrong, record it as `NOT APPLICABLE AFTER INVESTIGATION` with evidence. Don't silently skip it.

---

## 0. Ground rules

1. **Treat the code as authority, not the docs.** The repository's own docs (README, PROJECT_SUMMARY, FINAL_AUDIT_REPORT, REAL_AI_SETUP, FIXES_APPLIED, API_ERROR_FIX, docs/*, webapp/AUTO_DETECTION.md) contain many false claims. Don't rely on them.
2. **Prefer surgical, reviewable changes.** Don't rewrite the app from scratch. This is a small PHP 8 + vanilla JS app with one router, eight controllers and a PDO wrapper — fix it in place. The one structural change the audit supports is **consolidating the two competing front-end trees into a single public document root** (ARCH-001).
3. **No fake functionality.** Never replace missing features with random numbers, fabricated analytics, pretend AI, templated "research" presented as data, or hardcoded success responses. For every advertised feature, choose one: **(A)** implement it correctly; **(B)** clearly mark it unavailable/experimental/template-based in UI, API (`source`/`powered_by` fields) and docs; **(C)** remove the claim.
4. **Network minimalism.** Keep only outbound traffic that a feature needs. Every outbound capability must go through the central guarded HTTP client (§3) and be documented.
5. **Secrets.** Never write a real secret into any file. The audit found an apparently real Google API key in `docs/API_CONFIGURATION.md` (~line 173, begins `AIzaSyAP…`). Remove it. **Do not rotate or test it yourself** — list it under *User actions required*.
6. **No destructive Git operations** (`git reset --hard`, `git clean -fd`, force push, history rewriting) unless the user explicitly authorizes them. If history scrubbing is needed for the key, recommend it to the user; don't do it.
7. **No live third-party calls during testing** (Gemini, Nominatim, arbitrary websites). Use local fixtures, a local HTTP test server, and mocks/injection seams.
8. **Do the work rather than handing it back.** Only items that genuinely need the user (credential rotation, production hostnames, real DB credentials, OAuth client registration, HTTPS certs) go to *User actions required*.

---

## 1. Startup procedure

1. `git status`, `git branch --show-current`, `git log -1 --oneline`. Record them. If there are uncommitted changes, **don't discard them**: commit them on a `wip/pre-remediation-<date>` branch or stash with a clear message, and report what you did.
2. Create a working branch, e.g. `remediation/audit-2026-11`. Tag or record the starting commit as a checkpoint.
3. Read `AUDIT_REPORT.md` fully, then every file you'll change. The repo has 43 files. The backend lives in `core/`, `modules/`, `config/`, `api.php`. There are two front ends: the root (`index.php`, `assets/`) and `webapp/` (`webapp/index.php`, `webapp/assets/js/app.js`, `webapp/config/config.php`, `webapp/core/Router.php`, `webapp/api.php`, `webapp/.htaccess`).
4. Check tooling: `php -v` (target **PHP ≥ 8.1**), `composer` (optional, dev-only for PHPUnit), `node`, `mysql`/`mariadb` or Docker. If PHP isn't available, say so and stop claiming runtime verification. Static checks only, clearly labelled.
5. Make **logically grouped commits** (one concern per commit, message referencing finding IDs) so each change can be reviewed or reverted independently.

---

## 2. Target architecture (keep it simple)

- **Single document root `public/`** containing only: `index.php` (SPA shell), `api.php` (front controller), `assets/css/style.css`, `assets/js/app.js`, `.htaccess`. Everything else (`src/` or the existing `core/` + `modules/`, `config/`, `database/`, `docs/`, `tests/`, `bin/`) lives **outside** the web root. If you must keep the current layout for compatibility, add deny rules for everything except those files — but the move is preferred (fixes SEC-010, ARCH-001, ARCH-004).
- **Pick one front end.** Base it on the `webapp/` version (runtime path detection, history routing), but restore the stylesheet, fix routing, and delete the other tree. Remove every hardcoded `/rankmath` and `/rankmath/webapp` path (root `core/Router.php`, root `assets/js/app.js`, `.htaccess` files, `test-api.html`, installer-generated `.htaccess`).
- **Configuration:** `config/config.example.php` (committed, no secrets) plus `config/config.php` (git-ignored) **and/or** environment variables (`RMW_DB_HOST`, `RMW_DB_PORT`, `RMW_DB_NAME`, `RMW_DB_USER`, `RMW_DB_PASS`, `RMW_DB_PREFIX`, `RMW_GEMINI_API_KEY`, `RMW_GEMINI_MODEL`, `RMW_BASE_URL`, `RMW_ALLOWED_HOSTS`, `RMW_ENABLE_GEOCODING`, `RMW_DEBUG`). Load it once in a bootstrap file used by `api.php` and `index.php`. `config/app.php`'s dead settings (ARCH-002) must either be enforced (debug, session, rate limit, feature flags) or deleted.
- **Bootstrap** sets `display_errors=0` unless debug, registers a global exception/error handler returning generic JSON (`{"success":false,"message":"Internal error","request_id":…}`), logs details server-side, and sets security headers.

---

## 3. Central outbound HTTP client (required for SSRF-001/002/003, DOS-001, PRIV-001, PRIV-002, SEC-007)

Create **one** class (e.g. `core/SafeHttpClient.php`) used by **every** outbound request: SEO analysis, image analysis, bulk image analysis, sitemap crawl, Gemini, Nominatim. Delete each controller's private `fetchUrl`/`fetchPage`/`getImageInfo` curl code. Never call `getimagesize()`, `file_get_contents()` or `fopen()` on URLs.

Requirements:
- Parse with `parse_url`; **scheme allow-list `http`/`https` only**; reject embedded credentials (`user:pass@`), empty hosts, malformed URLs, control characters, and overlong URLs (> 2048).
- Resolve the hostname (`dns_get_record` A/AAAA, or `gethostbynamel` plus AAAA). Reject if **any** resolved address is: loopback (127.0.0.0/8, ::1), unspecified (0.0.0.0/8, ::), RFC1918 (10/8, 172.16/12, 192.168/16), CGNAT 100.64/10, link-local (169.254/16, fe80::/10) including **169.254.169.254** and `fd00:ec2::254`, unique-local fc00::/7, multicast, broadcast, reserved/documentation/benchmark ranges (192.0.0/24, 192.0.2/24, 198.18/15, 198.51.100/24, 203.0.113/24, 240/4), IPv4-mapped/compatible IPv6 (`::ffff:0:0/96`) whose embedded v4 is disallowed, NAT64 `64:ff9b::/96` mapping to disallowed v4, and 6to4/Teredo embedding disallowed v4. Use `filter_var(FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)` **plus** explicit CIDR checks (the flags alone are incomplete). Handle decimal/octal/hex/short-form IPv4 by relying on the resolver result, never on string matching.
- **Pin the connection** to the validated IP with `CURLOPT_RESOLVE` (`host:port:ip`) to defeat DNS rebinding.
- `CURLOPT_PROTOCOLS_STR`/`CURLOPT_PROTOCOLS` and `CURLOPT_REDIR_PROTOCOLS*` = http, https.
- `CURLOPT_FOLLOWLOCATION = false`; follow redirects **manually**, up to 5, re-running the full validation (scheme, DNS, IP, pin) on each `Location` and resolving relative `Location` values against the current URL.
- Timeouts: `CURLOPT_CONNECTTIMEOUT` ≈ 5 s, `CURLOPT_TIMEOUT` ≈ 15 s (configurable), plus a per-operation total budget for crawls.
- **Max response size** (e.g. 5 MB HTML, 10 MB images), enforced while streaming with `CURLOPT_WRITEFUNCTION` (abort past the limit) **and** via `CURLOPT_MAXFILESIZE`.
- `CURLOPT_SSL_VERIFYPEER = true`, `CURLOPT_SSL_VERIFYHOST = 2` (fixes SEC-007).
- Configurable, honest User-Agent including a contact URL/email from config.
- Ports: default allow 80/443 only, configurable allow-list.
- Optional operator configuration `RMW_ALLOWED_HOSTS` / `RMW_BLOCKED_HOSTS`.
- Returns a structured result (`final_url`, `status`, `headers`, `body`, `bytes`, `elapsed_ms`, `redirects`) or throws typed exceptions mapped to clean 4xx JSON errors (no internal details).
- Provide a **test seam** (injectable resolver and transport) so tests can simulate DNS answers and redirects without network access.

Then:
- **SeoAnalysisController (SSRF-001, FUNC-008, DOS-001):** fetch **once**, reuse the body and timing for all checks; return a clear error (no score, no DB row) on failure; compute HTTPS from the final effective URL; strip `<script>`/`<style>` before word counts and use a Unicode-aware count; label heuristic checks honestly ("viewport meta present" instead of "mobile friendly"); remove PHP-warning-producing undefined-index access.
- **ImageSeoController (SSRF-002, FUNC-012, DOS-001):** fetch image bytes through the client with a size cap, then `getimagesizefromstring()`; cap images per bulk run (e.g. 50) and total time; resolve relative/protocol-relative/`../` URLs with one shared RFC 3986 resolver (also used by the sitemap); unknown size ⇒ `optimized = null/unknown`, never `true`; consider `srcset`/`data-src` or document the limitation.
- **SitemapController (SSRF-003, FUNC-011, DOS-001):** validate `base_url` with the client; hard server-side cap on `max_pages` (≤ 500) regardless of client input; total time budget; skip non-http(s) hrefs (`mailto:`, `tel:`, `javascript:`, `data:`); strip fragments; normalize; use associative arrays for visited/queued sets; include only HTTP 200 HTML pages; optional robots.txt respect and politeness delay; upsert to avoid duplicates (DB-001).
- **ContentAi Gemini call (PRIV-002, FUNC-001):** send the key in the `x-goog-api-key` header, not the URL; never log response bodies that could contain request data — log status and error code only.
- **LocalSeo geocoding (PRIV-001):** off by default (`RMW_ENABLE_GEOCODING=false`); when enabled use the client with timeout, identifying UA plus contact, simple cache, ≤ 1 request/second; document exactly what is sent to OpenStreetMap.

---

## 4. Findings to resolve (every ID must appear in your final ledger)

### P0 — Security / trust blockers

- **SEC-001 (CRITICAL) — Installer RCE / re-run.** `install-handler.php` writes unescaped `$_POST` values (`db_host`, `db_port` unquoted, `db_name`, `db_user`, `db_pass`, `db_prefix`) into PHP source at `config/database.php`, is always reachable, also overwrites `.htaccess`, and injects `db_name`/`db_prefix` into SQL. Fix:
  - Make installation **CLI-first** (`bin/install.php`). If a web installer is kept, require a one-time setup token from a file created at deploy time, and refuse to run if config or a lock file exists (lock stored outside the web root).
  - Strictly validate inputs: hostname/IP regex; port integer 1–65535; db name and prefix `^[A-Za-z0-9_]{1,64}$` / `^[A-Za-z0-9_]{0,16}$`.
  - Generate config with `var_export()` (or write env vars), never string interpolation; write with `0640`/`0600` permissions.
  - Stop generating or overwriting `.htaccess`.
  - Make the schema idempotent (see FUNC-014).
  - Tests: malicious port/password/name/prefix payloads are rejected; the second run is refused; generated config parses and contains no executable injection.
- **AUTH-001 (CRITICAL) — No authentication.** Add session-based auth for the whole app:
  - Use the existing `rm_users` table. `password_hash`/`password_verify`.
  - Create the first admin via the CLI installer (prompted, never defaulted).
  - Login/logout endpoints and a login view.
  - `session_regenerate_id(true)` on login; cookies `HttpOnly`, `SameSite=Lax` (or Strict), `Secure` when HTTPS; idle and absolute timeouts from config.
  - Login throttling.
  - A middleware in the router that **denies by default**; allow-list only `POST /api/auth/login` and a minimal `GET /api/health` that reveals nothing sensitive.
  - Authorization: every state-changing or resource-consuming route requires an authenticated admin (single-role is acceptable; document it).
  - `index.php` serves the login view when unauthenticated.
  - Tests: every route in the route table returns 401 without a session (iterate the table programmatically so new routes can't be missed).
- **SQLI-001 (CRITICAL) — `MonitorController::getLogs`.** Allow-list `order_by` ∈ {`last_accessed`,`hits`,`created_at`,`uri`,`id`} and `order` ∈ {`ASC`,`DESC`}; clamp `page` ≥ 1 and `per_page` 1–100 as ints; bind LIMIT/OFFSET with `PDO::PARAM_INT`. Also fixes **FUNC-016** (TypeError / division by zero). Grep the codebase for any other SQL built from request data (including `Database::update` `$where` and installer DDL) and fix it. Tests: injection payloads in each parameter return 400 or are ignored, and never alter the SQL.
- **SEC-003 (HIGH) — Exposed API key.** Replace the value in `docs/API_CONFIGURATION.md` (~L173) with an obvious placeholder (`YOUR_GEMINI_API_KEY`). Search the whole tree for `AIza[0-9A-Za-z_\-]{35}` and other secret patterns. Add the key to *User actions required* (rotate/delete in Google AI Studio / Cloud Console; scan Git history with gitleaks/trufflehog; consider history rewrite only with explicit user approval). Never print the full value.
- **CSRF-001 (HIGH) — Wildcard CORS / CSRF.** Remove `Access-Control-Allow-Origin: *` and the OPTIONS shortcut (same-origin app). If cross-origin access is genuinely needed, use an explicit configured allow-list without credentials. Require a CSRF token (per session, sent in an `X-CSRF-Token` header and validated for all non-GET requests) and reject state-changing requests whose `Content-Type` isn't `application/json` (except authenticated CSV upload with token). Validate `Host` against `RMW_ALLOWED_HOSTS`/`RMW_BASE_URL` (mitigates DNS rebinding). Tests: missing or invalid token → 403; foreign `Origin` → rejected; foreign `Host` → rejected.
- **SSRF-001 / SSRF-002 / SSRF-003 (HIGH)** — implement §3. Regression tests (all offline via the resolver/transport seam):
  - Each blocked range: 127.0.0.1, localhost, ::1, 10.x, 172.16.x, 192.168.x, 169.254.169.254, fd00::, fe80::, ::ffff:127.0.0.1, 0.0.0.0, 100.64.x.
  - Decimal/octal/hex IPv4 forms (e.g. `2130706433`, `0177.0.0.1`, `0x7f.1`).
  - `file:///etc/passwd`, `gopher://`, `dict://`, `ftp://`, `php://`, `phar://`.
  - `http://user:pass@host`.
  - A public host that resolves to a private IP.
  - A redirect from a public URL to `http://127.0.0.1/` and to `file://`.
  - Redirect-limit exceeded; oversize body aborted; timeout honored.
  - Bulk image page whose `<img src>` points at internal addresses — those are skipped.
  - Crawler `max_pages=1000000` gets clamped.
- **SEC-004 (MEDIUM) — Info-disclosure utilities.** Delete `phpinfo.php` and `check.php` from the deployable tree (optionally keep a CLI-only `bin/check-env.php`). The installer's requirement check must not be publicly reachable after install.

### P1 — Internet-exposure blockers

- **XSS-001 / XSS-002 (HIGH) — DOM XSS sinks.** In the consolidated `app.js`:
  - Remove every `innerHTML` assignment that interpolates data. Build DOM nodes with `textContent`, or use a single audited `escapeHtml()` for unavoidable templates.
  - Replace inline `onclick="…(${id})"` with delegated listeners reading `data-id`.
  - Content AI copy button must copy from a JS variable, never an inline string.
  - Cover: SEO/Competitor results (title, meta description, headings), Image SEO `src`, Content AI output, 404 `uri`/timestamps, redirection fields, location fields, analytics keyword, dashboard recent activity, `install.php` message rendering.
  - Delete `test-api.html` (or move it to `dev/` outside the web root and fix its sinks).
  - Add a CSP (see SEC-009) with no `unsafe-inline` for scripts — move the inline config script into a `data-` attribute or a JSON `<script type="application/json">` read by `app.js`.
  - Tests: a JS unit test (Node + jsdom if available, otherwise a documented manual test with payloads) feeding `<img src=x onerror=alert(1)>` and `"';alert(1);//` through every renderer and asserting no element or attribute injection.
- **DOS-001 (MEDIUM) — Resource exhaustion.** Client timeouts and size caps (§3); a single fetch per analysis; caps on bulk image count, crawl pages and `importGSC`/CSV row counts (e.g. 5,000) with transactions; request body size limit; per-session/IP rate limiting for expensive endpoints (analysis, bulk image, crawl, content generation, geocoding) using a small DB- or file-backed limiter; `set_time_limit` budgets. Tests for the caps.
- **SEC-005 (LOW) — Sample-data generator.** Move `generate-sample-data.php` to `bin/`, guard with `PHP_SAPI === 'cli'`, and mark generated rows as sample (e.g. `project_id` of a "Sample" project or a `is_sample` flag) so the UI/API can label them (FUNC-009).
- **SEC-006 (MEDIUM) — Config hygiene.** Real configs out of Git (`.gitignore`: `config/config.php`, `config/database.php`, `config/api-keys.php`, `.env*`, `*.log`, `storage/`). Ship `*.example` files with empty values. `git rm --cached` the real config files (keep local copies). Document least-privilege DB grants.
- **SEC-007 (LOW) — TLS verification off.** Covered by §3; verify that no `CURLOPT_SSL_VERIFYPEER => false` remains.
- **SEC-008 (MEDIUM) — Error leakage.** Global handler (§2); `Database` throws a typed exception instead of `die()`; the installer and `importCSV` return generic messages (details only in server log); delete the no-op middleware in `api.php`.
- **SEC-009 (LOW) — Headers.** In `public/.htaccess` (and documented for nginx): `Options -Indexes`; `Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'`; `X-Content-Type-Options: nosniff`; `Referrer-Policy: strict-origin-when-cross-origin`; `Permissions-Policy` minimal; drop `X-XSS-Protection` (or set `0`); HSTS only when HTTPS is configured. Set equivalent headers from PHP for API responses. Fix the DEPLOYMENT_GUIDE vhost (`Options -Indexes`, DocumentRoot = `public/`).
- **SEC-010 (MEDIUM) — Web-served non-PHP files.** Solved by the `public/` move; otherwise deny `*.md`, `*.sql`, `docs/`, `database/`, `config/`, dotfiles.
- **SEC-011 (LOW) — Host/SCRIPT_NAME reflection.** Use the configured base URL; when injecting JSON into HTML use `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT`; escape `BASE_PATH` with `htmlspecialchars`.
- **SEC-012 (LOW) — CSV formula injection.** In `exportCSV` (and any new export), prefix cells starting with `=`, `+`, `-`, `@`, tab or CR with `'`. Test it.
- **SEC-013 (LOW) — Prompt injection.** Cap keyword length (e.g. 200 chars), strip control characters, delimit user input in the prompt; output is already treated as untrusted text after the XSS fix.
- **PRIV-001 (MEDIUM)** — geocoding opt-in plus documentation (§3).
- **PRIV-002 (LOW)** — Gemini key in a header; no body logging (§3).
- **PRIV-003 (LOW)** — 404 data retention: configurable retention (e.g. 90 days) with a purge on write or via CLI. Document what personal data (IP, UA, referer) is stored and that analysis requests identify the server to target sites.

### P2 — Functional correctness

- **FUNC-001 (HIGH) — Content AI provenance.** Make the model configurable (`RMW_GEMINI_MODEL`) with a current, supported default. Verify the model name against Google's current Gemini model list at implementation time; `gemini-2.0-flash-exp` is shut down. Set `powered_by`, `credits_used` and a `source` field from the path **actually taken**. On API failure, return an explicit error (or a template result clearly flagged `source: "template_fallback"`, `ai_error: "<code>"`). Show provenance in the UI. Add a lightweight `GET /api/content-ai/status` (auth-required) that reports configured/not configured without calling Google. Tests with a mocked transport covering 200, 4xx, 5xx and malformed JSON.
- **FUNC-002 (MEDIUM)** — Declare PHP ≥ 8.1 in README, installer check and (if added) `composer.json` `require.php`. Keep `match`.
- **FUNC-003 (MEDIUM) — `searchNearby`.** Use distinct placeholders (`:lat1`, `:lat2`, `:lng`); validate lat ∈ [-90,90], lng ∈ [-180,180], radius ∈ (0, 500]; exclude rows with NULL coordinates. Test.
- **FUNC-004 (MEDIUM) — Redirections never enforced.** Either implement enforcement in the front controller (for requests to the managed site — only meaningful if this app fronts that site), with an **open-redirect guard** (relative targets or an allow-listed host set), correct 301/302/307/308/410/451 semantics and atomic hit counting; **or** relabel the feature in the UI and docs as "Redirect rule manager (export to your web server)" and add an export (Apache/nginx/CSV). Choose the honest option that fits the architecture and document why.
- **FUNC-005 (MEDIUM) — 404 Monitor.** Either implement capture (e.g. a tiny ingest endpoint authenticated by a per-site ingest token, used by the monitored site, with server-derived IP/UA where applicable) or relabel as "404 log import/manual." Fix dedupe with null-safe comparison (`project_id <=> :project_id` or an `IS NULL` branch) and an atomic upsert. Make the SPA catch-all return real 404s for non-app paths.
- **FUNC-006 (MEDIUM) — Clear logs.** Read JSON input; use `DELETE … WHERE` (no `TRUNCATE`); honor `project_id`; require confirmation token/CSRF.
- **FUNC-007 (MEDIUM) — Front-end error handling.** Use one `apiRequest()` for every call (it already exists but is unused). Check `response.ok` and `success`; display server error messages via `textContent`; disable submit buttons while pending; never show success toasts on failure; handle 401 by showing the login view; send the CSRF header.
- **FUNC-008 (MEDIUM)** — see §3 SEO analysis items.
- **FUNC-009 (MEDIUM) — Keyword research honesty.** Return only data that exists, with an explicit per-field `source`. Rename `search_volume` → `impressions` (it's summed impressions, not search volume). Move templated related/long-tail/questions into a separate `suggestion_templates` field labelled as such. Label sample data as sample. Add UI only if you implement it honestly; otherwise document it as API-only.
- **FUNC-010 (MEDIUM) — Pretend AI.** `rewriteContent` and `getSuggestions`: implement via the Gemini client (with provenance) or relabel as templates in API/UI/docs. Remove "AI-powered" wording from `suggestAltText` unless real AI is used; never append fake markers like "(Simplified version)".
- **FUNC-011 (MEDIUM) — Sitemap correctness.** §3 items, plus: either generate valid News/Video/Image sitemap extension elements from stored metadata (requires schema fields) or remove those types from UI, docs, schema enum and installer text.
- **FUNC-012 (LOW)** — §3 Image SEO items.
- **FUNC-013 (= DOC-003)** — Google Search Console/OAuth/SEMrush: remove claims and unused config. Implementing GSC OAuth is **out of scope unless the user asks**; if you keep `import-gsc`, rename and document it as "import keyword data (JSON)", validate each item (keyword length, ISO date, numeric ranges), cap row count, and use a transaction.
- **FUNC-014 (MEDIUM) — Installer correctness.** Idempotent schema (`INSERT … ON DUPLICATE KEY UPDATE` for settings); a `schema_migrations` table (DB-003); prefix substitution via a placeholder token (e.g. `{{prefix}}`), not `str_replace('rm_')`; check the `pdo_mysql`, `curl`, `mbstring`, `dom`, `json` extensions; no `.htaccess` writing.
- **FUNC-015 (LOW) — Local SEO.** Either add UI fields (state, postal code, country, email, website, coordinates, hours) and edit support, or document that they're API-only. Validate the `business_hours` shape before `formatBusinessHours`. Validate email/website/phone formats.
- **FUNC-016 (LOW)** — covered by SQLI-001.
- **FUNC-017 (LOW)** — Dashboard SEO score uses `analysis_type='site'` only; render or drop pages/summary/chart data; fix the "Run an SEO analysis" link to open the SEO view.
- **FUNC-018 (LOW)** — covered by XSS-002.
- **FUNC-019 (LOW)** — covered by the global handler (§2/SEC-008).
- **DB-001 (MEDIUM) — Integrity.** Add unique keys (use generated hash columns such as `url_hash CHAR(64)` for long URLs): redirections (`project_id`, `source_hash`); sitemaps (`project_id`, `type`, `url_hash`); `analytics_keywords` (`project_id`, `keyword_hash`, `date`); `404_monitor` (`project_id`, `uri_hash`). Use `INSERT … ON DUPLICATE KEY UPDATE hits = hits + 1` for counters (fixes the race); transactions for bulk imports. Treat `project_id` NULL semantics consistently (consider a default project row). Ship as a migration.
- **DB-004 (LOW)** — Validate enum/decimal inputs (`redirect_type`, `status`, sitemap `type`, `changefreq`, `priority` 0.0–1.0, dates) and return 400 instead of 500.
- **INFO-003** — Bind every LIMIT/OFFSET with `PDO::PARAM_INT` (`SeoAnalysisController::getHistory`, `ContentAiController::getHistory`, `getLogs`).

### P3 — Architecture / cleanup / docs

- **ARCH-001 (MEDIUM)** — consolidate into `public/` (§2). After the move, test that `/`, `/seo-analysis`, `/competitor`, `/analytics`, `/content-ai`, `/local-seo`, `/image-seo`, `/sitemaps`, `/404-monitor` and `/redirections` load **the same** SPA shell with CSS and JS, in both root and subdirectory deployments (use `RewriteBase`-free rules or a documented single setting).
- **ARCH-002 (LOW)** — enforce or delete dead config (`config/app.php`) and unused tables/config (`rm_projects`, `rm_schema`, `rm_settings` beyond install/version, GSC/SEMrush blocks). Keep `rm_users` (now used by auth).
- **ARCH-003 (LOW)** — delete the duplicate `Router.php`/`api.php`/`app.js`/`index.php` copies after consolidation.
- **ARCH-004 (LOW)** — remove dev/test artifacts from the deployable tree (`test-api.html`, `check.php`, `phpinfo.php`, sample-data script → `bin/`/`dev/`).
- **ARCH-005 (LOW)** — `Database::insert/update/delete`: validate column identifiers against `^[a-z_][a-z0-9_]*$` and quote with backticks; replace raw `$where` strings with a structured `['id' => $id]` form, or assert placeholders only.
- **DB-002 (LOW)** — document the least-privilege grant (`SELECT, INSERT, UPDATE, DELETE` at runtime; `CREATE, ALTER, INDEX` only for migrations) and make sure runtime needs nothing more (no `TRUNCATE`).
- **DB-003 (LOW)** — migration mechanism (see FUNC-014).
- **DOC-001 / DOC-002 / DOC-003 / DOC-004 / DOC-005 / DOC-007** — after code changes, rewrite README (and QUICKSTART/DEPLOYMENT) to describe only what exists:
  - actual features and limitations (including which are template-based or experimental);
  - PHP ≥ 8.1 and required extensions;
  - authentication and first-admin creation;
  - CLI install;
  - config and env vars;
  - **a "Network & privacy" section listing every outbound destination** (target sites the operator enters; Gemini only if configured — what's sent; Nominatim only if enabled — what's sent) with how to disable each;
  - security headers;
  - least-privilege DB;
  - HTTPS requirement for production;
  - a deployment layout with `public/` as document root;
  - nginx equivalent rules.
  Delete or move to `docs/history/` (with a "historical, inaccurate" banner): `FINAL_AUDIT_REPORT.md`, `FIXES_APPLIED.md`, `API_ERROR_FIX.md`, `PROJECT_SUMMARY.md`, `REAL_AI_SETUP.md`, `webapp/AUTO_DETECTION.md` — or rewrite them accurately. Remove "production ready", "all RankMath Pro features", "no license required", "OAuth already implemented", "directory listing disabled", "AI-powered alt text" and similar unless now true. Fix the unsafe guidance (root user, `Options Indexes`, `chmod -R 755`, CLI passwords, `GRANT ALL`).
- **DOC-006 (INFO)** — Don't make legal determinations. Add a neutral note to *Remaining risks* that the project uses the "RankMath" name and has no LICENSE file, so the owner should choose a license and review naming. Don't add a license yourself unless the user asks.
- **SEC-003 docs** — also remove the key from any other doc copy.

### Informational (verify, record, no code change expected)

- **MAL-001** — re-run the suspicious-code scan after changes; confirm no telemetry or obfuscation was introduced.
- **INFO-001** — keep `htmlspecialchars` on sitemap `<loc>`; also escape `changefreq`/`priority` after validation.
- **INFO-002** — keep parameter binding everywhere; extend it to all new code.

---

## 5. Tests and validation

Set up a minimal test harness. PHPUnit via Composer dev-dependency is preferred; if Composer is unavailable, use a plain-PHP test runner in `tests/run.php` that exits non-zero on failure. Use a disposable MySQL/MariaDB (Docker or local) with synthetic credentials, or SQLite only where SQL is portable. Clearly state anything you couldn't run.

Required test areas, at minimum:
- SSRF guard (all cases in P0 above), redirect revalidation, size and timeout limits — offline via seams.
- Auth: every route 401 without a session; login success/failure/throttle; session regeneration; logout.
- CSRF: non-GET without a valid token → 403; foreign Origin/Host rejected.
- SQLi: `getLogs` parameter payloads; identifier validation in `Database`.
- XSS: renderer escaping tests (jsdom) or documented manual payload tests.
- Installer: payload rejection, lock behavior, idempotent schema.
- Input validation: enums, coordinates, priority, dates, URL fields, size caps.
- Content AI provenance with a mocked transport.
- `searchNearby` returns results (FUNC-003).
- CSV export formula neutralization.
- 404 dedupe/upsert and hit counting.

Then run the **post-fix validation pass** and report each result:
1. `php -l` on **every** PHP file (`find . -name '*.php' -print0 | xargs -0 -n1 php -l`).
2. `node --check` on all JS; validate any JSON/YAML.
3. Inspect every changed SQL statement and the migration against the schema; verify every PHP query references existing columns.
4. Run all tests (existing and new).
5. Repeat the secret scan (`rg -n 'AIza[0-9A-Za-z_\-]{35}|GOCSPX-[0-9A-Za-z_\-]{10,}|-----BEGIN'`), redacting any output.
6. Repeat the suspicious-code scan (`eval`, `assert`, `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen`, backticks, `base64_decode`, `gzinflate`, `str_rot13`, `unserialize`, `create_function`, dynamic include, `fsockopen`, `stream_socket_client`, `sendBeacon`, `WebSocket`, `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `document.write`).
7. Repeat outbound enumeration: `rg -n 'curl_init|curl_setopt|file_get_contents\(|fopen\(|getimagesize\(|fsockopen|stream_socket|https?://'` — every outbound path must go through `SafeHttpClient`.
8. Re-review auth on the route table (print it and mark each route's protection).
9. Re-review SSRF across all callers.
10. `git diff --stat` and a full `git diff` review for regressions (broken routes, changed response shapes the JS depends on, missing files after the `public/` move).
11. If PHP and a web server are available, smoke-test end to end on localhost only: login → each view → each primary action → rendering. Record what you exercised. A feature isn't "fixed" just because PHP lints, HTTP 200 comes back, or the UI loads: **UI → JS → request → route → controller → DB/network → response → render** must work.

---

## 6. Required final output

Finish with these sections, in this order:

1. **REMEDIATION SUMMARY** — what changed and why.
2. **SECURITY STATUS** — `Critical remaining: X / High remaining: X / Medium remaining: X / Low remaining: X`.
3. **FILES CHANGED** — every modified, created, moved and deleted file with its purpose.
4. **FINDING-BY-FINDING REMEDIATION LEDGER** — table with columns `FINDING ID | ORIGINAL SEVERITY | DESCRIPTION | FIX STATUS | FILES CHANGED | TEST / VERIFICATION | RESIDUAL RISK`. Allowed statuses: `FIXED`, `MITIGATED`, `NOT APPLICABLE AFTER INVESTIGATION`, `BLOCKED - REQUIRES USER ACTION`, `NOT FIXED`. **All of these IDs must appear:**
   SEC-001, AUTH-001, SQLI-001, SSRF-001, SSRF-002, SSRF-003, SEC-003, CSRF-001, XSS-001, XSS-002, FUNC-001, DOS-001, SEC-004, SEC-005, SEC-006, SEC-007, SEC-008, SEC-009, SEC-010, SEC-011, SEC-012, SEC-013, PRIV-001, PRIV-002, PRIV-003, FUNC-002, FUNC-003, FUNC-004, FUNC-005, FUNC-006, FUNC-007, FUNC-008, FUNC-009, FUNC-010, FUNC-011, FUNC-012, FUNC-013, FUNC-014, FUNC-015, FUNC-016, FUNC-017, FUNC-018, FUNC-019, ARCH-001, ARCH-002, ARCH-003, ARCH-004, ARCH-005, DB-001, DB-002, DB-003, DB-004, DOC-001, DOC-002, DOC-003, DOC-004, DOC-005, DOC-006, DOC-007, MAL-001, INFO-001, INFO-002, INFO-003.
5. **VALIDATION RESULTS** — each command or test run with its result (pass/fail counts), and anything you couldn't run and why.
6. **NETWORK / PRIVACY STATUS** — every remaining external destination, what data it receives, when, why it exists, and how to disable it.
7. **USER ACTIONS REQUIRED** — only genuine user actions, e.g.:
   - **Rotate/delete the exposed Google API key** (`AIzaSyAP…<REDACTED>`, formerly in `docs/API_CONFIGURATION.md`) and scan/clean Git history (history rewrite only with approval).
   - Provide production DB credentials and create a least-privilege DB user.
   - Create the first admin account via the CLI installer.
   - Supply a current Gemini model name and key (optional).
   - Configure HTTPS and the production hostname (`RMW_BASE_URL`, `RMW_ALLOWED_HOSTS`).
   - Decide on a LICENSE and on the use of the "RankMath" name.
   - Set the web server document root to `public/`.
8. **REMAINING RISKS / LIMITATIONS** — be explicit (e.g., SSRF guard limits, single-role auth, features relabelled rather than implemented, untested environments).
9. **DEPLOYMENT VERDICT** — choose one of `SAFE FOR CONTROLLED LOCAL TESTING` / `SAFE FOR STAGING` / `READY FOR PRODUCTION` / `NOT READY FOR DEPLOYMENT`, with the evidence. Don't claim `READY FOR PRODUCTION` unless every Critical/High finding is FIXED and verified by tests that actually ran.

Do not declare completion while any finding ID above is missing from the ledger, or while tests you claim passed weren't actually executed.
