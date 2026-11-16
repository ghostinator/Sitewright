# Settings area: design

Status: approach A approved by the owner; this document awaits the owner's approval of the design before any building. A builder review happened; its answers are in "Calls made after the builder review".
Scope size: a project, built in two phases. Date: 2026-11-15.

## Intent and why

The owner runs Sitewright for internal use on a private (Tailscale) VM. Today every tunable is either a line in `config/config.php`, an `SW_*` environment variable, a CLI helper (`sw-set-secrets`) that needs SSH, or a constant buried in code (SEO scoring ranges, tones, image size cut-off, 30-day windows). The owner wants **one admin-only Settings area in the app where "all settings and any and all options" can be changed and tuned**, with no file editing and no SSH, and with the safety nets (validation, audit, reset, test buttons) that make changing live settings low-risk.

## Goals

1. An admin-only **Settings** page with every runtime-tunable setting grouped sensibly, each with a label, help text, default, current value and "last changed by/when".
2. Secrets (API keys, passwords, tokens) are **write-only** in the UI: shown as "set", replaceable, never echoed, stored encrypted.
3. Saved values take effect without editing files, and without a restart where possible (say so per setting where not).
4. Every change is validated server-side, audited, and can be reset to its default with one click.
5. "Send test" buttons for integrations (mail now; Teams/Telegram when those are configured).
6. Phase 2: the hard-coded per-page tunables become settings and the pages read them.

## Non-goals

* Bootstrap settings that must exist before the app can start or be trusted stay in the config file/environment and are shown **read-only** in the UI: database connection, `app_key`, `base_url`, allowed hosts, trusted proxies, config-file path.
* No per-user or per-site settings in this project (the site sync interval is global). No multi-tenant settings.
* No settings import/export, no scheduled changes, no approval workflow.
* No visual theme/branding settings.
* Teams and Telegram: fields exist in the registry and UI but are marked "not tested yet"; no work to prove them live here.

## Anti-goals (what would make it a failure even if it technically works)

* A settings change that can lock the owner out (session timeout 0, MFA required with no way back in, a login-limit of 0, an unreachable base URL).
* Any secret appearing in an API response, log line, audit entry, error message or the page source.
* A second source of truth: the form, the validation, the config loader and the docs drifting apart.
* Silent precedence surprises: the owner changes a value and nothing happens because an environment variable overrides it, with no indication.

## Constraints

* PHP 8.1+, MariaDB/MySQL, no Composer dependencies, vanilla JS built from DOM nodes and `textContent` (no HTML-string sinks; a test greps for them), CSP `script-src 'self'; style-src 'self'`.
* Existing `Config` layering (defaults < `config/config.php` < env) must keep working for the CLI tools, worker and tests; the app must still boot with an empty settings table.
* Secrets encrypted with `Crypto` (XChaCha20-Poly1305, `app_key`); if `app_key` is missing, secret settings cannot be saved (the UI says why).
* Role model exists: settings are `admin` only (route option `role => admin`); a role-matrix test already walks every route and will cover the new ones.
* All new endpoints go through the existing pipeline (origin check, CSRF, JSON content type, audit).
* Tests run locally (`tests/all.sh`); there is no hosted CI. New work needs PHP tests (8.1/8.3/8.4), jsdom tests, and browser tests.

## Approaches considered

* **A. Settings in the database, layered over the config file** (chosen by the owner). A single registry in code defines every setting; stored values override file defaults; environment variables still win and show as "locked". Most up-front work; gives audit, reset, validation and test buttons.
* B. UI rewrites `config.php`. Rejected: the web user would need write access to its own config, no history, a bad save can break boot.
* C. Only secrets in the UI. Rejected: does not meet "tune any and all options".

## Design

### The registry (single source of truth)
One PHP class (`Settings` registry) lists every setting as data: `key` (dotted, matching existing config paths such as `limits.crawl_seconds`), `group`, `label`, `help`, `type` (`bool`, `int`, `float`, `string`, `enum`, `list`, `secret`, `email_list`, `url`), `default` (taken from `Config::defaults()`, not repeated), `min/max`/`enum values`/pattern, `secret` flag, `apply` (`live` | `worker_restart` | `fpm_reload`), `env` (the `SW_*` variable that can lock it), and an optional `test` action name. The UI form, API validation, Config merge and the generated settings reference doc are all derived from it. A test asserts every `Config::defaults()` leaf is either in the registry or on an explicit "bootstrap/not editable" list, so a new config key cannot be forgotten.

