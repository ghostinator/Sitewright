# Settings Area Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An admin-only Settings page that edits every runtime-tunable option (Phase 1: system settings; Phase 2: per-page tuning), with secrets write-only and encrypted, audit, reset, and test buttons.

**Architecture:** One registry class (`Sitewright\Core\Settings`) is the single source of truth (key, group, type, limits, secret flag, env var, apply mode). Saved values live in a `settings` table and are overlaid on the config after the file layer and before env (`env > saved > file > default`). A controller exposes list/save/reset/test; a vanilla-JS page renders the form from the registry output.

**Tech Stack:** PHP 8.1+ (no Composer), MariaDB/MySQL, vanilla JS (DOM nodes + `textContent` only), `Crypto` (libsodium) for secrets, existing test runners (`tests/run.php`, jsdom, Playwright).

**Spec:** `docs/superpowers/specs/2026-11-15-settings-area-design.md` (read its "Calls made after the builder review" section too).

## Global Constraints

* PHP floor 8.1; tests must pass on 8.1, 8.3, 8.4 and MariaDB 10.11/11.8, MySQL 8.4 (`tests/docker/run.sh`, `DB_IMAGE=...`).
* No new dependencies. JS: no `innerHTML`/HTML-string sinks (a test greps for them); CSP `script-src 'self'; style-src 'self'`.
* Every new route has `['role' => 'admin']`; the role-matrix test (`tests/cases/13_accounts.php`) walks the route table and must stay green.
* Secrets never appear in API responses, audit rows, logs, error messages, or page source.
* Precedence `env > saved > file > default`; a setting is locked only if it already has an `SW_*` variable.
* The app must boot with an empty or missing `settings` table and with the database down.
* Migrations: next number is `009_settings.sql`, statements separated by `;` at end of line, `{{prefix}}` placeholder; update nothing that hard-codes the migration count (tests use `migrationNames()`).
* One commit per task; run the full PHP suite on 8.1/8.3/8.4 before each commit; final `tests/all.sh` before deployment.

## Review Focus

1. **Corrupt or stale saved values** (a saved value no longer valid after a registry limit changes, or a non-JSON `svalue`): the loader ignores it, uses the lower layer, logs a warning; the app still boots.
2. **Lock-out values** (session idle > absolute, login limit 0, MFA required while the only admin has none): rejected by validation with a per-field message; the MFA case never locks the admin out (allow-list).
3. **Secret leakage via error paths**: a rejected save that includes a secret must not echo it in the 400 body, audit detail or log.
4. **Partial save**: one invalid field in a batch rejects the whole batch; nothing is written.
5. **Hostile text** in labels/values/help rendered by the UI is inert; booleans/ints arriving as strings from JSON are rejected or coerced exactly as the registry type says.

---

# Phase 1: framework and system settings

### Task 1: Registry, storage, and config overlay

**Files:**
- Create: `core/Settings.php`, `database/migrations/009_settings.sql`
- Modify: `core/Config.php` (overlay hook after build), `tests/lib.php` (nothing unless helper needed)
- Test: `tests/cases/16_settings_core.php`

