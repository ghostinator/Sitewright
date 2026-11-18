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
3. Tick **Keep me signed in on this device for 30 days** to stay signed in on that browser even after your normal session (30 minutes idle) ends. The 30 days restart each time you come back, up to a 180-day ceiling, after which you sign in again. If you use two-step verification you enter the code once, at that sign-in. Do not tick it on a shared computer. See and forget your remembered browsers under **My Account → Remembered devices**.
4. Too many wrong attempts lock the account for a while; wait and try again.
5. **Forgot your password?** on the sign-in page e-mails you a single-use link. The page gives the same answer for any address, so it never reveals who has an account.

If your administrator has required two-step verification for administrators, an admin without it can only reach **My Account** until it is set up.

## My Account

* **Change password** (at least 12 characters). Your other sessions are signed out.
* **Two-step verification**: enter your password, add the shown key to an authenticator app (choose "enter a setup key", time-based), type the 6-digit code, and **save the 8 recovery codes** shown once. Turning it off needs your password.

## Pages

### The site switcher
The **Site** menu in the top bar chooses which of your websites every page shows: *All sites*, or one. It is remembered in your browser. With *All sites* the dashboard starts with a table of every site: latest score, watched pages (and how many are down), search impressions and clicks over 28 days, and recent 404s. Click a site's name to switch to it. A site added without the WordPress plugin (see **Sites**) is analysed and monitored from here; it just has no 404 collection or redirect enforcement.

### Dashboard
Cards for your latest SEO score, tracked keywords, impressions and clicks over the configured window (default 30 days), and recent analyses. An admin can reorder or hide cards and change the window under Settings. Sample data, if any, is labelled.

### SEO Analysis
Enter a page address. Sitewright fetches the page **once** from the server and checks on-page basics: title and description length, headings, image alt text, links, speed of the fetch, HTTPS, mobile viewport tag, structured data and word count. The score is the sum of points for passed checks, capped at 100. It is a heuristic, not Lighthouse or Search Console. Admins see which ranges and weights are in force and can change them under Settings → SEO scoring.

### Watchlist
Pages that are re-checked on a schedule (every 6 hours up to weekly). Each check is a normal analysis, so the page builds a **score history** (a small trend line in the list, a table under *History*). You are alerted in Teams or e-mail when a page's score drops by the set number of points, it gains `noindex` or loses its title (**regression**), when it cannot be fetched several times in a row (**down**, sent once), and when it is back (**recovered**). The first check of a page never alerts. *Check now* runs one immediately; *Pause* stops the schedule. Press **Watch this page** on any analysis result to add it, or add an address at the top. If an administrator has turned on Core Web Vitals, a page can also be measured weekly (*Web Vitals: on*).

### Site Audit
Crawls a whole site politely (same site only, `robots.txt` respected, page and time limits from Settings → Monitoring), checks every page and lists what to fix, worst first: broken internal links, broken outside links (404/410 only), missing titles, descriptions and H1s, duplicates, thin pages, slow pages, pages nothing links to (found by also reading your sitemap list), `noindex` pages, missing mobile viewport tag, and more. Each problem type has advice and a *Show pages* button. Every audit is compared with the previous one of the same site: **new** and **fixed** counts, and an alert for new high-priority problems. One audit runs per site at a time, and an administrator can have every site audited automatically every N days. If the audit stops at its page or time limit it says so.

### Competitor Analysis
The same checks for another site, stored separately so it never affects your own score.

### Search Opportunities
Built from your Search Console data (connect it on **Analytics** and tell each connection which site it belongs to). It lists, with a rough estimate of the extra clicks each could bring over 28 days: **Low click-through** (page-one results people see but rarely click: rewrite the title and description), **Within reach of page one** (rank 8-20 and enough impressions to matter), **Pages competing** (several of your pages show for the same query) and **Losing clicks** (a keyword whose clicks fell by 40% or more against the previous 28 days). The click estimates use average click-through by position; use them to rank, not to promise.

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

