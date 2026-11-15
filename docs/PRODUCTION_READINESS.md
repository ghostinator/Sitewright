# Production readiness: status

Verdict: **still SAFE FOR STAGING, not yet "ready for production"** (see the last section for what stands between the two).
Items are the list given after the first remediation, in the same order. "Evidence" names a test or run, not an intention.

| # | Item | Status | Evidence / what is left |
|---|------|--------|-------------------------|
| 1 | Live Gemini call (endpoint, header, model) | **Done** | Staging VM, 2026-11-15. Found and fixed a real bug (thinking tokens truncated answers); regression tests added |
| 1 | Live Nominatim call | **Done** | One address geocoded correctly on the VM; geocoding switched back off |
| 1 | SSRF guard on a real network | **Done** (mostly) | 17 of 21 live cases on the VM; the other 4 (`nip.io`/`sslip.io`) could not be resolved from that network. Own-DNS rebinding server: **not done** |
| 1 | MySQL 8, nginx, PHP 8.4 | **Done** | Full suite 321/321 on MariaDB 10.11 (PHP 8.1, 8.3, 8.4), MariaDB 11.8 + PHP 8.4 (the staging VM's pair) and MySQL 8.4 + PHP 8.3, all in Docker; nginx + PHP 8.4-FPM on the VM. Apache symlinked sub-path: still fails in my container (`Alias` works), documented |
| 2 | TOTP multi-factor sign-in | **Done** | RFC 6238 vectors, replay/older-step refusal, per-account throttle, pending-state grants nothing, recovery codes single-use and hashed, new session id at both steps, jsdom test of the login UI (`tests/cases/11_mfa_audit.php`, `tests/js`) |
| 2 | Audit log | **Done** | Logins, failures, MFA, logout, deletes, imports, CLI account changes; secrets never stored; retention purge; tested |
| 2 | Trusted-proxy handling | **Done** | `SW_TRUSTED_PROXIES`; spoofing, chains, IPv6, fail-closed config, end to end through the HTTP server (`10_trusted_proxies.php`) |
| 2 | Password reset | **Deferred** | Needs outbound email (SMTP, templates, token store). CLI `bin/user.php passwd` covers operators today |
| 2 | Roles / user-management UI | **Deferred** | Every account is a full administrator; accounts are created via `bin/user.php`. A role model touches every route |
| 3 | Background jobs for crawl / bulk images | **Deferred** | Needs a queue table, a worker process and a status UI; request-bound limits (15 s, 2 min crawl, 50 images) and per-user rate limits remain |
| 4 | Redirect enforcement, 404 ingest, GSC OAuth, location edit UI | **Needs your decision** | Each is a product feature; the app currently says plainly what it does not do |
| 5 | CI pipeline | **Written, not executed** | `.github/workflows/ci.yml` (lint + tests on PHP 8.1/8.3/8.4, MariaDB 10.11/11.8, MySQL 8.4, JS tests, gitleaks). Not pushed, so GitHub has never run it; the YAML parses and the commands were run locally |
| 5 | Permanent browser (Playwright) suite | **Not done** | The browser pass was a manual script against the VM (results in the remediation report, section 10) |
| 5 | Secret scanner | **Done locally** | gitleaks over the working tree: no leaks (in CI too). Git history still holds the fork's old third-party key |
| 5 | Backup + restore drill | **Done** | `bin/db-backup.sh` and `tests/docker/backup-drill.sh`: drop, restore, counts and table count match on MariaDB 10.11 and 11.8 and MySQL 8.4 |
| 5 | Egress firewall | **Done on staging** | nftables rules for the web user on the VM (`docs/STAGING_VM.md`); you must replicate them where you deploy |
| 5 | Structured logging, monitoring, alerting | **Not done** | Errors go to the PHP error log; the audit log covers security events. Pick your log shipper and alert rules |
| 6 | Independent penetration test / review | **Needs you** | I wrote and tested the SSRF client and the auth code myself |
| 7 | Licence | **Done** | MIT for this fork's changes; upstream shipped none (README) |
| 7 | Privacy notice | **Template done** | `docs/NETWORK_AND_PRIVACY.md`; you must name the controller and confirm the retention values |
| 7 | Trademark check on "Sitewright"; crawler terms of use | **Needs you** | Legal checks I cannot do |

## What stands between this and "ready for production"

1. Run CI on GitHub and keep it green (first run may expose environment differences).
2. Pen test (item 6) and fix what it finds.
3. Decide on the product features in item 4 (or keep them labelled as unsupported).
4. Pick logging/monitoring/alerting and rehearse a restore of a *real* backup.
5. Decide whether single-role access, no password reset and synchronous crawls are acceptable for your user base; otherwise build items 2 (reset, roles) and 3 (jobs).