### Precedence
`environment variable` > `saved setting (database)` > `config/config.php` > `built-in default`. A setting whose environment variable is set is shown locked with the variable name and cannot be saved. The UI shows the **source** of the effective value (env / saved / file / default).

### Storage
Migration `009_settings.sql`: table `settings` (`skey` PK, `svalue` TEXT, `is_secret` flag, `updated_by`, `updated_at`). Secrets are stored as `Crypto` ciphertext bound to the context `setting:<key>`. A second table is not needed: history comes from the audit log (old/new values for non-secrets; "changed" only for secrets).

### Loading and applying
`Config::build()` gains a step that merges saved settings between the file and env layers. To keep the app bootable if the database is down or the table is missing, the merge is best-effort and cached for the request. Web requests read it once per request (one indexed query); the worker re-reads between jobs so changes apply without a restart. Settings flagged `fpm_reload`/`worker_restart` (for example the log destination) show "applies after reload" with the exact command. Phase 1 settings are all `live` or `worker_restart`.

### API (all `role => admin`)
* `GET /api/settings`: groups, each setting's definition, effective value (secrets masked as `{"set":true}`), source, locked flag, last changed.
* `PUT /api/settings`: body of `{key: value}` pairs, validated against the registry, applied in one transaction; unknown keys, locked keys, wrong types and out-of-range values are rejected with per-field messages; secrets accepted as new values only.
* `DELETE /api/settings/{key}`: reset to default (removes the saved value).
* `POST /api/settings/test/{name}`: runs a test (mail: send a test e-mail to the signed-in admin's address; later Teams/Telegram) and returns pass/fail with a safe message.

### UI
A **Settings** page (admin nav item) with a group list on the left and the group's form on the right; a search box filters settings; each row shows label, help, control, source badge, "Reset", and "last changed by/when"; a sticky "Save changes" bar that lists unsaved changes; secrets show "set" with a "Replace" button; locked rows are disabled with the variable name. Page-specific shortcuts (for example a "tune scoring" link on SEO Analysis) link into the relevant group in phase 2.

### Safeguards
* Server-side validation from the registry; **cross-field rules** (idle timeout ≤ absolute timeout; login limits ≥ 1; alert e-mail list requires mail configured).
* "Dangerous" settings require a confirm (session timeouts, MFA enforcement, login limits).
* **Require MFA for admins** (new setting): enforced at sign-in and in the role check; an admin without MFA is sent to set it up rather than locked out; the owner's break-glass is `php bin/user.php mfa-disable` and the env override `SW_REQUIRE_MFA=false`.
* Each change writes an audit entry (`setting_changed`: key, old → new for non-secrets) and raises an alert for security-group settings.
* Secrets: masked everywhere, never in audit/logs; `Logger` already masks secret-looking keys, tests assert none of the secret values appear in responses, audit rows or logs.

### Phase 1: framework + system settings
Mail & alerts (Postal, SMTP fallback, recipients, cooldown, login-failure threshold, Teams/Telegram fields, mail test button); Security (session timeouts, login and rate limits, password minimum length, require MFA for admins; trusted proxies/base URL read-only); Integrations (Gemini key/model/enable, Google OAuth client, geocoding on/off, outbound timeouts/size caps/allow-block lists); Crawling & jobs (crawl pages/seconds/delay, bulk image cap, jobs per user, stale-job seconds, site sync interval, Search Console schedule); Data & logging (retention for 404/audit/jobs, log level, request logging on/off).

### Phase 2: per-page tuning (each needs the page code to read the setting)
* SEO Analysis and Competitor: title length range (now 30–60), meta description range (120–160), minimum word counts (300/1000), load-time thresholds (3 s / 5 s), the score weights.
* Content AI: default tone, which content types and tones are offered, temperature, max output tokens.
* Image SEO: "optimized" size cut-off (now 200000 bytes), alt-text length guidance.
* Sitemaps: default priority and change frequency, default crawl page cap.
* Redirects: default redirect type. 404 log: ignore patterns (paths/bots to skip).
* Dashboard and Analytics: date window (now 30 days), cards shown.
* Users: default role for new accounts, invite and reset link lifetimes.
* Sites: default sync behaviour. Audit: which events raise alerts.
Scoring rewrites must keep the current behaviour as the default (the existing tests are the regression guard).

## Decisions made together (the owner's words and picks)
* Internal use; wants to change and tune **all** settings and options (owner).
* Approach A: database settings over the config file (owner picked it).
* Teams and Telegram later, not needed to start (owner).

## My calls (to check)
* Two phases, framework and system settings first, per-page tuning second (the owner answered only the approach question).
* Environment variables outrank saved settings and show as locked.
* Bootstrap settings read-only in the UI; secrets write-only.
* History through the audit log rather than a settings-history table.
* Admins-only access; no new role.
* "Require MFA for admins" included as a new setting with an env break-glass.

## Calls made after the builder review (mine; the owner can overrule any of them)

1. **Existing file values and `sw-set-secrets`.** Values already in `config.php` appear in Settings with source "file" (secrets show "set"). Each such setting gets a **"Move to encrypted storage"** action that copies the file value into the database (encrypted if secret); the file is left alone because removing it needs SSH. `sw-set-secrets` stays only as a break-glass tool and, before writing a key that has a saved setting, prints that Settings takes precedence and how to clear it. Deployment docs are rewritten around Settings; `sw-set-secrets` remains documented for bootstrap (`app_key`) and recovery.
2. **Plan scope.** The implementation plan covers Phase 1 in detail. Phase 2 is outlined only; it gets its own short design pass before it is built, because several items (score weights, offered tones and content types, dashboard cards, which audit events alert) have no data structure in the code today and each needs one introduced.
3. **Security-boundary settings.**
   * *Not editable in the UI (shown read-only, file/env only):* `fetch.dev_allow_cidrs`, `fetch.allow_ports`, `gemini.endpoint`, `session.secure`, `session.name`, `hsts`, `debug`, `limits.body_bytes`, `db.*`, `app_key`, `base_url`, `allowed_hosts`, `trusted_proxies`. These either weaken the SSRF guard or the request/cookie hardening, or are needed to boot.
   * *Editable but "dangerous" (confirm dialog, audit entry, security alert):* `mail.postal_url`, `mail.host`/`port`/`encryption`, `google.redirect_uri`. Changing where an API key or OAuth code is sent **clears the stored key/secret for that integration** so it has to be re-entered, which stops a hijacked admin session from redirecting an existing key to another server.
   * *Editable, normal:* everything else, including `fetch.allow_hosts`/`block_hosts` (they only narrow what the app may fetch), `contact`, `limits.import_rows`, timeouts, retention, logging.
4. **Env locks and reset.** A setting is "locked" only if it already has an `SW_*` variable; no new variables are invented for the rest. "Reset to default" removes the saved value, so the setting falls back to the file value if present, otherwise the built-in default; the UI says which ("falls back to the config file value X" / "built-in default").
5. **Require MFA for admins.** The MFA enrolment endpoints and My Account screen already exist (`/api/auth/mfa/setup|enable|disable`). When the setting is on and an admin has no MFA, the role check allows only a small allow-list (sign-out, `auth/me`, the three MFA endpoints, change password, the My Account page) and returns a `mfa_required` error elsewhere; the UI redirects to My Account with an explanation. No new session state is needed. The break-glass (`SW_REQUIRE_MFA=false`, `php bin/user.php mfa-disable`) is unchanged. This touches `App::handle`, `Auth`, the role-matrix test (new expectations), and one browser test.

## Left to the builder
Exact registry field names and the settings table indexes; the precise JS structure of the form; how the registry produces the generated reference doc; the cache strategy details; test fixtures; the order in which groups are built; copy for help texts.

## Testing outline
Registry integrity (every config leaf covered); precedence and "locked" behaviour; validation and cross-field rules; secrets never leak (responses, audit, logs, page source); role matrix includes the new routes; reset-to-default; worker picks up changes between jobs; app boots with empty/missing table; migration idempotent on MariaDB 10.11/11.8 and MySQL 8.4; jsdom tests for the form rendering (inert with hostile values); Playwright test that changes a setting, sees it applied, resets it, and checks the audit entry.
