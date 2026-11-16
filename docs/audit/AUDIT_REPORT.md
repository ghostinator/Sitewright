# RankMath-SEO-Webapp — Whole-Repository Audit Report

**Audit type:** Read-only static source, security, privacy, malware, network-egress, functionality and production-readiness audit
**Subject:** `RankMath-SEO-Webapp-main.txt` (ZIP archive renamed to `.txt`)
**Upstream reference (not trusted as identical):** https://github.com/poofbyte/RankMath-SEO-Webapp
**Remediation performed:** None (Stage 1 only). See `REMEDIATION_PROMPT.md` for Stage 2.

---

## 0. Evidence handling (Phase 0)

| Item | Value |
|---|---|
| Original attachment | `RankMath-SEO-Webapp-main.txt` (93,248 bytes) |
| Detected type | `Zip archive data, v1.0, compression method=store/deflate` |
| SHA-256 of archive | `44f7efdf88dfde6a374972c87f1ca6ccf032f99496dfd6a2c4bc10e117c031b9` |
| ZIP comment | `24209d64f68cf086f92d55546d9dc454be5a89fe` (GitHub "Download ZIP" commit SHA) |
| Entries | 65 (22 directories, 43 files) |
| Path traversal / absolute paths | None |
| Symlinks / hardlinks | None (all external attrs `0`) |
| Nested archives / binaries / executables | None — every file is text (PHP, JS, CSS, HTML, SQL, Markdown, Apache config) |
| Suspiciously large files | None (largest: `webapp/assets/js/app.js`, 24,971 bytes) |
| Git metadata | **Not present** (`.git/` absent; history could not be inspected) |
| Extraction | Copied to `.zip`, inspected with Python `zipfile`, then extracted into an isolated audit directory. No repository code was executed. |

Tooling available: Python 3, Node.js (JS parser), ripgrep. **No PHP interpreter was available**, so `php -l` and dynamic execution were not possible (see §13).

---

## 1. Executive verdict

**Would I run this application on a public server today? → DO NOT DEPLOY.**

Reasons (each independently sufficient):

1. **Unauthenticated remote code execution** through the permanently reachable installer (`install-handler.php`), which writes unescaped POST values into a PHP config file that every API request then executes (SEC-001).
2. **No authentication or authorization anywhere.** All 41 API routes — including deletes, truncation, outbound fetching, crawling and Gemini calls — are open to anyone who can reach the server, and `Access-Control-Allow-Origin: *` lets any website drive them from a visitor's browser (AUTH-001, CSRF-001).
3. **Unauthenticated SQL injection** in `GET /api/404-monitor/logs` via `order_by`, `order`, `per_page` and `page` (SQLI-001).
4. **Unrestricted SSRF** in four features, including `file://` and other non-HTTP schemes, internal addresses, cloud metadata and redirects, with partial response disclosure (SSRF-001..003).
5. **A credential that looks real** (Gemini/Google API key format, full length) published in `docs/API_CONFIGURATION.md` (SEC-003).
6. Stored and third-party-content **XSS** throughout the UI (XSS-001, XSS-002).

The application is suitable, at most, for **isolated single-user local experimentation on a machine whose web server is not reachable from other hosts** — and even there, the CORS/CSRF issues let any web page you visit drive the local instance (including the installer).

---

## 2. Malware / trust verdict

| Question | Verdict | Confidence |
|---|---|---|
| Evidence of malware | **NOT FOUND** | High (small, fully-read, plain-text codebase) |
| Evidence of backdoors (intentional) | **NOT FOUND** — but SEC-001 is a functional, unintentional backdoor-equivalent (unauthenticated RCE) | High |
| Evidence of credential theft | **NOT FOUND** — no code reads credentials and sends them anywhere other than the configured Gemini endpoint | High |
| Evidence of hidden telemetry / analytics / tracking | **NOT FOUND** — no analytics SDKs, beacons, pixels, CDN scripts, WebSockets or `sendBeacon` | High |
| Evidence of unexplained outbound communication | **NOT FOUND** — every outbound call is tied to a visible feature (see §3). One call (Nominatim geocoding) is **undocumented** but not hidden. | High |
| Evidence of obfuscation | **NOT FOUND** — no `eval`, `base64_decode`, `gzinflate`, `str_rot13`, packed/hex payloads, dynamic includes, `create_function`, `unserialize`, shell execution functions or minified JS | High |
| Persistence / cron / self-update / remote code download | **NOT FOUND** | High |

**Conclusion:** I found no evidence of malware, backdoors, credential harvesting, telemetry, or obfuscation in the reviewed repository. That is **"not found," not "proven absent"**: the code ran through no dynamic analysis, Git history was unavailable, and this verdict covers only this archive (commit `24209d64…`), not the upstream repository at any other point in time. The serious risks here come from **insecure design and missing controls**, not hostile intent.

---

## 3. Network / privacy map (Phase 6)

### 3.1 Complete outbound destination catalog

All outbound traffic is **server-side PHP cURL / PHP stream wrappers**. The browser contacts **only the app's own origin** (`API_BASE` + `/api/...`). There are no third-party scripts, fonts, CDNs, images, analytics or iframes in any HTML/CSS/JS file.

| # | Destination | Protocol | Source (file : function) | Trigger | Method | Data sent | Credentials sent | Expected? | Can disable? | Risk |
|---|---|---|---|---|---|---|---|---|---|---|
| N1 | **Any user-supplied URL** (any scheme libcurl supports, incl. `file://`; any host incl. loopback/RFC1918/169.254.169.254) | HTTP(S)/file/others | `modules/SeoAnalysis/SeoAnalysisController.php : fetchUrl()` L429-448 | `POST /api/seo-analysis/analyze` (SEO Analysis & Competitor views, test-api.html) | GET ×**8 per analysis**, follows redirects | URL; UA `RankMath SEO Webapp/1.0`; server's IP; TLS verification **disabled** | None | Expected functional (target site) — but **unrestricted** | No | **SSRF-001** |
| N2 | Any user-supplied image URL | Any curl scheme + **any PHP stream wrapper** via `getimagesize()` | `modules/ImageSeo/ImageSeoController.php : getImageInfo()` L275-303 | `POST /api/image-seo/analyze` | HEAD (curl) + full GET (`getimagesize`) | URL, UA (PHP default) | None | Expected functional, **unrestricted** | No | **SSRF-002** |
| N3 | Any user-supplied page URL **plus every `<img src>` found on it** (second-order: attacker page controls targets) | HTTP(S)/others | `ImageSeoController : bulkAnalyze()` L111-171, `fetchUrl()`, `getImageInfo()` | `POST /api/image-seo/bulk-analyze` (Image SEO view) | GET page; HEAD + GET per image; unbounded count | URLs | None | Expected functional, **unrestricted & unbounded** | No | **SSRF-002 / DOS-001** |
| N4 | Any user-supplied base URL and same-host links, up to client-chosen `max_pages` | HTTP(S)/others | `modules/Sitemap/SitemapController.php : crawlWebsite()/fetchPage()` L186-250 | `POST /api/sitemap/crawl` (Sitemaps view) | GET, follows redirects | URLs; UA `RankMath Sitemap Crawler/1.0` | None | Expected functional, **unrestricted & unbounded** | No | **SSRF-003 / DOS-001** |
| N5 | `generativelanguage.googleapis.com` (host taken from `config/api-keys.php` → `gemini.endpoint`) | HTTPS | `modules/ContentAi/ContentAiController.php : generateWithGemini()` L79-128 | `POST /api/content-ai/generate` **only when `gemini.api_key` is non-empty** | POST | Prompt containing the user's keyword, chosen content type and tone; generation config | **API key in the URL query string** (`?key=…`) | Optional third-party | Yes (leave key empty) | PRIV-002, FUNC-001 |
| N6 | `nominatim.openstreetmap.org` | HTTPS | `modules/LocalSeo/LocalSeoController.php : geocodeAddress()` L274-296 | `POST /api/local-seo/locations` whenever latitude/longitude are not supplied — **always the case from the UI** | GET | Business address, city, state, postal code, country (URL-encoded `q=`); UA `RankMath SEO Webapp`; server IP | None | **Undocumented** third-party egress | **No** (no config switch) | **PRIV-001** |

Static XML namespace URIs in `SitemapController::buildXML()` (`sitemaps.org`, `google.com/schemas/...`) and `https://schema.org` in `LocalSeoController::getSchema()` are **string literals placed in output; they are never fetched.**

`config/api-keys.php` contains `google_search_console` and `semrush` blocks, but **no code reads them** — so no traffic goes to Google Search Console, Google OAuth, or SEMrush.

### 3.2 Traffic classification