**Interfaces:**
- Produces:
  - `Settings::registry(): array<string,array>`: key => `['group'=>string,'label'=>string,'help'=>string,'type'=>'bool|int|float|string|enum|list|email_list|url|secret','default'=>mixed,'min'=>?num,'max'=>?num,'enum'=>?array,'pattern'=>?string,'secret'=>bool,'env'=>?string,'apply'=>'live|worker_restart|fpm_reload','danger'=>bool,'editable'=>bool]`. `default` is read from `Config::defaults()` by key path, never repeated.
  - `Settings::readonlyKeys(): string[]` (the non-editable list from the spec's call 3).
  - `Settings::coerce(string $key, mixed $raw): mixed` (throws `InvalidArgumentException` with a field message).
  - `Settings::saved(): array<string,mixed>` (decoded, secrets decrypted if `app_key` present, invalid rows skipped with a `Logger::warn`).
  - `Settings::effective(string $key): array{value:mixed,source:'env|saved|file|default',locked:bool,fallback:string}` (never returns a secret's value: `value` is `['set'=>bool]` for secrets).
  - `Config::overlaySettings(array $cfg): array` called once, after the first successful `build()`, wrapped in try/catch (DB down / table missing => no overlay).
- Consumes: `Crypto::encrypt/decrypt($v, 'setting:<key>')`, `Database::getInstance()`.

- [ ] **Step 1: Write failing tests** in `16_settings_core.php`: (a) `registry` contains every leaf of `Config::defaults()` except those on `readonlyKeys()`, and no key appears in both (name: `settings: every config leaf is editable or explicitly read-only`); (b) precedence matrix with a saved value, a file value and an env var for `limits.crawl_seconds` (`env_wins`, `saved_beats_file`, `file_beats_default`); (c) `coerce` rejects `'abc'` for int, `150` for max 100, `'maybe'` for bool, accepts `true/false/1/0` and `"true"`? (no: only JSON booleans), (d) boot with the table dropped and with a corrupt `svalue` still returns defaults; (e) a secret saved without `app_key` is refused with a clear message.
- [ ] **Step 2: Run** `tests/docker/run.sh 8.3 16` — expect FAIL (class missing).
- [ ] **Step 3: Implement** migration 009 (`settings`: `skey VARCHAR(80) PK`, `svalue TEXT`, `is_secret TINYINT`, `updated_by BIGINT NULL`, `updated_at DATETIME ON UPDATE`) and `Settings` registry for **Phase 1 keys only** (groups: Mail & alerts, Security, Integrations, Crawling & jobs, Data & logging) per the spec; derive `env` names from `Config::build()` (grep `SW_`). Config overlay: after `build()` sets `self::$data`, call `overlaySettings` so `Database` (which reads `db.*` from `self::$data`) can connect; env layer re-applied on top (extract the env-application block of `build()` into `applyEnv(array $cfg): array`).
- [ ] **Step 4: Run** the new tests plus the full suite on 8.3 — expect PASS.
- [ ] **Step 5: Commit** `feat(settings): registry, storage, config overlay`.

### Task 2: Settings API (list, save, reset, move-to-storage, test)

**Files:**
- Create: `core/SettingsController.php`
- Modify: `config/routes.php`, `core/Settings.php` (add `save`, `reset`, `promoteFromFile`), `core/Alerts.php` (nothing), `core/Mailer.php` (expose `Mailer::sendTest(string $to): void`)
- Test: `tests/cases/17_settings_api.php`

**Interfaces:**
- Produces (all `role => admin`): `GET /api/settings`; `PUT /api/settings` (`{key: value}`); `DELETE /api/settings/{key}` (route param named `key`, matched as one segment); `POST /api/settings/{key}/promote` (copy the file value into storage); `POST /api/settings/test/{name}` (`mail` now).
- `Settings::save(array $changes, int $userId): array{changed:string[]}` — validates all first, then one transaction; cross-field rules (idle ≤ absolute, all limits ≥ 1, alert recipients need mail configured, `postal_url` must be https); danger keys must arrive with `confirm:true` in the request body or the call fails with 409 `confirm_required`; changing `mail.postal_url`, `mail.host/port/encryption`, `google.redirect_uri` also deletes the stored secrets of that integration (`mail.postal_key`, `mail.password`, `google.client_secret`) and the response says so.
- Audit: `setting_changed` with `key: old -> new` (non-secrets) or `key: changed` (secrets); security-group changes also call `Alerts::notify('setting_changed', ..., 'warning', $key)`.

- [ ] **Step 1: Write failing tests** (HTTP via `TestHttp`, admin vs editor): list masks secrets (`{"set":true}`) and never contains a saved secret; locked key (env var set on the test server) rejects with 409; whole-batch rejection with one bad field writes nothing; secret absent from the 400 body, audit rows and log file when a bad batch includes it; danger change without `confirm` is 409, with it clears the dependent secret; reset falls back to the file value then the default and reports which; promote copies a file secret into encrypted storage (DB has no plaintext); mail test returns a safe message on failure without the key; role matrix covers new routes (editor 403).
- [ ] **Step 2: Run** — expect FAIL. **Step 3: Implement** controller, routes, `Settings::save/reset/promoteFromFile`, `Mailer::sendTest`. **Step 4: Run** full suite 8.3 — PASS. **Step 5: Commit** `feat(settings): admin API with audit, safeguards and tests`.

### Task 3: Settings page (UI)

**Files:**
- Modify: `public/index.php` (admin view `settings`, nav icon), `public/assets/js/app.js` (render + actions; export `renderSettings`), `public/assets/css/style.css` (group list + sticky save bar)
- Test: `tests/js/render.test.js`

**Interfaces:**
- Consumes: `GET /api/settings` shape from Task 2: `{groups:[{id,label,settings:[{key,label,help,type,value,source,locked,env,fallback,secret,danger,apply,enum,min,max,changed_by,changed_at}]}]}`.
- Produces: `SW.renderSettings(container, data, handlers)`; actions `settings-save`, `settings-reset`, `settings-promote`, `settings-test`.

- [ ] **Step 1: Write failing jsdom tests**: hostile strings in label/help/value render as text only (extend the existing XSS payload loop with a `settings` case); secret rows show "set" and a Replace button and never an `<input value>` with the secret; locked rows disabled with the env var name; dirty tracking lists unsaved keys in the save bar; danger saves ask `confirm` before sending `confirm:true`.
- [ ] **Step 2: Run** `cd tests/js && node --test` — FAIL. **Step 3: Implement** the page: left group list, search box filtering rows, per-row control by `type`, source badge, Reset, "last changed by/when". **Step 4:** PASS; add a Playwright test in `tests/browser/app.spec.js` (open Settings, change `limits.crawl_delay_ms`, save, reload shows value and source "saved", Reset returns to default, audit page shows `setting_changed`). **Step 5: Commit** `feat(settings): admin settings page`.

### Task 4: Worker pickup, `sw-set-secrets` warning, docs

**Files:**
- Modify: `bin/worker.php` (re-read settings between jobs: `Config::reload()` new method clearing the cache and re-overlaying), `deploy/sw-set-secrets` (before writing keys, read `settings` rows via `php -r` and warn), `docs/DEPLOYMENT_GUIDE.md`, `docs/ALERTS_AND_LOGGING.md`, `docs/PRODUCTION_READINESS.md`
- Test: add to `17_settings_api.php`: `worker: a setting saved between two jobs applies to the second job` (use `Jobs::register` handler that reads `Config::get`).

- [ ] Steps: failing test; implement `Config::reload()` and call it in the worker loop; implement the helper warning (message names the key and "clear it under Settings"); rewrite doc sections to lead with Settings and keep `sw-set-secrets` as break-glass; run suites; commit `feat(settings): worker reload, helper warning, docs`.

### Task 5: Require MFA for admins

**Files:**
- Modify: `core/Settings.php` (key `security.require_mfa`, env `SW_REQUIRE_MFA`, danger), `core/App.php` (role check allow-list), `core/Auth.php` (`Auth::needsMfaEnrolment(): bool`), `public/assets/js/app.js` (redirect to My Account on `mfa_required`)
- Test: `tests/cases/13_accounts.php` additions, Playwright test

**Interfaces:**
- Produces: when `security.require_mfa` is true and the signed-in admin has no TOTP secret, every route except `POST /api/auth/logout`, `GET /api/auth/me`, `POST /api/auth/password`, `POST /api/auth/mfa/{setup,enable,disable}` returns 403 `{"success":false,"message":"Two-step verification is required","code":"mfa_required"}`; `index.php` serves only the `account` view for such a session.

- [ ] Steps: tests first (admin without MFA gets `mfa_required` on `/api/users` but can reach `/api/auth/mfa/setup`; after enabling MFA everything works; editors/viewers unaffected; `SW_REQUIRE_MFA=false` env overrides; setting cannot be enabled by an admin who has no MFA themselves, 409 with message); implement; Playwright: enable the setting, sign in as an admin without MFA, land on My Account, enrol, reach Users; run suites; commit `feat(security): require MFA for admins`.

### Task 6: Phase 1 verification and deployment

- [ ] Run `tests/all.sh`; fix failures; update `docs/PRODUCTION_READINESS.md` row 10 to "Phase 1 done"; deploy to the staging VM in the documented order (backup `/var/backups/sitewright`, rsync with the standard excludes, migrate with `sw_migrate`, run VM tests, reload php-fpm, restart worker); verify live over `https://sitewright-staging.tail1d3605.ts.net/` with a throwaway admin (change a setting, confirm the worker uses it, delete the user). Commit docs. Do NOT remove the `claude-sitewright-setup` SSH key (owner instruction).

---

# Phase 2: per-page tuning

### Task 7: Phase 2 design pass (short, committed before code)

**Files:** Create `docs/superpowers/specs/2026-11-15-settings-phase2-design.md`.

Decisions to record (defaults must reproduce today's behaviour exactly; existing tests are the regression guard):
* **SEO scoring:** keys `seo.title_min/max` (30/60), `seo.meta_min/max` (120/160), `seo.words_good/words_great` (300/1000), `seo.load_fast/load_ok` (3/5 s), and integer weights `seo.w.title`, `seo.w.title_len`, `seo.w.meta`, `seo.w.meta_len`, `seo.w.h1`, `seo.w.load_fast/ok/slow`, `seo.w.words_good/great` … (one per `+=` term in `SeoAnalysisController::calculateScore`; the builder lists them from the code). The final score = `round(100 * sum / maxPossible)` so any weights stay on a 0–100 scale; a validation rule requires every weight ≥ 0 and the max possible > 0.
* **Content AI:** `content_ai.default_tone`, `content_ai.tones_enabled` (list ⊆ built-in tones), `content_ai.types_enabled`, `content_ai.temperature` (0–2), `content_ai.max_tokens` (256–8192); endpoints reject disabled tones/types with 400.
* **Image SEO:** `image.optimal_bytes` (200000), `image.alt_max_length` (125, shown in tips).
* **Sitemaps:** `sitemap.default_priority` (0.5), `sitemap.default_changefreq` (weekly), `crawl.default_max_pages` (100).
* **Redirects:** `redirects.default_type` (301). **404 log:** `monitor.ignore_patterns` (list of path prefixes or `*` globs, applied on manual add, import and site pulls).
* **Dashboard/Analytics:** `dashboard.window_days` (30, 7–365), `dashboard.cards` (ordered list ⊆ stat card ids).
* **Users:** `users.default_role` (viewer), `users.invite_days` (7), `users.reset_minutes` (60).
* **Alerts:** `alerts.events` (list ⊆ the event names `Alerts::notify` is called with; default all on); `Alerts::notify` consults it.
* Each Phase 2 setting carries `page` (the view it belongs to) so the UI can link from the page header to its Settings group.

- [ ] Write the doc, get nothing else built until it is committed; commit `docs: settings phase 2 design`.

### Task 8: SEO and Competitor scoring settings
**Files:** Modify `core/Settings.php` (registry), `modules/SeoAnalysis/SeoAnalysisController.php` (read thresholds/weights through `Config::get`); Test: `tests/cases/05_seo_image.php` (existing assertions unchanged) + new `18_settings_phase2.php`.
- [ ] Tests first: defaults reproduce today's scores on the existing fixtures (byte-identical result JSON); changing `seo.title_max` to 70 flips `title_optimal` for a 65-char title; weights normalised so doubling every weight leaves the score unchanged; invalid weights rejected by `Settings::save`. Implement; commit `feat(settings): SEO scoring tunables`.

### Task 9: Content AI, Image SEO, Sitemap, Redirects, 404 ignore patterns
**Files:** Modify `modules/ContentAi/ContentAiController.php`, `modules/ImageSeo/ImageSeoController.php`, `modules/Sitemap/SitemapController.php`, `modules/Redirections/RedirectionsController.php`, `modules/Monitor/MonitorController.php`, `core/SiteSync.php` (ignore patterns on pull); Test: `18_settings_phase2.php`, jsdom (content form only offers enabled tones/types: add `GET /api/content-ai/status` fields `tones`, `types`, `default_tone`).
- [ ] Tests first for each (disabled tone => 400; `image.optimal_bytes` changes `is_optimized`; sitemap and redirect defaults applied when the field is omitted; ignore pattern `/wp-json/*` drops a manual add, an import row and a pulled site 404; patterns validated: must start with `/`, ≤ 200 chars, ≤ 100 entries). Implement; commit `feat(settings): content, image, sitemap, redirect and 404 tunables`.

### Task 10: Dashboard, Users, Alerts event list, page shortcuts
**Files:** Modify `modules/Analytics/AnalyticsController.php` (window days), `public/index.php` + `app.js` (cards from `dashboard.cards`, "Tune" link on each page header to `settings#<group>`), `core/UserController.php` (default role, invite days), `core/Accounts.php` (`RESET_TTL_MINUTES` from setting), `core/Alerts.php` (`events` filter); Test: `18_settings_phase2.php`, Playwright (change window to 7, dashboard note shows 7 days; Tune link opens the right group).
- [ ] Tests first; implement; commit `feat(settings): dashboard, users, alert-event tunables and page shortcuts`.

### Task 11: Final verification, docs, deployment
- [ ] Generate `docs/SETTINGS_REFERENCE.md` from the registry with `php bin/settings-doc.php` (new tiny CLI; a test asserts the committed file equals the generated output); run `tests/all.sh`; deploy Phase 2 to the VM in the documented order with a fresh backup; live-verify one setting from each page group; mark row 10 done in `docs/PRODUCTION_READINESS.md` and add report section 13. Commit. Key `claude-sitewright-setup` stays until the owner says otherwise.

### Task 12: Documentation write-up (requested by the owner: "once done, write up documentation")

**Files:** Create `docs/USER_GUIDE.md` (how to use every page and the Settings area, for the owner and any future operator), `docs/ADMIN_GUIDE.md` (install, deploy, worker, backups, upgrade order, troubleshooting, security model); Modify `README.md` (short index to both guides), `docs/SETTINGS_REFERENCE.md` (generated in Task 11, linked from the guides).

- [ ] **Step 1:** Read the final app (views in `public/index.php`, routes in `config/routes.php`, registry in `core/Settings.php`) so the guides describe what exists; no invented features.
- [ ] **Step 2:** `USER_GUIDE.md`: sign-in and two-step verification, then one section per page (Dashboard, SEO Analysis, Competitor, Analytics + Search Console, Content AI, Local SEO with hours, Image SEO, Sitemaps, 404 Log, Redirect Rules, Sites, Users, Audit Log, My Account, Settings) with what it does, what it sends to third parties, and common problems; a glossary of roles (viewer/editor/admin).
- [ ] **Step 3:** `ADMIN_GUIDE.md`: architecture in one page (web + worker + MariaDB; outbound-only to sites, Postal, Gemini, Google), the staging VM layout (`/srv/sitewright`, `/etc/sitewright/db.env`, nginx, certs, backups), deploy order (backup, rsync, migrate, tests, reload, restart worker), configuration precedence and the Settings area, secrets and `app_key` handling, backup/restore drill, alerts and logging options, WordPress plugin setup, Search Console setup, security notes (what each protection is and where it is tested), a troubleshooting table drawn from problems found during the build (worker not running, `mfa_required`, plugin signature errors, Postal From-domain, symlinked Apache sub-path).
- [ ] **Step 4:** Verify every command and path in the guides exists (grep the repo / run `php bin/*.php` help where available); run a link check over `docs/` (script: every relative markdown link resolves); commit `docs: user and admin guides`.
