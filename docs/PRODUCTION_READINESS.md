# Production readiness: status

Updated with every commit of the production-readiness phase. Verdict stays **SAFE FOR STAGING** until the last section is satisfied.
"Evidence" names a test or a run. This project has **no hosted CI**: `tests/all.sh` runs everything locally.

## Done earlier
| Item | Evidence |
|---|---|
| Live Gemini + Nominatim + real-network SSRF checks (staging VM) | remediation report section 10 |
| TOTP MFA, audit log, trusted proxies, backups, MIT licence, privacy notice | report section 11; tests 10, 11; `tests/docker/backup-drill.sh` |
| MySQL 8.4, MariaDB 10.11/11.8, PHP 8.1/8.3/8.4 | 321/321 in Docker |

## This phase (one commit per row; status updated as work lands)
| # | Item | Status |
|---|------|--------|
| 0 | Remove CI; local `tests/all.sh`; fix Apache `.htaccess` 500 under `AllowOverride FileInfo` | **Done** (27 Apache checks on root/Alias/FileInfo-only). Symlinked sub-path still fails (documented; use Alias) |
| 1 | JSON-lines logging with request IDs | planned |
| 2 | SMTP mailer for Postal + alert channels (email, Teams, Telegram) + secrets helper | planned |
| 3 | Password reset, self-service password change and MFA setup | planned |
| 4 | Roles (admin/editor/viewer), user management UI, audit viewer | planned |
| 5 | Background jobs (crawl, bulk images, mail, alerts) with worker + quota | planned |
| 6 | Site integration: WordPress plugin (pull model), 404 collection, redirect enforcement | planned |
| 7 | Location edit UI with opening hours | planned |
| 8 | Permanent browser test suite | planned |
| 9 | Google Search Console OAuth | planned (mock-tested only until credentials exist) |

## Still needs you (cannot be done by me)
* Independent penetration test; trademark check on "Sitewright"; legal review of crawling third parties; privacy-notice controller name.
* Real credentials for Postal, Teams, Telegram, Google OAuth (entered with the hidden-input helper, never in chat).
* Choosing and running a log shipper / dashboards / uptime monitor (recommendations in the logging section of the deployment guide).

## What stands between this and "ready for production"
1. Everything above marked planned lands and its tests are green.
2. Deploy to the VM in the documented order and re-run the live checks (needs SSH access restored).
3. Pen test findings fixed; a real backup restored once; alerts proven by triggering each channel.
