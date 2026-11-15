# Staging VM (Proxmox VM 120 `sitewright-staging`)

Created 2026-11-15 on the Proxmox host `pve-pueblo-mini` (`100.106.88.45`). Nothing else on that host was modified.

| Item | Value |
|---|---|
| VM | VMID 120, q35, 2 vCPU, 3 GB RAM (ballooning off), 40 GB thin disk on `local-lvm`, `cpu: host,flags=+nested-virt` (`/dev/kvm` present inside), NIC on `vmbr0`, start on boot |
| OS | Debian 13 (cloud image, checksum verified), user `sitewright` (sudo), key-only SSH |
| Address | `192.168.0.147` (DHCP: create a reservation on the router). Tailscale: see "Tailscale" below |
| Stack | nginx 1.26, PHP 8.4-FPM, MariaDB 11.8, Docker 26, Node 20, ufw, fail2ban, nftables |
| App | `/srv/sitewright` (document root `public/`), config `config/config.php` (0640 `sitewright:www-data`) |
| Database | `sitewright`; runtime account `sw_app` (SELECT/INSERT/UPDATE/DELETE), migration account `sw_migrate`, test account `sw_tester` (only `sw_test`/`sw_inst`). Passwords: `/etc/sitewright/db.env` (root, 0600) |
| TLS | `https://sitewright-staging.tail1d3605.ts.net/` uses a Let's Encrypt certificate from `tailscale cert` (expires in ~90 days: re-run `sudo tailscale cert` in `/etc/ssl/sitewright-ts` and `sudo systemctl reload nginx`). `https://192.168.0.147/` still uses a self-signed certificate (browsers warn) |
| Admin login | user `admin`; initial password in `/home/sitewright/admin-initial-credentials.txt` on the VM. Change it: `cd /srv/sitewright && php bin/user.php passwd admin` |

## Firewalls

* **Ingress** (ufw): only 22, 80 (redirects to 443) and 443.
* **Egress for the web user** (`/etc/nftables.d-sitewright.nft`, table `inet sw_egress`): `www-data` (nginx/PHP-FPM) may open new connections only to public addresses, plus MariaDB on loopback and DNS. LAN, loopback services, Tailscale range and the cloud-metadata address are rejected. This sits *behind* the application's own SSRF guard; the rule counters stay at 0 when only the app guard acts.

## Operating it

```bash
# deploy an update from your Mac (code only; config.php and storage/ are preserved)
rsync -a --delete --exclude node_modules --exclude .git --exclude config/config.php --exclude storage/install.lock ./ sitewright@192.168.0.147:/srv/sitewright/
ssh sitewright@192.168.0.147 'sudo chmod -R g+rX /srv/sitewright'
# migrations (migration-capable account). RUN THIS IMMEDIATELY AFTER THE RSYNC: since release 004 the new code reads users.totp_secret, so
# code without the migration answers every sign-in with HTTP 500. Order: rsync, migrate, tests, then `sudo systemctl reload php8.4-fpm`.
ssh sitewright@192.168.0.147 'cd /srv/sitewright && sudo bash -c ". /etc/sitewright/db.env; sudo -u sitewright env SW_DB_USER=sw_migrate SW_DB_PASS=\$SW_DB_MIGRATE_PASS php bin/migrate.php"'
# tests (PHP 8.4 + MariaDB 11.8)
ssh sitewright@192.168.0.147 'cd /srv/sitewright && . <(sudo cat /etc/sitewright/db.env) && SW_TEST_DB_HOST=127.0.0.1 SW_TEST_DB_USER=sw_tester SW_TEST_DB_PASS="$SW_DB_TEST_PASS" php tests/run.php'
```

## Gemini key

`ssh -t sitewright@192.168.0.147 sudo sw-set-gemini-key` prompts for the key (hidden), validates its shape, stores it in `config/config.php` and reloads PHP-FPM. `... sw-set-gemini-key --remove` deletes it. The key never appears in output or process arguments.

## Tailscale

`tailscaled` is installed; the VM is not logged in until someone opens the login URL printed by `sudo tailscale up --hostname=sitewright-staging --accept-dns=false` on the VM. For trusted HTTPS on the tailnet name: enable HTTPS certificates in the Tailscale admin console, run `sudo tailscale cert <name>.<tailnet>.ts.net`, point nginx at that certificate and add the name to `allowed_hosts` / `base_url`.

## Access keys

`~/.ssh/authorized_keys` on the VM and the Proxmox host contain a key commented `claude-sitewright-setup` (installed for this setup). Remove it when finished: `sed -i '/claude-sitewright-setup/d' ~/.ssh/authorized_keys` (VM, as `sitewright`; Proxmox, as `root`).
