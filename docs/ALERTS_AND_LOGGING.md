# Logging, alerts and the background worker

## What the application writes
One JSON object per line, for every request and every notable event:
```json
{"ts":"2026-11-15T22:03:35Z","level":"info","event":"request","request_id":"a1b2c3","method":"POST","path":"/api/sitemap/crawl","status":202,"ms":18,"uid":1,"ip":"203.0.113.9"}
{"ts":"…","level":"warn","event":"job_failed","job":42,"type":"crawl","status":502,"reason":"Could not crawl that site: …"}
```
* Query strings are never logged; fields named like `password`, `token`, `key`, `secret`, `cookie`, `code` are masked; strings are cut at 300 characters.
* Destination (`log.file` / `SW_LOG_FILE`): a file path, `stderr`, or empty = PHP's `error_log` (php-fpm log / Apache error log). Level: `SW_LOG_LEVEL` = debug | info | warn | error.
* Security events (sign-ins, MFA, deletions, account changes) are **also** in the `audit_log` table (`php bin/audit.php`).

### Which log tooling to use (recommendation)
| Size of setup | Use | Why |
|---|---|---|
| One VM, just you | **`deploy/setup-logging.sh`** (file + logrotate, see below), read with `jq` / `grep` | Zero infrastructure; JSON lines are greppable |
| Several VMs / you want search and dashboards | **Grafana Loki + Grafana Alloy** (on each host) | Light, runs fine in a homelab/Proxmox, free. Use **Alloy**, not Promtail: Promtail reached end-of-life on 2 March 2026 |
| Already run an ELK/OpenSearch stack | Ship the same file with Filebeat / Fluent Bit | No app change |
| Uptime and "is it up" alerts | **Uptime Kuma** push monitor fed by the health timer (it can alert to e-mail, Teams and Telegram too) | Independent of the app, so it still alerts when the app is down |
The app does not care which you pick: it writes JSON lines; collectors read them.

Minimal Alloy snippet (Loki):
```
local.file_match "sw" { path_targets = [{__path__ = "/var/log/sitewright/app.log"}] }
loki.source.file "sw" { targets = local.file_match.sw.targets  forward_to = [loki.write.default.receiver] }
loki.write "default" { endpoint { url = "http://LOKI:3100/loki/api/v1/push" } }
```
### Recommended setup for one VM: warnings to a file, rotated, plus Uptime Kuma
Log only what needs attention, keep it on disk for a bounded time, and let an independent monitor tell you when the app is down.

```
sudo /srv/sitewright/deploy/setup-logging.sh 90 warn     # keep 90 days, warnings and errors only
```
* Writes `/var/log/sitewright/app.log` (JSON lines) and sets `log.file` / `log.level` in `config.php`. At `warn`, the per-request lines (about one per click) are not written, so the file stays small: you get failed jobs, server errors, rejected requests and similar. Sign-ins, MFA, deletions and setting changes are still kept in full in the **Audit Log** (database, purged after `retention_days_audit`), so nothing security-relevant depends on the file level. Change the level later under Settings → Data & logging (`info` adds every request; use it for a day when debugging).
* **Rotation** (`deploy/sitewright.logrotate`, installed to `/etc/logrotate.d/sitewright`): every day the log is rotated; yesterday's stays plain text (`app.log.1`, easy to grep), older days are gzip-compressed (`app.log.2.gz`, ...), and anything beyond the keep limit is deleted. A day that grows past 50 MB rotates early, so a flood cannot fill the disk. Change the number of days by running the script again with another value. Read old days with `zcat /var/log/sitewright/app.log.*.gz | jq .`.
* The same file is what Loki/Alloy would read if you ever want search and dashboards; nothing changes in the app.
* Proven by `tests/docker/logging-drill.sh` (rotation, compression, deletion at the limit, file mode, idempotent setup).

**Uptime Kuma** (independent of the app; it still alerts when the app, the VM or the network is down). Use a **Push** monitor, because it needs no inbound access to Sitewright and no address or certificate to match:
1. In Kuma: Add New Monitor, type **Push**, heartbeat interval **120 seconds**, and attach your notification channels (Kuma can send to e-mail, Teams and Telegram). Copy the Push URL it shows.
2. On the Sitewright VM: `sudo sw-set-secrets uptime` and paste the URL (hidden prompt; stored in `/etc/sitewright/worker.env`, mode 0600). It must be reachable from the VM, so use the Home Assistant LAN address.
3. `sudo systemctl start sitewright-health.service`: the monitor turns green.