| Category | Destinations |
|---|---|
| EXPECTED FUNCTIONAL TRAFFIC | N1–N4 (user-chosen target sites) — legitimate purpose, but implemented as **unrestricted SSRF** |
| OPTIONAL THIRD-PARTY TRAFFIC | N5 (Google Gemini; off unless a key is set) |
| UNDOCUMENTED (but feature-tied) THIRD-PARTY TRAFFIC | N6 (OpenStreetMap Nominatim; always on) |
| UNNECESSARY TELEMETRY | None found |
| SUSPICIOUS TRAFFIC | None found |
| UNEXPLAINED TRAFFIC | None found |

### 3.3 Direct answers

**"If I install this application and never configure optional third-party integrations, what external hosts can it contact?"**
- **`nominatim.openstreetmap.org`** — whenever anyone adds a Local SEO location without coordinates (the UI never sends coordinates, so this happens every time). It receives the business's address and your server's IP.
- **Any host or URL any network user chooses**, through SEO/Competitor Analysis, Image SEO (single and bulk, including image URLs embedded in a fetched page) and the Sitemap crawler. Because none of these endpoints require authentication, *anyone who can reach the app* chooses these destinations — not just you. That includes internal/private hosts, `localhost` services, cloud metadata endpoints and, for SEO Analysis/Image SEO, local files via `file://` (and, for `getimagesize`, other PHP stream wrappers).
- Nothing else. No Google, analytics, update, or "phone-home" traffic happens unless you set a Gemini key.

**"If I configure its intended AI functionality, exactly what information can leave my system, and to whom?"**
- To **Google (`generativelanguage.googleapis.com`)**: your Gemini API key (in the URL query string), and a prompt built from the keyword typed into Content AI plus the content type and tone. No page content, analytics data, database records, server metadata or user identity is included. Every request also sends your server's IP (inherent to any HTTP request).
- **Important:** the configured model `gemini-2.0-flash-exp` has been shut down, according to Google's model page ([Gemini 2.0 Flash](<cite>turn1search6</cite>)). Each request therefore still sends the key and prompt to Google, gets an error, and silently falls back to templates — while the API response still says `powered_by: "Google Gemini AI"` (FUNC-001).
- Because the endpoint is unauthenticated, **anyone can spend your Gemini quota** and send arbitrary text to Google under your key (AUTH-001).

---

## 4. Top security findings (Critical and High)

| ID | Sev | Title |
|---|---|---|
| SEC-001 | **CRITICAL** | Unauthenticated, re-runnable installer → PHP code injection into `config/database.php` → RCE; also full DB re-pointing and `.htaccess` overwrite |
| AUTH-001 | **CRITICAL** | No authentication or authorization on any endpoint (41 API routes, installer, utilities) |
| SQLI-001 | **CRITICAL** | SQL injection in `MonitorController::getLogs()` (`ORDER BY`/`LIMIT`/`OFFSET` built from `$_GET`) |
| SSRF-001 | HIGH | Unrestricted server-side fetch in SEO/Competitor Analysis (any scheme incl. `file://`, internal IPs, metadata, redirects; partial content disclosure; TLS verification disabled) |
| SSRF-002 | HIGH | Image SEO SSRF incl. PHP stream wrappers via `getimagesize()` and second-order SSRF from attacker-controlled `<img src>` |
| SSRF-003 | HIGH | Sitemap crawler SSRF with client-controlled unbounded `max_pages` |
| SEC-003 | HIGH | Apparently real Google/Gemini API key published in `docs/API_CONFIGURATION.md` |
| CSRF-001 | HIGH | `Access-Control-Allow-Origin: *` + no CSRF protection → any website can read/modify data and trigger the installer RCE (including against `localhost` XAMPP installs) |
| XSS-001 | HIGH | Stored XSS: unauthenticated-writable fields rendered via `innerHTML` |
| XSS-002 | HIGH | XSS from third-party content (analyzed page `<title>`/meta, image `src`, AI/template output into inline `onclick`) |
| FUNC-001 | HIGH | Content AI calls a shut-down Gemini model, silently falls back to templates, but labels output "Google Gemini AI" and records `credits_used=1` |

---

## 5. Functionality verdict

The **PHP backend is structurally coherent** (one router, eight controllers, prepared statements for most queries), and several CRUD flows would work on PHP ≥ 8.0 in the right directory layout: dashboard stats, analytics list, 404 log list/delete, redirection list/add/delete, location list/add/delete, SEO analysis, sitemap crawl and XML generation.

However, the application **does not do what its documentation says** in several important ways:

- **Redirections never redirect anything.** No request handler consults the table; the "check" endpoint just returns JSON.
- **The 404 Monitor never detects 404s.** Nothing in the app logs them; the SPA catch-all returns `index.php` for every unknown path; dedupe is broken.
- **Content AI is effectively templates** — the Gemini model is shut down — yet it reports itself as Gemini. "Rewrite" and "Suggestions" are trivial string manipulations (e.g., appending "(Simplified version)").
- **Keyword Research** has no UI. With no data, it returns templated "related/long-tail/questions" next to fields labelled with data sources. Sample-data rows are reported as "Real Data."
- **Google Search Console / OAuth / SEMrush** integration does not exist.
- **News/Video/Image sitemaps** are plain URL lists with extension namespaces only — not valid sitemaps of those types.
- **Local SEO "nearby" search** is broken (reused named PDO placeholders with native prepares).
- **PHP 7.4** (the documented minimum, and what the installer accepts) **fatals the entire API** because of a `match` expression.
- Two competing front-end trees exist. In the auto-detecting `webapp/` tree the stylesheet is missing and deep links route to the wrong `index.php`.

Roughly, **about half the advertised features work end-to-end, with caveats.** The rest are partial, misleading, broken or unimplemented (see §6).

---

## 6. Feature matrix (Phase 14)

