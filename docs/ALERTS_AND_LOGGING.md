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
| One VM, just you | **`SW_LOG_FILE=/var/log/sitewright/app.log` + logrotate**, read with `jq` / `grep` | Zero infrastructure; JSON lines are greppable |
| Several VMs / you want search and dashboards | **Grafana Loki + Grafana Alloy** (on each host) | Light, runs fine in a homelab/Proxmox, free. Use **Alloy**, not Promtail: Promtail reached end-of-life on 2 March 2026 |
| Already run an ELK/OpenSearch stack | Ship the same file with Filebeat / Fluent Bit | No app change |
| Uptime and "is it up" alerts | **Uptime Kuma** pinging `/api/health` (it can alert to Teams and Telegram too) | Independent of the app, so it still alerts when the app is down |
The app does not care which you pick: it writes JSON lines; collectors read them.

Minimal Alloy snippet (Loki):
```
local.file_match "sw" { path_targets = [{__path__ = "/var/log/sitewright/app.log"}] }
loki.source.file "sw" { targets = local.file_match.sw.targets  forward_to = [loki.write.default.receiver] }
loki.write "default" { endpoint { url = "http://LOKI:3100/loki/api/v1/push" } }
```
Logrotate: `/var/log/sitewright/*.log { daily rotate 14 compress missingok copytruncate create 0640 www-data adm }`.

## Alerts (email, Teams, Telegram)
Configure in `config/config.php` (or `SW_*` variables). Secrets never go in git or chat: on the staging VM use `sudo sw-set-secrets` (hidden prompts).
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

What alerts fire: HTTP 5xx / unhandled errors, repeated failed sign-ins (`login_failures` within 10 minutes), failed background jobs, account/role/MFA changes, and `bin/healthcheck.php` state changes (database unreachable, worker dead, queue stuck, disk almost full). Each event has a cooldown (default 5 minutes) so a storm is one message.

## Worker and health check
```
sudo cp deploy/sitewright-worker.service deploy/sitewright-health.{service,timer} /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now sitewright-worker sitewright-health.timer
```
The worker needs the same environment as the web app (config file is shared; export `SW_DB_*` in `/etc/sitewright/worker.env` if you use env vars). It runs as `www-data`, so crawls obey the same egress firewall as web requests.
