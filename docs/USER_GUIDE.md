# Sitewright user guide

Sitewright is an SEO toolkit you run yourself. You sign in, point it at websites, and it analyses them, crawls them, tracks 404s, manages redirects and keeps keyword data. This guide covers what each page does and what leaves your server when you use it. Server setup is in the [admin guide](ADMIN_GUIDE.md); every setting is listed in the [settings reference](SETTINGS_REFERENCE.md).

## Roles

| Role | Can |
|---|---|
| **Viewer** | Read pages and data. Cannot run analyses, change data, or see visitor IPs in the 404 log. |
| **Editor** | Everything a viewer can, plus run analyses, crawls and image checks, and add, change or delete data. |
| **Admin** | Everything an editor can, plus Sites, Users, Audit Log and Settings. |

## Signing in

1. Open the address you were given and enter your username and password.
2. If two-step verification is on for your account, a second box asks for a **6-digit code** from your authenticator app, or one of your **recovery codes** (each works once).
3. Too many wrong attempts lock the account for a while; wait and try again.
4. **Forgot your password?** on the sign-in page e-mails you a single-use link. The page gives the same answer for any address, so it never reveals who has an account.

If your administrator has required two-step verification for administrators, an admin without it can only reach **My Account** until it is set up.

## My Account

* **Change password** (at least 12 characters). Your other sessions are signed out.
* **Two-step verification**: enter your password, add the shown key to an authenticator app (choose "enter a setup key", time-based), type the 6-digit code, and **save the 8 recovery codes** shown once. Turning it off needs your password.

## Pages

### Dashboard
Cards for your latest SEO score, tracked keywords, impressions and clicks over the configured window (default 30 days), and recent analyses. An admin can reorder or hide cards and change the window under Settings. Sample data, if any, is labelled.

### SEO Analysis
Enter a page address. Sitewright fetches the page **once** from the server and checks on-page basics: title and description length, headings, image alt text, links, speed of the fetch, HTTPS, mobile viewport tag, structured data and word count. The score is the sum of points for passed checks, capped at 100. It is a heuristic, not Lighthouse or Search Console. Admins see which ranges and weights are in force and can change them under Settings → SEO scoring.

### Competitor Analysis
The same checks for another site, stored separately so it never affects your own score.

### Analytics
Keyword impressions, clicks and positions. Data arrives from Google Search Console (an admin connects it on this page: sign in with Google, choose the property, data imports daily) and from anything added or imported through the API. Nothing is invented: with no data, the page says so.

### Content AI
Generates paragraphs, titles, meta descriptions, headings and conclusions. With a Gemini key configured it uses Google Gemini and **your keyword is sent to Google**; without a key it uses fixed templates and says so. Which tones and content types are offered is a setting. Generated text is labelled with its source (Gemini, template, or template fallback when the AI call failed).

### Local SEO
Business locations with address, phone, e-mail, website, coordinates and **opening hours**. Use **Edit** to change a location and **JSON-LD** to get the LocalBusiness markup for your site's `<head>`. If geocoding is enabled in Settings, a new location without coordinates has its address looked up at OpenStreetMap (the address leaves your server); otherwise enter coordinates yourself.

### Image SEO
Enter a page address; Sitewright finds its images, downloads up to the configured limit and reports alt text, size and dimensions. It runs in the background: the page shows "waiting for a worker" then the result.

### Sitemaps
Add URLs by hand, or **crawl** a site (same-origin, respects `robots.txt`, limited by pages and time; runs in the background) and download the XML sitemap.

### 404 Log
Lists 404s with hit counts. Entries come from you (add by path) and from connected sites (see Sites). Pick a site in the selector to see only its entries. Admins can set paths to ignore (for example `/wp-json/*`). Editors and admins see visitor referrers and addresses; viewers do not.

### Redirect Rules
Create, list and delete redirect rules (301, 302, 307, 308, 410, 451). For a **connected site**, rules are pushed to the site's plugin and enforced there. For anything else, export the rules as Apache, nginx or CSV. Choose the site in the selector to scope rules to it.

### Sites (admin)
Connect WordPress sites. Sitewright calls the site; the site never calls Sitewright, so Sitewright can stay private. Steps: download the plugin, install it, add the site here, copy the one-time secret into `wp-config.php` (`define('SITEWRIGHT_SECRET', '…');`), then press **Sync now**. The plugin records 404s (path and referrer only, no visitor addresses) and enforces your redirect rules even if Sitewright is offline. **New secret** replaces the secret if it was exposed.

### Users (admin)
Add people (with a password, or leave it empty to e-mail an invitation), change roles, disable or delete accounts, remove someone's two-step verification, or send a password-reset link. The last enabled administrator cannot be removed, and you cannot demote or disable yourself.

### Audit Log (admin)
Sign-ins and failures, MFA events, imports, deletions, account and settings changes, newest first. Passwords and keys are never recorded.

### Settings (admin)
One place to change how Sitewright behaves. Each setting shows where its current value comes from:

* **Built-in default**, **Config file**, **Saved here**, or **Locked by server** (an environment variable controls it; change it on the server).
* Unsaved changes collect in a bar at the bottom; **Review** shows old → new values, and changes with side effects (for example changing the Postal address clears its API key) need an explicit confirmation.
* **Reset** removes your saved value and says what the setting falls back to.
* Secrets are write-only: they show "Set" and can be replaced or removed, never read back.
* **Send test mail to me** checks that e-mail works.
* Search matches the name, the key and the environment variable. "Only changed from default" shows what you have customised.

## Background jobs
Crawls, image checks, e-mail, alerts, site syncs and Search Console imports run in a worker, not while you wait. If a page says "waiting for a worker" for more than a minute, tell your administrator: the worker may be stopped.

## What leaves the server
Only these, and only when you use the feature: the websites you analyse or crawl; Google Gemini (your Content AI prompt, if a key is set); OpenStreetMap Nominatim (a location's address, if geocoding is on); your connected WordPress sites; your mail provider; Google (Search Console, once connected). Details: [network and privacy](NETWORK_AND_PRIVACY.md).

## Troubleshooting

| You see | What it means |
|---|---|
| "Invalid host" | You opened the app on an address the server does not allow. Use the address you were given. |
| "Too many attempts" | Wait for the time shown, then try again. |
| "You do not have permission" | Your role cannot do that; ask an administrator. |
| "Two-step verification is required" | Set it up under My Account. |
| A job stays "waiting for a worker" | The background worker is not running; tell your administrator. |
| Analysis says "URL resolves to a network address that is not allowed" | Sitewright refuses private and internal addresses on purpose. |
| Search Console says "revoked or expired" | Connect Google again on the Analytics page. |
