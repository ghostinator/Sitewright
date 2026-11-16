# Production readiness: status

Updated with every commit of the production-readiness phase. Verdict stays **SAFE FOR STAGING** until the last section is satisfied.
"Evidence" names a test or a run. This project has **no hosted CI**: `tests/all.sh` runs everything locally.

## Done earlier
| Item | Evidence |
|---|---|
| Live Gemini + Nominatim + real-network SSRF checks (staging VM) | remediation report section 10 |
| TOTP MFA, audit log, trusted proxies, backups, MIT licence, privacy notice | report section 11; tests 10, 11; `tests/docker/backup-drill.sh` |
| MySQL 8.4, MariaDB 10.11/11.8, PHP 8.1/8.3/8.4 | 321/321 in Docker |

**Deployed to the staging VM on 2026-11-15** (backup, code, migrations 004-008, 397/397 tests on PHP 8.4 + MariaDB 11.8, worker + health timer running, live crawl through the queue verified over the Tailscale HTTPS URL). Mail now supports the Postal HTTP API (`sw-set-secrets postal`; `app.ghosties.email` resolves to public addresses, so no firewall rule is needed). Postal e-mail confirmed working by the owner (2026-11-15). Still unproven live: Teams, Telegram, Google (no credentials entered yet); the Postal egress rule needs your mail server's address.

## This phase (one commit per row; status updated as work lands)
| # | Item | Status |
|---|------|--------|
| 0 | Remove CI; local `tests/all.sh`; fix Apache `.htaccess` 500 under `AllowOverride FileInfo` | **Done** (27 Apache checks on root/Alias/FileInfo-only). Symlinked sub-path still fails (documented; use Alias) |
| 1 | JSON-lines logging with request IDs; logging recommendations (`docs/ALERTS_AND_LOGGING.md`) | **Done** (logger masks secrets; tests) |
| 2 | SMTP mailer for Postal + alert channels (email, Teams, Telegram) + hidden-input secrets helper `deploy/sw-set-secrets` | **Done in code**: mailer tested against a fake SMTP server incl. real STARTTLS; Teams/Telegram against a scripted transport. **Not yet proven against your real Postal / Teams / Telegram** (needs your credentials) |
| 3 | Password reset (Postal e-mail), self-service password change and MFA setup | **Done** (`tests/cases/13_accounts.php`; reset tested end to end through the job queue, e-mail delivery itself tested in 12) |
| 4 | Roles (admin/editor/viewer), user management UI, audit viewer | **Done** (role matrix over every route, last-admin guard, disabled accounts, jsdom tests) |
| 5 | Background jobs (crawl, bulk images, mail, alerts) with worker, stale-job recovery, per-user quota; health check; systemd units | **Done** (`tests/cases/12_jobs_mail_alerts.php`, 342 PHP + 15 JS). Not yet deployed to the VM |
| 6 | Site integration: WordPress plugin (Sitewright calls the site; nothing needs to be public), 404 collection, redirect enforcement | **Done and tested against real WordPress 6** (`tests/docker/wordpress-e2e.sh`: 29 checks incl. signatures, replay, nonce, enforcement, hostile rules, SSRF) + `tests/cases/14_sites.php`. UI for sites still to do |
| 7 | Location edit UI with opening hours and JSON-LD view | **Done** (jsdom tests; browser suite below exercises it end to end) |
| 8 | Permanent browser test suite (`tests/browser`, Playwright in Docker against Apache + MariaDB + worker) | **Done**: 8 tests. First run found two real bugs (duplicate security headers; hidden `required` field blocked the MFA code step) |
| 9 | Google Search Console OAuth (PKCE, session-bound single-use state, read-only scope, encrypted refresh token, daily import) | **Done in code, mock-tested only** (`tests/cases/15_gsc.php`, 17 checks incl. replay, wrong state, revoked grant, idempotent import). Not exercised against real Google: needs your OAuth client |

## To do next (agreed with the owner, not started)
| # | Item | Notes for when we pick it up |
|---|------|------------------------------|
| 10 | **Settings area in the app** (**Phase 1 done and deployed 2026-11-16**: registry, storage, API, page, worker reload, shadow warning, require-MFA; migration 009 applied on the VM; 426/426 tests on the VM; live-checked over HTTPS, 4 real secrets verified absent from the response. Phase 2 per-page tuning next)  (admin only) to control every setting the app needs, instead of editing `config.php` / running `sw-set-secrets` | Scope to decide together. Candidates: mail (Postal), alert channels and thresholds, Gemini, Google OAuth, geocoding on/off, session timeouts, rate limits, retention (404 / audit / jobs), log level, crawl limits, site sync interval, trusted proxies/base URL (read-only hints). Design points: secrets are write-only in the UI (shown as "set", never echoed) and stored encrypted with `app_key`; non-secret settings in a `settings` table that overrides file defaults; env/config-file values stay authoritative for bootstrap settings (database, `app_key`, `base_url`, allowed hosts); every change goes to the audit log and some raise an alert; a "Send test" button per channel; validation shared with the config loader; a per-setting note saying whether it needs a PHP-FPM/worker restart. |

## Still needs you (cannot be done by me)
* Independent penetration test; trademark check on "Sitewright"; legal review of crawling third parties; privacy-notice controller name.
* Real credentials for Postal, Teams, Telegram, Google OAuth (entered with the hidden-input helper, never in chat).
* Choosing and running a log shipper / dashboards / uptime monitor (recommendations in the logging section of the deployment guide).

## What stands between this and "ready for production"
1. Everything above marked planned lands and its tests are green.
2. Deploy to the VM in the documented order and re-run the live checks (needs SSH access restored).
3. Pen test findings fixed; a real backup restored once; alerts proven by triggering each channel.