### Suggested redirects (404 Log)
Below the 404 list, each address is matched against the live pages of its site (the newest finished site audit, then the sitemap list) and up to three pages are suggested with a match strength: 100% means the same address apart from capital letters, a trailing slash or a file extension; 85% the same page name in another folder; lower values are similar names; 40% is only the closest section page. One click creates a permanent (301) rule. Addresses that already have a rule are left out. Run a site audit first for the best matches.

### Redirect Rules
Create, list and delete redirect rules (301, 302, 307, 308, 410, 451). For a **connected site**, rules are pushed to the site's plugin and enforced there. For anything else, export the rules as Apache, nginx or CSV. Choose the site in the selector to scope rules to it.

### Sites (admin)
Add every website you want to analyse and monitor. Untick *WordPress with the Sitewright plugin* for a site that does not use it. For a WordPress site, connect it with the plugin: Sitewright calls the site; the site never calls Sitewright, so Sitewright can stay private. Steps: download the plugin, install it, add the site here, copy the one-time secret into `wp-config.php` (`define('SITEWRIGHT_SECRET', '…');`), then press **Sync now**. The plugin records 404s (path and referrer only, no visitor addresses) and enforces your redirect rules even if Sitewright is offline. **New secret** replaces the secret if it was exposed.

#### Setup guide (per site)
Adding a site reads its public pages (home page, `robots.txt`, sitemap) and opens the **Setup guide**: what the site says about itself, listed so you decide what Sitewright uses. It works for every site, with or without the plugin. Per item you choose **Use this** (follows the site when it changes), **Type my own** (yours, never overwritten; if the site later says something different it is shown next to your text), **Skip**, or **Start over**. Items: home page title and description, language, what it is built with, sitemap address, business name, phone and address (from the site's structured data), plus two checks: HTTPS, and whether `robots.txt` shuts every search engine out (a site that does is flagged **Look at this**). Only items you use or type are used elsewhere:
* choosing a site in the header fills the address on the analysis, watch, image and sitemap forms (anything you typed is kept);
* **Fill empty fields from this site's details** on the Local SEO form copies the business name, phone and address;
* the Search Console property that matches the site's address is preselected;
* **Watch the home page** and **Run a first audit** are one click at the bottom of the guide.
For a WordPress site with plugin 1.1 or newer the guide has a second part, **Shared by the WordPress plugin**: the site owner decides in WordPress which extra details Sitewright may read (nothing is shared until they tick it). The site title, tagline, language and shop address, once approved, appear in the table above as items you can use, type over or skip.
Sitewright reads each site again every 7 days (Settings, Crawling and jobs, *Re-read site details every*; 0 turns it off) and on **Read the site again**. Whatever a site says is treated as untrusted text: markup is removed, lengths are capped, and a phone number or sitemap address that is the wrong shape is ignored.

### Alerts (admin)
See every alert Sitewright raised and what happened to it: *Sent*, *Held back* (with the reason: cooldown, hourly limit, event not enabled), *Batched*, *Daily digest* or *Paused*. **Pause alerts** for one site or everything for 1, 4 or 24 hours while you work on something. Two presets: **Calm** (only real problems arrive at once, the rest is batched or in one daily digest, and nights are quiet) and **Everything**. Every rule is under Settings → Alert controls.

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

## Exports and PDF reports
Reports and tables have **Download CSV** buttons (watchlist, history of one page, site audit issues and pages, search opportunities, all-sites overview, redirect suggestions; past analyses and keywords from their pages; 404 log and redirect rules as before). CSV files are UTF-8 (Excel shows accented text correctly) and text that could run as a spreadsheet formula is defused. **Print or save as PDF** opens your browser's print dialog on the report you are looking at (choose *Save as PDF* there); the menu and buttons are left out of the printout.

## Core Web Vitals
If an administrator has turned it on, **Measure Core Web Vitals** appears under an analysis result. Google PageSpeed Insights loads the page itself, so the address is sent to Google (only public addresses). You get a performance score, the lab measurements (Largest Contentful Paint, layout shift, total blocking time, First Contentful Paint, server response) rated Good / Needs work / Poor, and real-user data from Chrome when the page has enough visits. Google needs an API key in practice (see Settings → Integrations).

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