| Feature | UI? | Backend? | DB? | External dep | End-to-end | Secure? | Verdict | Evidence |
|---|---|---|---|---|---|---|---|---|
| Dashboard | Yes | `AnalyticsController::getDashboardStats` | Yes | — | Yes (averages competitor scores into "your" score) | No (unauth, XSS via activity) | **LIKELY WORKING** | api.php L56; app.js `loadDashboard` |
| SEO Analysis | Yes | `SeoAnalysisController::analyze` | Yes | Target site | Yes, with flawed metrics; failed fetch still scored | **No** (SSRF-001, XSS-002) | **PARTIAL** | Fetches URL 8×; `load_time` = 7th fetch; HTTPS = string prefix; "mobile" = viewport tag only |
| Competitor Analysis | Yes | Same as SEO (`is_competitor`) | Yes | Target site | Yes (overwrites SEO view's results div) | No | **PARTIAL** | app.js competitor handler copies `#seo-results` |
| Analytics | Yes (keywords only) | `getDashboard`, `addKeyword`, `importGSC` | Yes | — | Lists keywords; pages/summary/chart fetched but not rendered; no data-entry UI | No | **PARTIAL** | app.js `loadAnalytics` |
| Keyword Research | **No** | `ContentAiController::researchKeyword` | Yes | — | API only; templated fallbacks | No | **MISLEADING** | L234-358 |
| Content AI | Yes | `generateContent` (+ `rewrite`, `suggestions`, `history` without UI) | Yes | Gemini (optional) | Templates in practice; mislabelled | No (quota abuse, XSS) | **MISLEADING** | Model `gemini-2.0-flash-exp` shut down ([source](<cite>turn1search6</cite>)); `$isRealAI=true` even on fallback |
| Local SEO | Yes (name/address/city/phone) | CRUD + schema + nearby | Yes | Nominatim (undocumented) | Add/list/delete work; hours/schema/update API only; **nearby broken** | No | **PARTIAL** | `searchNearby` reuses `:lat` (HY093) |
| Image SEO | Yes (bulk) | analyze/bulk/update/suggest/tips | Yes | Target & image hosts | Bulk works with wrong URL resolution; null size → "optimized" | **No** (SSRF-002) | **PARTIAL** | `getBaseUrl` drops port/path |
| Sitemap generation | Yes (crawl + download) | crawl/add/delete/xml | Yes | Target site | General sitemap works; duplicates on re-crawl; news/video/image invalid | **No** (SSRF-003) | **PARTIAL** | `buildXML` emits no `news:`/`video:`/`image:` elements |
| 404 Monitoring | Yes (list/delete/clear) | log/list/delete/clear/export | Yes | — | Never captures real 404s; clear-all ignores project; **SQLi** | **No** | **MISLEADING / INSECURE** | No caller of `log404` in app; `$_POST` vs JSON |
| Redirections | Yes (list/add/delete) | CRUD/check/import | Yes | — | Rules stored, **never enforced** | No | **MISLEADING** | No redirect middleware anywhere |
| Schema functionality | **No** | `getSchema` (LocalBusiness only) | `rm_schema` unused | — | API only | No | **PARTIAL** | Installer says "Schema Markup Generator" |
| Installation | Yes | `install-handler.php` | Creates DB | DB host (any) | Works once; re-run fails on existing DB; always reachable | **No (RCE)** | **BROKEN / INSECURE** | SEC-001, FUNC-014 |
| API health endpoint | test-api.html | Closure in api.php | — | — | Yes | Unauth (benign) | **WORKING** | api.php L126-131 |
| Gemini integration | Indirect | `generateWithGemini` | — | Google | Always errors → fallback | Key in URL | **BROKEN** | FUNC-001 |
| Google Search Console | Claimed | **None** (only JSON import endpoint) | — | — | No OAuth route exists | — | **UNIMPLEMENTED** | No `/api/google/*` route |
| SEMrush | Claimed (docs) | None | — | — | — | — | **UNIMPLEMENTED** | Config key never read |
| CSV import/export (redirects, 404) | **No** | Yes | Yes | — | API only | Formula injection in export | **PARTIAL** | No UI buttons |
| Sample data generator | n/a | `generate-sample-data.php` | Yes | — | Works; web-reachable | No | **WORKING (should not be deployed)** | SEC-005 |
| Users / projects / settings | No | No | Tables only | — | — | — | **UNIMPLEMENTED** | Tables unused |
| Rate limiting / sessions / debug flag | No | No | — | — | `config/app.php` never loaded | — | **UNIMPLEMENTED** | ARCH-002 |

---

## 7. Documentation claims vs reality (Claims matrix)

| # | Claim | Source | Actual implementation | Verdict |
|---|---|---|---|---|
| C1 | "complete, production-ready SEO web application" | PROJECT_SUMMARY.md | Unauth RCE, SQLi, SSRF, XSS; several features fake/broken | **FALSE** |
| C2 | "all RankMath Pro features … no license required" | README, PROJECT_SUMMARY | Small subset, several simulated | **FALSE** |
| C3 | "PDO prepared statements (SQL injection prevention)" | PROJECT_SUMMARY | Mostly true; `MonitorController::getLogs` concatenates `$_GET`; installer concatenates DB name/prefix | **PARTIALLY VERIFIED** |
| C4 | "Input validation" | PROJECT_SUMMARY | Only emptiness checks + one `FILTER_VALIDATE_URL` | **MISLEADING** |
| C5 | "Directory listing disabled", "Sensitive file protection" | PROJECT_SUMMARY | No `Options -Indexes`; `.md`, `.sql`, docs (with key) served; DEPLOYMENT_GUIDE vhost *enables* `Indexes` | **FALSE** |
| C6 | "XSS protection headers" | PROJECT_SUMMARY | Only in `webapp/.htaccess` (deprecated `X-XSS-Protection`); none at root; no CSP | **PARTIALLY VERIFIED / MISLEADING** |
| C7 | "Password hashing ready" | PROJECT_SUMMARY | No auth code at all | **NOT IMPLEMENTED** |
| C8 | "CORS support" | PROJECT_SUMMARY | Wildcard `*` for all methods | **VERIFIED (and insecure)** |
| C9 | "40+ endpoints" | PROJECT_SUMMARY | 41 routes registered | **VERIFIED** |
| C10 | "12 tables" / "13 tables listed" | PROJECT_SUMMARY | 13 tables in schema; 4 unused | **PARTIALLY VERIFIED** |
| C11 | "Redirect hit tracking", redirection management | README/PROJECT_SUMMARY | Rules stored; never applied to traffic | **MISLEADING** |
| C12 | "Auto-log 404 errors" / "Automatically tracks 404 errors" | PROJECT_SUMMARY, README | No automatic capture exists | **FALSE** |
| C13 | "Export to CSV", "CSV import", "Bulk delete" | PROJECT_SUMMARY | Export/import API only; no bulk delete | **PARTIALLY VERIFIED** |
| C14 | "General, news, video sitemaps" | README/installer | Only plain URL sets | **MISLEADING** |
| C15 | "Geocoding integration" | PROJECT_SUMMARY | Nominatim, undocumented, no timeout | **PARTIALLY VERIFIED** |
| C16 | "Opening hours management", "Nearby location search" | PROJECT_SUMMARY | API only; nearby broken | **PARTIALLY VERIFIED / BROKEN** |
| C17 | "AI-powered alt text suggestions" | README | Filename → words | **FALSE** |
| C18 | "Real AI Generation using Google Gemini 2.0" | REAL_AI_SETUP | Model shut down; silent template fallback, mislabelled | **STALE / MISLEADING** |
| C19 | "Keyword Research - Now Uses Real Data! … No more fake data!" | REAL_AI_SETUP | DB aggregates (sample data labelled "Real Data"); templated related/long-tail/questions | **MISLEADING** |
| C20 | "API key … stored outside web root when deployed" | REAL_AI_SETUP | `config/` sits inside the web root | **FALSE** |
| C21 | "Never sent to frontend" | REAL_AI_SETUP | Verified | **VERIFIED** |
| C22 | ".gitignore (already configured)" for api-keys.php | REAL_AI_SETUP | `.gitignore` contains only `old/` | **FALSE** |
| C23 | "Not exposed in responses or logs" | REAL_AI_SETUP | Not in responses; key travels in URL query (proxy-log exposure) | **PARTIALLY VERIFIED** |
| C24 | "30-second timeout prevents hanging" | REAL_AI_SETUP | True for Gemini only; Nominatim and `getimagesize` have no timeout | **PARTIALLY VERIFIED** |
| C25 | "Use OAuth 2.0 (already implemented)"; GSC OAuth callback | docs/API_CONFIGURATION | No OAuth code or route | **FALSE** |
| C26 | "Search Console Integration – Import GSC data" | README | JSON POST endpoint only, no Google API, no UI | **MISLEADING** |
| C27 | SEMrush optional integration | docs/API_CONFIGURATION | Not implemented | **NOT IMPLEMENTED** |
| C28 | "Automatically works in any directory" / "Zero Manual Configuration" | webapp/AUTO_DETECTION, webapp/.htaccess | `RewriteBase /rankmath/` hardcoded; root tree hardcodes `/rankmath/webapp`; CSS missing in webapp | **FALSE** |
| C29 | "Gzip already in .htaccess" | DEPLOYMENT_GUIDE | Only in `webapp/.htaccess` | **PARTIALLY VERIFIED** |
| C30 | `config/api-keys.php.example`, `webapp/config/database.php(.example)` | DEPLOYMENT_GUIDE | Files do not exist; DB config lives in root `config/` | **STALE** |
| C31 | Docker env vars `DB_HOST` etc. | DEPLOYMENT_GUIDE | Code never reads env vars | **FALSE** |
| C32 | "PHP 7.4 or higher" | README, installer | `match` requires PHP 8.0 | **FALSE** |
| C33 | "SEO analysis is production-ready and accurate"; "8/10 features real" | FINAL_AUDIT_REPORT | SSRF, flawed metrics; redirections/404 not real | **FALSE** |
| C34 | "Content AI – NOT REAL AI … No API calls" | FINAL_AUDIT_REPORT | Superseded by Gemini code (itself now non-functional) | **STALE** |
| C35 | "Remove or protect install.php after installation" | README checklist | App never locks/protects installer; `install-handler.php` is the dangerous file and isn't mentioned | **MISLEADING (advice only, not enforced)** |
| C36 | "Debug false in production" via `config/app.php` | README | `config/app.php` is never loaded | **FALSE (no effect)** |
| C37 | Dashboard / SEO analysis / installer workflow per QUICKSTART | QUICKSTART | Works in the `/rankmath/webapp` layout on PHP ≥ 8 | **PARTIALLY VERIFIED** |
| C38 | Router subdirectory fix | API_ERROR_FIX | Root router strips hardcoded `/rankmath/webapp` + `/api.php`; webapp router auto-detects | **VERIFIED (layout-dependent)** |
| C39 | "The app is now fully functional" | FIXES_APPLIED | See §5 | **FALSE** |

---

## 8. Credential findings (redacted)

| ID | Location | What | Assessment |
|---|---|---|---|
| SEC-003 | `docs/API_CONFIGURATION.md` line ~173, inside the "Complete Example" `config/api-keys.php` block | Google API key: `AIzaSyAP…<REDACTED>` (39 characters, exact Google key format) | Unlike the obvious placeholders elsewhere (`AIzaSy...YOUR_KEY_HERE`, `GOCSPX-abcd1234...`), this has full length and plausible entropy. **Treat as real and compromised**: it ships in the archive and in the public upstream repo, and any deployment serves it at `/docs/API_CONFIGURATION.md`. I did **not** test it against Google. **Manual rotation required by the key owner.** |
| SEC-006 | `config/database.php` (tracked) | `root` / empty password defaults | Not a secret leak, but an insecure default. The installer writes real DB credentials into this tracked file, and `.gitignore` does not exclude it. |
| — | `config/api-keys.php` (tracked) | All keys empty | No secret present. Not git-ignored despite comments saying otherwise. |
| — | Docs placeholders | `GOCSPX-abcd1234...`, `GOCSPX-xyz123...`, `123456789-abc...`, `your_semrush_key`, `secure_password`, `root_password`, `strong_password_here` | Placeholders, not secrets |

Git history was unavailable, so I could not determine whether real values were committed to `config/api-keys.php` or `config/database.php` earlier. The upstream repository owner should scan the history (e.g., `gitleaks`/`trufflehog`).

---

## 9. Complete findings list

Severity reflects exploitability and impact when the app is reachable by other hosts. Confidence: **H**igh / **M**edium / **L**ow.

### CRITICAL

**SEC-001 — Unauthenticated installer re-run → PHP code injection → RCE**
- **Files:** `install-handler.php` L46-132 (`installDatabase()`); `.htaccess` L8-13; `core/Database.php` L17
- **Description:** `install-handler.php?action=install_database` is reachable at all times, with no lock, no "already installed" check and no auth. It takes `db_host`, `db_port`, `db_name`, `db_user`, `db_pass` and `db_prefix` from `$_POST` and interpolates them **unescaped** into a PHP source heredoc written to `config/database.php` (`'password' => '$password'`, `'port' => $port` with **no quotes**). `core/Database.php` `require`s that file on every API request.
- **Exploit:** An attacker POSTs a payload that still lets the PDO connection succeed, e.g.:
  - (a) an unquoted `db_port` value starting with a valid port number followed by PHP code. PDO's DSN parser reads the leading integer, so the connection to the local MySQL succeeds (XAMPP `root` has no password by default).
  - (b) a `db_host` pointing at an attacker-run MySQL server that accepts credentials containing `'` plus PHP code in `db_pass`/`db_user`.
  - (c) a quote-breaking `db_name`.
  The next API request executes the code. Even without code injection, the attacker can re-point the app at their own database, overwrite `.htaccess`, and create databases/tables with the configured MySQL account. `db_name` and `db_prefix` are also SQL-injected into `CREATE DATABASE`/`USE` and the schema DDL.
- **Impact:** Full compromise of the web server account.
- **Fix:** Lock the installer after a successful install (lock file outside webroot + config existence check); require CLI or a one-time setup token; validate all fields strictly (host regex, integer port, identifier regex for db name/prefix); write config with `var_export()`; stop overwriting `.htaccess`; deny web access to `install*.php` once installed.
- **Confidence:** H for re-runnability, config overwrite and the code-injection sink; M-H for vector (a) specifically (relies on PDO's integer port parsing); vectors (b) and (c) don't depend on that.

**AUTH-001 — No authentication or authorization anywhere**
- **Files:** `api.php`, `webapp/api.php` (all 41 routes), `core/Router.php`, `webapp/core/Router.php`, `install-handler.php`, `generate-sample-data.php`, `phpinfo.php`, `check.php`
- **Description:** No session, login, token, API key, IP restriction or role check exists. `rm_users` is unused, and the `session` block in `config/app.php` is never loaded. State-changing and resource-consuming endpoints are open: `POST /api/404-monitor/clear` (**TRUNCATE**), every `DELETE`, every `PUT`, redirection/location/sitemap/keyword creation, `import-gsc`, `redirections/import`, `seo-analysis/analyze`, `image-seo/*analyze`, `sitemap/crawl` and `content-ai/generate` (Gemini quota). Stored data — locations (name, address, phone, email), analysis history, Content AI history, 404 IPs/user agents — is readable by anyone.
- **Impact:** Anyone who can reach the host can read, alter and destroy all data, consume API quota, and use the server as an SSRF/crawling proxy. This is effectively an **unauthenticated administrative application**.
- **Fix:** Add mandatory session-based authentication (password hashing, login, logout, secure cookies, session regeneration) to every route except a minimal health check; add an authorization check per route; deny utility scripts.
- **Confidence:** H

**SQLI-001 — SQL injection in 404 log listing**
- **Files:** `modules/Monitor/MonitorController.php` L23-62 (`getLogs`)
- **Description:** `$orderBy = $_GET['order_by']`, `$order = $_GET['order']` and `$perPage = $_GET['per_page']` go straight into `"... ORDER BY $orderBy $order LIMIT $perPage OFFSET $offset"`.
- **Exploit:** Unauthenticated `GET /api/404-monitor/logs?order_by=<expression>` permits boolean/time-based blind extraction (e.g., `IF(...,SLEEP(5),0)`) and error-based extraction where errors are displayed. With the documented default `root` DB account, an `order=` payload ending in `INTO OUTFILE ... #` can write files where `secure_file_priv` allows it; table content is attacker-seedable via the unauthenticated `POST /api/404-monitor/log`. That last step depends on DB privileges and server config.
- **Impact:** Read of any data the DB account can access (with `root`: all databases and `mysql.user` hashes); possible file write → RCE.
- **Fix:** Allow-list `order_by` columns and `order` direction; cast `page`/`per_page` to bounded integers; bind LIMIT/OFFSET as `PDO::PARAM_INT`.
- **Confidence:** H (injection); M (file-write escalation, environment-dependent)

### HIGH

**SSRF-001 — Unrestricted fetch in SEO/Competitor analysis**
- **Files:** `modules/SeoAnalysis/SeoAnalysisController.php` L23-61, L98-112, L429-448
- **Description:** The only validation is `filter_var(FILTER_VALIDATE_URL)`. That accepts `file:///…` (PHP exempts `file` from the host requirement) and any scheme with a host (`gopher://`, `dict://`, `ftp://`, …). There's no `CURLOPT_PROTOCOLS`, IP/host restriction, DNS pinning, or redirect revalidation, and no size cap — the whole body is loaded into memory. Redirects are followed (libcurl restricts redirect protocols by default, but redirect *targets* such as `http://127.0.0.1` / `169.254.169.254` are unrestricted). `CURLOPT_SSL_VERIFYPEER=false`. The response returns the parsed `<title>`, meta tags, all headings, image `src`/`alt`, **full JSON-LD contents**, link counts, sizes and timing.
- **Exploit:** `POST {"url":"http://169.254.169.254/latest/meta-data/"}` or `http://localhost:8080/admin`. Internal HTML pages leak titles/headings/meta/JSON-LD; non-HTML responses leak size/word count/timing (port/host scanning oracle). `file:///` URLs read local files with the same partial disclosure. Where libcurl supports `gopher://`, crafted requests can hit internal TCP services (e.g., unauthenticated Redis) — environment-dependent escalation.
- **Fix:** Centralized outbound-request guard (§10 / remediation prompt): http/https only via `CURLOPT_PROTOCOLS`/`CURLOPT_REDIR_PROTOCOLS`; resolve DNS and reject private/loopback/link-local/reserved/IPv4-mapped addresses; pin the resolved IP with `CURLOPT_RESOLVE`; manual redirect handling with revalidation (max 3–5); connect and total timeouts; max response bytes; TLS verification on; fetch once per analysis.
- **Confidence:** H

**SSRF-002 — Image SEO SSRF, stream wrappers, second-order SSRF**
- **Files:** `modules/ImageSeo/ImageSeoController.php` L51-76, L111-171, L275-303, L319-335
- **Description:** `analyzeImage` accepts any `image_url` with **no validation at all**. `getImageInfo` sends it to curl (any scheme) **and** to `getimagesize()`, which accepts any PHP stream wrapper (`file://`, plain local paths, `php://`, `ftp://`, `compress.zlib://`, and `phar://`). On PHP < 8.0, `phar://` with `getimagesize` triggers metadata deserialization — an object-injection risk if an attacker can place a file on disk; no upload feature exists, so this is environment-dependent. `getimagesize` uses the default socket timeout (≈60 s) and downloads the full resource. `bulkAnalyze` fetches a user page and then **every `<img src>` on it**, so an attacker-hosted page can point the server at arbitrary internal URLs (second-order SSRF), with no limit on image count. Responses disclose size and dimensions.
- **Fix:** Same central guard; never pass URLs to `getimagesize()` — fetch bytes through the guarded client with a size cap, then use `getimagesizefromstring()`; cap image count; resolve relative URLs correctly.
- **Confidence:** H (SSRF/wrappers); L-M (phar escalation)

**SSRF-003 — Sitemap crawler SSRF and unbounded crawl**
- **Files:** `modules/Sitemap/SitemapController.php` L116-150, L186-265
- **Description:** `base_url` is unvalidated (not even `FILTER_VALIDATE_URL`). Any scheme, internal hosts, redirects. `max_pages` comes from the client with no upper bound; each page has a 10 s timeout; `in_array` over growing lists is O(n²). Crawled internal URLs are stored and later served in the XML sitemap (internal URL disclosure).
- **Fix:** Central guard; hard server-side page cap (e.g., ≤ 500), total-time budget, per-host politeness delay, robots.txt respect, and a URL set (hash map).
- **Confidence:** H

**SEC-003 — Apparently real API key in documentation** — see §8.
- **Files:** `docs/API_CONFIGURATION.md` ~L173. **Fix:** Remove; replace with an obvious placeholder; key owner rotates/deletes the key in Google AI Studio / Cloud Console; scan Git history. **Confidence:** M-H that it is real (format and length match; not tested).

**CSRF-001 — Wildcard CORS and no CSRF defenses**
- **Files:** `api.php` / `webapp/api.php` L24-32; `install-handler.php`; all state-changing routes
- **Description:** `Access-Control-Allow-Origin: *` with all methods and `Content-Type` allowed means any website can make cross-origin JSON requests to the API **and read the responses** (all stored data). `POST /api/404-monitor/clear` needs no body, so it's a simple request. The installer accepts `multipart/form-data` — a CORS "simple" request — so **any web page visited by someone running this on `localhost` (the documented XAMPP setup) can trigger SEC-001 blind**. DNS rebinding can also defeat the "it's only on localhost" assumption. Browser Private/Local Network Access protections may block some of these paths in recent Chromium builds; this is browser- and version-dependent and not a control the app provides.
- **Fix:** Remove wildcard CORS (same-origin only, or an explicit configured allow-list); require auth plus a CSRF token or custom-header check on state-changing requests; validate the `Host` header against a configured hostname; lock the installer.
- **Confidence:** H

**XSS-001 — Stored XSS from unauthenticated-writable fields**
- **Files:** `assets/js/app.js` and `webapp/assets/js/app.js`: `load404Logs` (`log.uri`, `log.last_accessed`), `loadRedirections` (`source_url`, `target_url`, `redirect_type`), `loadLocations` (`name`, `address`, `city`), `loadAnalytics` (`kw.keyword`), `loadDashboard` (`recent_activity` built from stored URLs); writers: `MonitorController::log404`, `RedirectionsController::add/update/importCSV`, `LocalSeoController::add/updateLocation`, `AnalyticsController::addKeyword/importGSC`
- **Description:** Values are concatenated into template strings and assigned to `innerHTML` with no encoding. Anyone can write these values (AUTH-001).
- **Exploit:** `POST /api/404-monitor/log {"uri":"<img src=x onerror=…>"}` executes when the operator opens 404 Monitor. On XAMPP, that origin (`http://localhost`) is shared with `/phpmyadmin`, so the script can drive phpMyAdmin in the operator's browser (XAMPP's default phpMyAdmin auto-logs in as root) — environment-dependent escalation.
- **Fix:** Render with `textContent`/DOM APIs or a single `escapeHtml()` helper for every interpolation; delegated event listeners with `data-id` instead of inline `onclick`; a strict CSP (`script-src 'self'`, no `unsafe-inline`).
- **Confidence:** H

**XSS-002 — XSS from third-party/remote content**
- **Files:** `displaySeoResults` (both app.js: `data.results.basic.title`, `data.results.meta.description`); Image SEO results (`img.src`); Content AI result (`result.data.content` into `innerHTML` **and** into an inline `onclick="copyToClipboard('…')"` escaped only for `'`); `test-api.html` (JSON into `innerHTML`)
- **Description:** A page's `<title>` text such as `<img src=x onerror=…>` comes back as text from `DOMDocument` and is injected as HTML. Content AI output with `"` breaks out of the `onclick` attribute. The keyword is echoed into templates, so it also reaches this sink.
- **Exploit:** The operator runs Competitor Analysis on an attacker-controlled page → script runs in the app origin.
- **Fix:** As XSS-001; copy-to-clipboard via a stored JS variable, not an inline string.
- **Confidence:** H

**FUNC-001 — Content AI misrepresents provenance**
- **Files:** `modules/ContentAi/ContentAiController.php` L34-74, L79-128; `config/api-keys.php` L12
- **Description:** The configured model `gemini-2.0-flash-exp` has been shut down ([Google model page](<cite>turn1search6</cite>), [deprecations](<cite>turn1search5</cite>)). Non-200 responses fall back to templates silently, but `$isRealAI` is set to `true` whenever a key exists, so the response says `powered_by: "Google Gemini AI"` and stores `credits_used = 1`. The UI never shows `powered_by` at all.
- **Fix:** Make the model configurable with a current default (validated at startup or health check); set `powered_by`/`credits_used` from the *actual* path taken; on failure return an explicit error or a clearly labelled template result; show provenance in the UI.
- **Confidence:** H

### MEDIUM

**DOS-001 — Resource exhaustion and outbound amplification**
- **Files:** `SeoAnalysisController` (8 sequential fetches × 30 s, unbounded body); `ImageSeoController::bulkAnalyze` (unbounded images, two requests each, `getimagesize` ~60 s default); `SitemapController::crawlAndGenerate` (unbounded `max_pages`); `AnalyticsController::importGSC` (unbounded array, no transaction); `LocalSeoController::geocodeAddress` (no timeout); no rate limiting (the `rate_limit` setting is dead config)
- **Impact:** A single unauthenticated request can tie up PHP workers for minutes, exhaust memory, and make the server hammer third-party sites (reputation and abuse risk for your IP).
- **Fix:** Fetch once; strict timeouts and size caps; hard limits on counts; per-client rate limiting; auth.
- **Confidence:** H

**SEC-004 — Information disclosure utilities**
- **Files:** `phpinfo.php`, `check.php`, `install-handler.php?action=check_requirements` (PHP version)
- **Description:** `phpinfo()` publicly exposes PHP/OS versions, paths, modules, environment variables and request headers; `check.php` exposes `php.ini` path and extension info.
- **Fix:** Delete from the deployable tree. **Confidence:** H

**SEC-006 — Insecure configuration hygiene**
- **Files:** `.gitignore` (only `old/`); `config/database.php` (root/empty, tracked); `config/api-keys.php` (tracked); `install-handler.php` writes secrets into the tracked file
- **Fix:** Ship `*.example` templates only; git-ignore real config; support environment variables; restrictive file permissions; least-privilege DB user.
- **Confidence:** H

**SEC-008 — Error and information leakage**
- **Files:** `core/Database.php` L37-39 (`die('Database Connection Failed: ' . $e->getMessage())`); uncaught `PDOException`s everywhere (`Database::query` re-throws; the api.php middleware try/catch is a no-op); `install-handler.php` L139-148; `RedirectionsController::importCSV` L199-201
- **Description:** Raw driver messages, SQL fragments, hostnames and stack traces (when `display_errors` is on — the XAMPP default) are returned, and they also corrupt JSON responses.
- **Fix:** Global exception handler returning generic JSON errors plus a server-side log; `display_errors=0`.
- **Confidence:** H

**SEC-010 — Non-PHP sensitive files are web-served**
- **Files:** `database/schema.sql`, all `*.md` (including `docs/API_CONFIGURATION.md` with the key), `.gitignore`
- **Fix:** Move docs/schema out of the web root or deny them in server config; serve only a `public/` directory. **Confidence:** H

**PRIV-001 — Undocumented Nominatim geocoding**
- **Files:** `modules/LocalSeo/LocalSeoController.php` L85-93, L274-296
- **Description:** Business addresses go to OpenStreetMap's public Nominatim on every location add from the UI. Not documented, no opt-out, no timeout, a generic User-Agent without contact info, and no rate limiting — likely non-compliant with Nominatim's usage policy and abusable through AUTH-001.
- **Fix:** Make it opt-in via config, document it, add a timeout, an identifying UA/contact, caching, and 1 req/s throttling.
- **Confidence:** H

**FUNC-002 — PHP 8 syntax vs. documented PHP 7.4 support**
- **Files:** `ContentAiController.php` L134 (`match`); README; `install-handler.php` L26 (accepts 7.4)
- **Description:** On PHP 7.4, `api.php` fails to parse because it `require_once`s ContentAiController, so **every API endpoint fails**.
- **Fix:** Require PHP ≥ 8.1 in docs and installer (preferred), or replace `match`.
- **Confidence:** H

**FUNC-003 — Local SEO nearby search is broken**
- **Files:** `LocalSeoController::searchNearby` L233-260
- **Description:** `:lat` is used twice with `PDO::ATTR_EMULATE_PREPARES => false` → `SQLSTATE[HY093]`. Inputs are also unvalidated (non-numeric values reach SQL).
- **Fix:** Distinct placeholder names, numeric validation and bounds, radius cap.
- **Confidence:** H

**FUNC-004 — Redirections are never enforced**
- **Files:** `RedirectionsController.php`; `index.php`/`.htaccess` (no redirect handling)
- **Fix:** Either implement a front-controller redirect step (with open-redirect safeguards: allow relative targets or allow-listed hosts) or relabel the feature as "redirect rule storage/export."
- **Confidence:** H

**FUNC-005 — 404 Monitor does not monitor 404s**
- **Files:** `MonitorController::log404` L67-108; root `.htaccess` L20-23 and `webapp/.htaccess` (SPA catch-all → 200)
- **Description:** Nothing captures 404s. Dedupe uses `project_id = :project_id`, which never matches `NULL`, so duplicates pile up. IP/UA/referer are client-supplied and spoofable.
- **Fix:** Implement real capture (or relabel as "manual/external 404 import"); `<=>` null-safe comparison or `IS NULL` branch; derive IP/UA server-side where capture is implemented; retention policy.
- **Confidence:** H

**FUNC-006 — "Clear" ignores scope and always truncates**
- **Files:** `MonitorController::clearLogs` L128-139 (`$_POST['project_id']` vs JSON API); app.js `clear404Logs`
- **Description:** The project filter can never be supplied via the JSON API, so every clear is `TRUNCATE` (which also needs DROP privilege, contradicting the README's least-privilege grant).
- **Fix:** Read JSON input; use `DELETE`.
- **Confidence:** H

**FUNC-007 — Front-end error handling is broken or absent**
- **Files:** both `app.js`: `addRedirection`, `addLocation`, `delete404Log`, `clear404Logs`, `deleteRedirection`, `deleteLocation` (no `response.ok`/`success` check → false success toasts); SEO/Competitor/Content/Image/Sitemap submit handlers (no `else` for `success:false` → "Analyzing…" forever); `apiRequest()` defined but unused (dead code); no button disabling → duplicate submissions
- **Fix:** One `apiRequest` helper used everywhere; handle non-2xx/`success:false`; show errors; disable buttons while pending.
- **Confidence:** H

**FUNC-008 — SEO analysis metric correctness**
- **Files:** `SeoAnalysisController` L98-427
- **Description:** A failed fetch still produces a score (performance points) and a DB row, and triggers PHP "undefined index" warnings that can corrupt the JSON. `load_time` comes from a separate (7th) request. `word_count` includes script/style text and `str_word_count` is ASCII-only. HTTPS is judged by string prefix, ignoring redirects. "Mobile friendly" = viewport tag present. `hierarchy_proper` = has an H1. No `canonical`, `lang`, robots `noindex` or status-code checks.
- **Fix:** Fetch once, return a clear error on failure, compute metrics from the final effective URL and status, strip `script`/`style` before counting, and label heuristic checks honestly.
- **Confidence:** H

**FUNC-009 — Keyword Research presents templated output as research**
- **Files:** `ContentAiController::researchKeyword` L234-358; `generate-sample-data.php`
- **Description:** No UI. With no matches, `related_keywords` falls back to `"<kw> tips"`, `"best <kw>"`…; `long_tail_keywords` and `questions` are always templates; `search_volume` is actually summed GSC-style *impressions*; sample rows are labelled "Analytics Database (Real Data)."
- **Fix:** Return only real data with explicit `source` per field; label templates as "suggestion templates"; rename `search_volume` → `impressions`; flag sample data.
- **Confidence:** H

**FUNC-010 — Rewrite/Suggestions are pretend AI**
- **Files:** `ContentAiController::rewriteContent`, `getSuggestions`, `simplifyText` (appends " (Simplified version)"), `expandText` (appends a fixed sentence), `ImageSeoController::suggestAltText` (filename → words, documented as "AI-powered")
- **Fix:** Implement via the AI client with honest labelling, or remove/relabel as templates.
- **Confidence:** H

**FUNC-011 — Sitemap correctness**
- **Files:** `SitemapController` L116-265
- **Description:** News/video/image sitemaps contain no extension elements (invalid). `makeAbsoluteUrl` ignores the base path, port and `../`, and turns `mailto:`/`tel:`/`javascript:`/`//host` hrefs into bogus URLs. Fragments and query variants aren't normalized. Re-crawls insert duplicates (the "skip duplicates" catch never fires — there's no unique key). `max_pages` counts URLs recorded, not fetched. Non-200 pages are still added to the sitemap.
- **Fix:** RFC 3986 resolution, scheme filtering, normalization, a unique key per (project, type, url) with upsert, only-200 inclusion, and either valid extension sitemaps or removing those claims.
- **Confidence:** H

**FUNC-014 — Installer correctness**
- **Files:** `install-handler.php` L62-132; `database/schema.sql` L190-193; `install.php`
- **Description:** A re-run against an existing DB fails on the duplicate `rm_settings` insert (no `ON DUPLICATE KEY`). `str_replace('rm_', $prefix)` rewrites any `rm_` substring. The generated `.htaccess` hardcodes `/rankmath/webapp/` and **overwrites the root `.htaccess`** — in the `/rankmath/` layout this permanently rewrites the root app to `install.php`. `mbstring`/`dom` aren't checked. No DB-user-privilege guidance; `root` is the default.
- **Fix:** Idempotent schema plus a migration table; no `.htaccess` writing; extension checks; a least-privilege user.
- **Confidence:** H (logic), M (layout-specific effect)

**FUNC-013 / DOC-003 — Google Search Console / OAuth / SEMrush not implemented** — see DOC-003.

**ARCH-001 — Competing application trees and hardcoded paths**
- **Files:** root `index.php`, `assets/js/app.js` (`API_BASE='/rankmath/webapp/api.php'`), `core/Router.php` (`$basePath='/rankmath/webapp'`), root `.htaccess` (`/rankmath/…`); `webapp/index.php`, `webapp/assets/js/app.js`, `webapp/core/Router.php`, `webapp/.htaccess` (`RewriteBase /rankmath/`), `webapp/config/config.php`
- **Description:** The root tree works only if the repo root is served at exactly `/rankmath/webapp/` (the QUICKSTART layout). The `webapp/` tree works only when the repo root is at `/rankmath/`. Even then it **has no stylesheet** (`webapp/assets/css/` is missing), and its `RewriteBase /rankmath/` sends deep links (`/rankmath/webapp/seo-analysis`) to `/rankmath/index.php` — the root page, which defines no `window.APP_CONFIG` — so `webapp/app.js` throws at load. `getViewFromURL()` maps `/…/index.php` to an unknown view → blank content. Both trees share one backend; the two `Router.php` files define the same class and differ only in base-path logic.
- **Fix:** Pick one front end (recommend the auto-detecting `webapp/` approach, moved into a single `public/` document root), delete the other, compute paths at runtime, and remove hardcoded `/rankmath`.
- **Confidence:** H

**DB-001 — Missing integrity constraints / races**
- **Files:** `database/schema.sql`; `RedirectionsController::add` (check-then-insert race); `log404`/`checkRedirect` (read-modify-write hit counters); `SitemapController` (duplicates)
- **Description:** No unique keys on `redirections.source_url` (+project), `sitemaps(url,type,project)`, `analytics_keywords(keyword,date,project)` or `404_monitor(uri,project)`; no FKs to `projects`; no transactions for bulk imports.
- **Fix:** Unique keys (prefix/hashed columns for long URLs), `INSERT … ON DUPLICATE KEY UPDATE hits = hits + 1`, FKs or explicit nullable semantics, transactions.
- **Confidence:** H

### LOW

| ID | Title | Files / location | Fix | Conf |
|---|---|---|---|---|
| SEC-005 | Web-executable `generate-sample-data.php` seeds fabricated data | root | CLI-only guard (`PHP_SAPI==='cli'`) and move out of web root | H |
| SEC-007 | TLS verification disabled for analysis fetches (MITM can falsify results and inject XSS payloads) | `SeoAnalysisController::fetchUrl` L436 | Enable verification | H |
| SEC-009 | Missing security headers/CSP at root; deprecated `X-XSS-Protection`; no `Options -Indexes`; DEPLOYMENT_GUIDE vhost enables `Indexes` | `.htaccess`, `webapp/.htaccess`, `docs/DEPLOYMENT_GUIDE.md` | CSP, `frame-ancestors`, `Referrer-Policy`, `nosniff`, `-Indexes` | H |
| SEC-011 | Host header / `SCRIPT_NAME` reflected into inline script and `href` without escaping (`json_encode` without `JSON_HEX_TAG`) | `webapp/index.php` L1-12, `webapp/config/config.php` L25-31 | Configured base URL; `JSON_HEX_TAG\|JSON_HEX_AMP\|JSON_HEX_APOS\|JSON_HEX_QUOT`; `htmlspecialchars` | M |
| SEC-012 | CSV formula injection in 404 export | `MonitorController::exportCSV` L144-182 | Prefix cells starting with `= + - @ \t \r` with `'` | H |
| SEC-013 | Prompt injection: raw keyword interpolated into Gemini prompt (low impact; output is then XSS-rendered) | `ContentAiController::buildGeminiPrompt` | Length cap, delimiting, output encoding | M |
| PRIV-002 | Gemini key sent in URL query string; Gemini error bodies logged | `ContentAiController` L81, L115 | `x-goog-api-key` header; log status only | H |
| PRIV-003 | 404 logs store IP/UA with no retention or disclosure; analysis identifies itself to target sites via UA and server IP | `MonitorController`, fetchers | Retention setting; document | H |
| FUNC-012 | Image SEO URL resolution and "optimized" logic wrong (drops port/path; `//cdn` mishandled; `null` size < 200000 → optimized; ignores `srcset`/`data-src`) | `ImageSeoController` L135-171, L332-335 | Shared RFC 3986 resolver; treat unknown size as unknown | H |
| FUNC-015 | Local SEO UI captures only 4 fields; no edit, hours or schema UI; `formatBusinessHours` assumes array shape | `app.js`, `LocalSeoController` L298-323 | Form UI or relabel; validate shapes | H |
| FUNC-016 | `getLogs` crashes on non-numeric `page`, or `per_page=0` (TypeError/DivisionByZero) | `MonitorController` L25-30, L59 | Integer clamp (with SQLI-001 fix) | H |
| FUNC-017 | Dashboard averages competitor scores into "SEO Score"; Analytics renders only keywords (pages/summary/chart unused); "Run an SEO analysis" link goes to dashboard | `AnalyticsController` L43-45; `app.js loadAnalytics` | Filter `analysis_type='site'`; render or drop extra data; fix link | H |
| FUNC-018 | Content AI "Copy" button breaks on newlines/quotes | `app.js` Content AI handler | Covered by XSS-002 fix | H |
| FUNC-019 | No global error handling; DB failure `die()`s with non-JSON; the middleware try/catch is a no-op | `core/Database.php`, `api.php` L38-45 | Global handler (with SEC-008) | H |
| ARCH-002 | Dead config and unused tables: `config/app.php` never loaded (`debug`, `session`, `rate_limit`, `features` have no effect); `rm_users`, `rm_projects`, `rm_schema`, `rm_settings` unused; GSC/SEMrush config unused | `config/app.php`, `database/schema.sql`, `config/api-keys.php` | Load and enforce, or delete | H |
| ARCH-003 | Duplicate implementations drift (`core/Router.php` vs `webapp/core/Router.php`; two `app.js`, two `index.php`, two `api.php`) | listed | Consolidate (with ARCH-001) | H |
| ARCH-004 | Dev/test artifacts in deployable tree: `test-api.html` (also `innerHTML` sink, hardcoded path), `check.php`, `phpinfo.php`, `generate-sample-data.php` | root | Move to `dev/` outside web root or delete | H |
| ARCH-005 | `Database::insert/update/delete` interpolate column names and raw `WHERE` strings (safe today only because callers whitelist keys) | `core/Database.php` L78-106 | Validate identifiers against an allow-list/regex | H |
| DB-002 | Docs recommend `root` / `GRANT ALL`; `TRUNCATE` needs DROP privilege, contradicting the README's least-privilege grant | README, QUICKSTART, DEPLOYMENT_GUIDE, `MonitorController` | Least-privilege guidance; `DELETE` | H |
| DB-003 | No migration/versioning (`app_version` unused); schema changes require reinstall | `schema.sql`, installer | `schema_migrations` table | H |
| DB-004 | Unvalidated enum/decimal inputs (`redirect_type`, `status`, `type`, `changefreq`, `priority`) → strict-mode 500s | Redirections/Sitemap controllers | Validate against allowed sets | H |
| DOC-005 | Path/layout contradictions across README, QUICKSTART, AUTO_DETECTION, DEPLOYMENT_GUIDE, REAL_AI_SETUP (nonexistent files, `/rankmath/content-ai` vs `/rankmath/webapp/`, Docker env vars unused) | docs | Rewrite after consolidation | H |
| DOC-007 | Docs recommend unsafe practices (root DB user, `Options Indexes`, recursive `chmod 755`, password on `mysqldump` CLI, `GRANT ALL`) | README, QUICKSTART, DEPLOYMENT_GUIDE | Correct guidance | H |

### Documentation findings (MEDIUM unless noted)

- **DOC-001 (MEDIUM)** — False readiness/completeness claims (C1, C2, C33, C39). Files: PROJECT_SUMMARY.md, README.md, FIXES_APPLIED.md, FINAL_AUDIT_REPORT.md. Fix: rewrite README with honest status; delete or archive stale reports.
- **DOC-002 (MEDIUM)** — False security claims (C4–C7, C20, C22, C35, C36). Fix: remove or replace with implemented controls.
- **DOC-003 (MEDIUM)** — GSC/OAuth/SEMrush documented as integrations; none exist (C25–C27; README "Search Console Integration"). Fix: remove claims or implement GSC properly.
- **DOC-004 (LOW)** — Stale and contradictory internal reports (FINAL_AUDIT_REPORT vs REAL_AI_SETUP; API_ERROR_FIX's "All Fixed!"). Fix: archive under `docs/history/` with a banner, or delete.
- **DOC-006 (INFORMATIONAL)** — The project describes itself as "adapted from RankMath Pro (Enterprise License)" offering "all RankMath Pro features … no license required" and uses the RankMath name. I found no RankMath plugin source in this archive (the code appears original), and the repository has **no LICENSE file**. Trademark and licensing implications are a question for the owner and legal counsel; I can't assess them.

### INFORMATIONAL

- **MAL-001** — No malware, backdoor, telemetry, obfuscation or hidden egress found (see §2).
- **INFO-001** — Sitemap XML escapes `<loc>` correctly with `htmlspecialchars`.
- **INFO-002** — Most queries use bound parameters correctly; dynamic column names in `insert`/`update` come from code-defined allow-lists.
- **INFO-003** — `LIMIT :limit` binding under native prepares (`SeoAnalysisController::getHistory`, `ContentAiController::getHistory`) relies on driver/server type coercion. I couldn't verify this without a runtime; bind with `PDO::PARAM_INT` to be safe.

---

## 10. Recommended remediation order

**P0 — Must fix before any deployment (including LAN/localhost):** SEC-001, AUTH-001, SQLI-001, SEC-003 (remove from repo; **owner rotates key**), CSRF-001, SSRF-001, SSRF-002, SSRF-003, SEC-004.

**P1 — Must fix before Internet exposure:** XSS-001, XSS-002, DOS-001, SEC-006, SEC-008, SEC-010, SEC-005, SEC-007, SEC-009, SEC-011, SEC-012, PRIV-001, PRIV-002, PRIV-003, SEC-013, FUNC-016.

**P2 — Functional correctness and honesty:** FUNC-001, FUNC-002, FUNC-003, FUNC-004, FUNC-005, FUNC-006, FUNC-007, FUNC-008, FUNC-009, FUNC-010, FUNC-011, FUNC-012, FUNC-014, FUNC-015, FUNC-017, FUNC-018, FUNC-019, DB-001, DB-004, INFO-003.

**P3 — Architecture, maintainability, docs:** ARCH-001, ARCH-002, ARCH-003, ARCH-004, ARCH-005, DB-002, DB-003, DOC-001, DOC-002, DOC-003, DOC-004, DOC-005, DOC-006, DOC-007.

---

## 11. File audit ledger (Phase 1 / 19.11)

Legend: Sec = security relevance, Fn = functional relevance (H/M/L/—).

| # | Path | Type | Purpose | State | Sec | Fn | Findings |
|---|---|---|---|---|---|---|---|
| 1 | `.gitignore` | Git config | Ignores only `old/` | REVIEWED | M | — | SEC-006, DOC-002 |
| 2 | `.htaccess` | Apache | Root rewrites; installer redirect; API/SPA routing (hardcoded `/rankmath/`) | REVIEWED | M | H | ARCH-001, FUNC-005, SEC-009 |
| 3 | `API_ERROR_FIX.md` | Doc | Router fix narrative | REVIEWED | — | L | DOC-004, C38 |
| 4 | `FINAL_AUDIT_REPORT.md` | Doc | Prior self-audit | REVIEWED | — | L | DOC-001, DOC-004, C33-C34 |
| 5 | `FIXES_APPLIED.md` | Doc | Dashboard/sample-data notes | REVIEWED | — | L | DOC-001, C39 |
| 6 | `PROJECT_SUMMARY.md` | Doc | Feature/security claims | REVIEWED | L | L | DOC-001, DOC-002, C1-C17 |
| 7 | `QUICKSTART.md` | Doc | XAMPP setup | REVIEWED | L | L | DOC-007, C37 |
| 8 | `README.md` | Doc | Main docs | REVIEWED | L | L | DOC-001..003, DOC-007, C32 |
| 9 | `REAL_AI_SETUP.md` | Doc | Gemini setup | REVIEWED | L | L | C18-C24, DOC-002 |
| 10 | `api.php` | PHP | API entry/routes; CORS `*` | REVIEWED | H | H | AUTH-001, CSRF-001, FUNC-019 |
| 11 | `assets/css/style.css` | CSS | Styles; no remote resources | REVIEWED | — | L | none |
| 12 | `assets/js/app.js` | JS | Root SPA (hardcoded API_BASE) | REVIEWED | H | H | XSS-001, XSS-002, FUNC-007, FUNC-017, FUNC-018, ARCH-001/003 |
| 13 | `check.php` | PHP | Env diagnostics | REVIEWED | M | — | SEC-004, ARCH-004 |
| 14 | `config/api-keys.php` | PHP config | Gemini/GSC/SEMrush keys (empty) | REVIEWED | H | M | SEC-006, FUNC-001, ARCH-002 |
| 15 | `config/app.php` | PHP config | Never loaded | REVIEWED | L | L | ARCH-002, C36 |
| 16 | `config/database.php` | PHP config | Tracked DB creds (root/empty) | REVIEWED | H | H | SEC-001 sink, SEC-006 |
| 17 | `core/Database.php` | PHP | PDO wrapper | REVIEWED | H | H | SEC-008, ARCH-005, FUNC-019 |
| 18 | `core/Response.php` | PHP | JSON responses | REVIEWED | L | M | none (`JSON_PRETTY_PRINT` cosmetic) |
| 19 | `core/Router.php` | PHP | Root router (hardcoded base) | REVIEWED | M | H | ARCH-001, ARCH-003 |
| 20 | `database/schema.sql` | SQL | 13 tables + settings seed | REVIEWED | L | H | DB-001, DB-003, FUNC-014, ARCH-002, SEC-010 |
| 21 | `docs/API_CONFIGURATION.md` | Doc | API setup; **contains key** | REVIEWED | H | L | SEC-003, DOC-003, C25-C27 |
| 22 | `docs/DEPLOYMENT_GUIDE.md` | Doc | Deployment | REVIEWED | M | L | DOC-005, DOC-007, SEC-009, C29-C31 |
| 23 | `generate-sample-data.php` | PHP | Seeds demo data | REVIEWED | M | M | SEC-005, FUNC-009 |
| 24 | `index.php` | HTML (PHP ext.) | Root SPA shell | REVIEWED | L | H | ARCH-001 |
| 25 | `install-handler.php` | PHP | Installer backend | REVIEWED | **CRIT** | H | SEC-001, CSRF-001, FUNC-014, SEC-008 |
| 26 | `install.php` | HTML/JS | Installer UI (`innerHTML` of server messages) | REVIEWED | M | H | SEC-001 (entry), FUNC-014 |
| 27 | `modules/Analytics/AnalyticsController.php` | PHP | Dashboard/analytics | REVIEWED | M | H | AUTH-001, XSS-001 source, DOS-001, FUNC-017 |
| 28 | `modules/ContentAi/ContentAiController.php` | PHP | Gemini/templates/research | REVIEWED | H | H | FUNC-001/002/009/010, PRIV-002, SEC-013 |
| 29 | `modules/ImageSeo/ImageSeoController.php` | PHP | Image analysis | REVIEWED | H | H | SSRF-002, DOS-001, FUNC-010, FUNC-012 |
| 30 | `modules/LocalSeo/LocalSeoController.php` | PHP | Locations/schema/geocode | REVIEWED | M | H | PRIV-001, FUNC-003, FUNC-015 |
| 31 | `modules/Monitor/MonitorController.php` | PHP | 404 logs | REVIEWED | **CRIT** | H | SQLI-001, FUNC-005/006/016, SEC-012, PRIV-003 |
| 32 | `modules/Redirections/RedirectionsController.php` | PHP | Redirect rules | REVIEWED | M | H | FUNC-004, DB-001, DB-004, SEC-008 |
| 33 | `modules/SeoAnalysis/SeoAnalysisController.php` | PHP | SEO analysis fetcher | REVIEWED | H | H | SSRF-001, SEC-007, DOS-001, FUNC-008 |
| 34 | `modules/Sitemap/SitemapController.php` | PHP | Crawl/XML | REVIEWED | H | H | SSRF-003, DOS-001, FUNC-011, DB-001 |
| 35 | `phpinfo.php` | PHP | `phpinfo()` | REVIEWED | M | — | SEC-004 |
| 36 | `test-api.html` | HTML/JS | Manual API tester | REVIEWED | L | L | ARCH-004, XSS-002 |
| 37 | `webapp/.htaccess` | Apache | `RewriteBase /rankmath/`, headers, deflate | REVIEWED | L | H | ARCH-001, SEC-009 |
| 38 | `webapp/AUTO_DETECTION.md` | Doc | Path auto-detection claims | REVIEWED | — | L | C28, DOC-005 |
| 39 | `webapp/api.php` | PHP | API entry (uses `../` backend) | REVIEWED | H | H | AUTH-001, CSRF-001, ARCH-003 |
| 40 | `webapp/assets/js/app.js` | JS | Auto-config SPA + history routing | REVIEWED | H | H | XSS-001, XSS-002, FUNC-007, ARCH-001 |
| 41 | `webapp/config/config.php` | PHP | Base-path detection | REVIEWED | L | M | SEC-011 |
| 42 | `webapp/core/Router.php` | PHP | Auto-detect router | REVIEWED | M | H | ARCH-003 |
| 43 | `webapp/index.php` | PHP/HTML | Webapp SPA shell (missing CSS) | REVIEWED | L | H | ARCH-001, SEC-011 |

---

## 12. Coverage proof

Reconciliation compared this ledger against a **fresh `find . -type f` listing** of the extracted tree (see the reconciliation script output in the session).

```
Total files discovered:          43
Files reviewed:                  43
Files explicitly excluded:        0
Unaccounted files:                0
```

- **CHECK 1 – File coverage:** PASS (43/43; ledger set equals the filesystem set).
- **CHECK 2 – Finding coverage:** PASS — every confirmed issue has an ID in §9.
- **CHECK 3 – Remediation coverage:** PASS — every finding ID in §9 appears in `REMEDIATION_PROMPT.md` (verified programmatically; 0 omitted).

Every file was read in full (`cat -n`), not keyword-sampled. The two `api.php` files, the two `Router.php` files, the two `app.js` files and the two `index.php` files were additionally `diff`ed to establish exactly where they diverge.

---

## 13. Testing limitations

**Performed (static):**
- Safe archive inspection (types, paths, symlinks, sizes, hashes) before extraction.
- Full manual read of all 43 files; control- and data-flow tracing from every UI handler → route → controller → DB/network → render.
- ripgrep scans: dangerous PHP functions, obfuscation primitives, JS sinks and egress primitives, secret patterns, every literal URL/host.
- `node --check` on both `app.js` files and on the inline scripts of `index.php`, `webapp/index.php`, `install.php` and `test-api.html` — all parsed.
- A heuristic PHP delimiter-balance check on all PHP files. All balanced except `install.php`, a false positive: it's pure HTML/JS with no PHP blocks, and its JS parsed cleanly.
- External verification of the Gemini model's status ([Google: Gemini 2.0 Flash](<cite>turn1search6</cite>)).

**Not performed:**
- **`php -l` / PHP static analysis / dynamic execution** — no PHP interpreter was available. PHP syntax validity is inferred from manual reading. The only version-specific construct found is `match` (PHP ≥ 8.0).
- **Dynamic SSRF/SQLi/XSS confirmation** — not run. The SQL injection and XSS sinks are unambiguous in code. The RCE vector via `db_port` relies on PDO's integer port parsing; the alternative vectors (attacker-controlled DB host, `db_name`) don't.
- Behaviour that depends on **libcurl build** (e.g., `gopher://` support), **MySQL settings** (`secure_file_priv`, privileges, strict mode), **PHP settings** (`display_errors`, `allow_url_fopen`) and **browser** Private Network Access policy is flagged as environment-dependent.
- **Git history** wasn't present, so historical secret exposure couldn't be checked.
- **The suspected API key was not tested** against Google (doing so would transmit a discovered credential to a third party).
- No dependency audit was needed: there are no package manifests (no Composer/npm dependencies, no CDN assets).

---
*End of report.*