The health timer (`bin/healthcheck.php`, every minute) pings the URL only while everything passes: database reachable, worker running, no stuck queue, disk not nearly full. If a check fails, or the VM or timer stops, the pings stop and Kuma alerts after two missed intervals. A second, optional Kuma monitor of type HTTP(s) on `/api/health` only tells you the web server answers; the push monitor is the one that proves the whole system works.

## Alerts (email, Teams, Telegram)
Configure under **Settings → Mail & alerts** (there is a "Send test mail to me" button), or in `config/config.php` / `SW_*` variables (these outrank nothing but a Settings value you have not saved; environment variables outrank everything). Secrets never go in git or chat: on the staging VM use `sudo sw-set-secrets` (hidden prompts).
```php
'mail' => ['host' => 'postal.lan', 'port' => 587, 'encryption' => 'starttls', 'username' => '…', 'password' => '…', 'from' => 'sitewright@example.com', 'from_name' => 'Sitewright', 'verify_tls' => true],
'alerts' => ['email_to' => ['you@example.com'], 'teams_webhook' => 'https://…webhook.office.com/…', 'telegram_token' => '123456:ABC…', 'telegram_chat_id' => '123456789', 'cooldown' => 300, 'login_failures' => 10],
```
* **Postal (HTTP API, recommended)**: in Postal open the mail server, *Credentials*, create an **API** credential, and make sure the From address is on a verified domain. Then `sudo sw-set-secrets postal` (URL, key, From). Config equivalent: `'mail' => ['driver' => 'postal', 'postal_url' => 'https://app.ghosties.email', 'postal_key' => '…', 'from' => '…']`. The call goes through the app's SSRF-guarded client, so it works for a public Postal address with no firewall rule; a Postal that lives on a private address would be refused (use SMTP and the firewall rule below for that).
* **Postal over SMTP**: use an SMTP credential from the Postal server's *Credentials* page (username = the credential name, password = its key). `verify_tls` should stay `true`; if Postal uses a private CA, add that CA to the host's trust store.
* **Teams**: create a *Workflows* webhook ("Post to a channel when a webhook request is received"); the app sends a `message` with an Adaptive Card attachment, which is the format those flows accept. The old Office 365 connector webhooks are retired.
* **Telegram**: create a bot with @BotFather, send it a message, read your chat id from `https://api.telegram.org/bot<token>/getUpdates`.
* Test every channel: `php bin/alert-test.php`. Alerts are queued and retried; a channel outage never slows a request.
* **Firewall**: the web user may not open connections to private addresses. Allow your mail server explicitly (`deploy/egress-postal.nft.example`).

Monitoring alerts (Settings → Monitoring): **watch_down** (a watched page could not be fetched N times in a row; sent once), **watch_recovered**, **watch_regression** (score drop, a new `noindex`, or the title gone) and **audit_new_issues** (a site audit found new high-priority problems compared with the previous audit). They fire on a change, never on a first check, and a dead watched site does not fail any job, so it cannot turn the health check red or raise `job_failed`. The Teams card is coloured by severity and has an *Open Sitewright* button. If you saved your own *Events that send an alert* list under Settings, add the new events to it (a saved list replaces the built-in one).

What alerts fire: HTTP 5xx / unhandled errors, repeated failed sign-ins (`login_failures` within 10 minutes), failed background jobs, account/role/MFA changes, and `bin/healthcheck.php` state changes (database unreachable, worker dead, queue stuck, disk almost full). Each event has a cooldown (default 5 minutes) so a storm is one message.

## Worker and health check
```
sudo cp deploy/sitewright-worker.service deploy/sitewright-health.{service,timer} /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now sitewright-worker sitewright-health.timer
```
The worker needs the same environment as the web app (config file is shared; export `SW_DB_*` in `/etc/sitewright/worker.env` if you use env vars). It runs as `www-data`, so crawls obey the same egress firewall as web requests.

**Why the ping runs as root:** the health check runs as `www-data`, whose egress firewall rejects LAN and Tailscale addresses (where Kuma normally lives). The ping step therefore runs as root (`ExecStartPost=-+…`), and only contacts the one URL you saved. If the monitor stays red, run `journalctl -u sitewright-health.service -n 20` and look for a `curl:` line.
