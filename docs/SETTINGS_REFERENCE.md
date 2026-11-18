# Settings reference

Generated from the settings registry by `php bin/settings-doc.php`; do not edit by hand. Everything here can be changed on the **Settings** page (admins only).
Precedence: environment variable > saved in Settings > `config/config.php` > built-in default. A setting with an environment variable is locked while that variable is set.

## Mail & alerts

How Sitewright sends e-mail and who it alerts.

| Setting | Key | Default | Allowed | Environment variable | Notes |
|---|---|---|---|---|---|
| Mail method | `mail.driver` | smtp | smtp / postal | `SW_MAIL_DRIVER` | Postal HTTP API, or a generic SMTP server. |
| Postal address | `mail.postal_url` | (empty) | url | `SW_MAIL_POSTAL_URL` | The https address of your Postal server. Changing it clears the stored API key. Needs confirmation: Clears the stored Postal API key; enter it again. |
| Postal API key | `mail.postal_key` | (secret) | secret | `SW_MAIL_POSTAL_KEY` | The server API key from Postal (Credentials, type API). Secret: write-only, stored encrypted. |
| SMTP server | `mail.host` | (empty) | string | `SW_MAIL_HOST` | Only used when the mail method is SMTP. Changing it clears the stored SMTP password. Needs confirmation: Clears the stored SMTP password; enter it again. |
| SMTP port | `mail.port` | 587 | 1 to 65535 | `SW_MAIL_PORT` | Usually 587 (STARTTLS) or 465 (TLS). Needs confirmation: Clears the stored SMTP password; enter it again. |
| SMTP encryption | `mail.encryption` | starttls | starttls / tls / none | `SW_MAIL_ENCRYPTION` | starttls, tls or none. Needs confirmation: Clears the stored SMTP password; enter it again. |
| SMTP username | `mail.username` | (empty) | string | `SW_MAIL_USERNAME` | Login for the SMTP server (blank if it needs none). |
| SMTP password | `mail.password` | (secret) | secret | `SW_MAIL_PASSWORD` | Stored encrypted; never shown again. Secret: write-only, stored encrypted. |
| Check the mail server certificate | `mail.verify_tls` | on | bool | `SW_MAIL_VERIFY_TLS` | Leave on unless your SMTP server uses a certificate you cannot install. |
| From address | `mail.from` | (empty) | email | `SW_MAIL_FROM` | Must be on a domain verified in Postal / allowed by your SMTP server. |
| From name | `mail.from_name` | Sitewright |  to 100 | `SW_MAIL_FROM_NAME` | The sender name recipients see. |
| Mail timeout | `mail.timeout` | 10 | 1 to 60 seconds |  | How long to wait for the mail server. |
| Send alerts to | `alerts.email_to` | (empty) | email_list | `SW_ALERT_EMAIL_TO` | E-mail addresses that receive alerts (needs mail set up). |
| Teams webhook | `alerts.teams_webhook` | (secret) | secret | `SW_ALERT_TEAMS_WEBHOOK` | Microsoft Teams Workflows webhook address (not tested yet). Secret: write-only, stored encrypted. |
| Telegram bot token | `alerts.telegram_token` | (secret) | secret | `SW_ALERT_TELEGRAM_TOKEN` | Not tested yet. Secret: write-only, stored encrypted. |
| Telegram chat id | `alerts.telegram_chat_id` | (empty) | string | `SW_ALERT_TELEGRAM_CHAT_ID` | Not tested yet. |
| Failed sign-ins before an alert | `alerts.login_failures` | 10 | 1 to 1000 |  | Alert when this many sign-ins fail within 10 minutes. |

## Alert controls

How many alerts and e-mails you get: which events, where they go, how often, when to stay quiet, and one daily summary.

| Setting | Key | Default | Allowed | Environment variable | Notes |
|---|---|---|---|---|---|
| Send immediately | `alerts.events` | account_changed, account_deleted, gsc_sync_failed, job_failed, login_failures, mfa_disabled, mfa_reset, server_error, setting_changed, site_sync_failed, user_created, audit_new_issues, health_down, health_recovered, watch_down, watch_recovered, watch_regression | account_changed / account_deleted / audit_new_issues / gsc_sync_failed / health_down / health_recovered / job_failed / login_failures / mfa_disabled / mfa_reset / server_error / setting_changed / site_sync_failed / user_created / watch_down / watch_recovered / watch_regression |  | Alerts for these events go out the moment they happen. An event that is in none of the three lists (immediately, batches, daily digest) is never sent, but it is still recorded under Alert history. |
| Send together in batches | `alerts.events_batch` | (empty) | account_changed / account_deleted / audit_new_issues / gsc_sync_failed / health_down / health_recovered / job_failed / login_failures / mfa_disabled / mfa_reset / server_error / setting_changed / site_sync_failed / user_created / watch_down / watch_recovered / watch_regression |  | Events listed here are collected and sent as ONE message every "Batch window". Good for things that happen in bursts, such as failed jobs. |
| Send once a day (digest) | `alerts.events_digest` | (empty) | account_changed / account_deleted / audit_new_issues / gsc_sync_failed / health_down / health_recovered / job_failed / login_failures / mfa_disabled / mfa_reset / server_error / setting_changed / site_sync_failed / user_created / watch_down / watch_recovered / watch_regression |  | Events listed here are collected and sent as one summary at the digest time. Good for things that are only nice to know. |
| Where errors go | `alerts.channels_error` | email, teams, telegram | email / teams / telegram |  | email, teams and/or telegram (each must be set up under Mail & alerts). Leave empty to send no error alerts anywhere. |
| Where warnings go | `alerts.channels_warning` | email, teams, telegram | email / teams / telegram |  | For example: only teams, so e-mail is kept for errors. |
| Where notices go | `alerts.channels_info` | email, teams, telegram | email / teams / telegram |  | Recoveries and other good-to-know messages. |
| At most this many alerts an hour, per channel | `alerts.max_per_hour` | 30 | 0 to 1000 alerts |  | Extra alerts are not lost: they wait and go out together in the next batch. 0 means no limit. |
| Batch window | `alerts.batch_minutes` | 15 | 1 to 1440 minutes |  | How long collected alerts wait before they are sent together. |
| Daily digest time | `alerts.digest_time` | 08:00 | string |  | Local time (see Time zone below), as HH:MM. Nothing is sent when there is nothing to say. |
| Quiet hours start | `alerts.quiet_start` | (empty) | string |  | HH:MM local time. Leave both quiet-hours fields empty for no quiet hours. In quiet hours only errors are sent (if allowed below); everything else waits and is sent together when quiet hours end. |
| Quiet hours end | `alerts.quiet_end` | (empty) | string |  | HH:MM local time. A start later than the end means overnight (22:00 to 07:00). |
| Let errors through in quiet hours | `alerts.quiet_breakthrough` | on | bool |  | On: an error (for example a site down) is still sent at night. Off: even errors wait. |
| Hold back repeats of the same alert for | `alerts.cooldown` | 300 | 0 to 86400 seconds |  | The same event about the same page or site is sent at most once in this time (86400 seconds = 24 hours). It does not limit DIFFERENT alerts: use the hourly limit, batches and quiet hours for that. Down/recovered alerts are never held back, because they only fire when something changes. |
| Different hold-back for some events | `alerts.cooldowns` | (empty) | list |  | One per line as event=seconds, for example job_failed=3600. 0 means that event is never held back. |
| Time zone for quiet hours and the digest | `display.timezone` | UTC | Africa/Abidjan / Africa/Accra / Africa/Addis_Ababa / Africa/Algiers / Africa/Asmara / Africa/Bamako / Africa/Bangui / Africa/Banjul / Africa/Bissau / Africa/Blantyre / Africa/Brazzaville / Africa/Bujumbura / Africa/Cairo / Africa/Casablanca / Africa/Ceuta / Africa/Conakry / Africa/Dakar / Africa/Dar_es_Salaam / Africa/Djibouti / Africa/Douala / Africa/El_Aaiun / Africa/Freetown / Africa/Gaborone / Africa/Harare / Africa/Johannesburg / Africa/Juba / Africa/Kampala / Africa/Khartoum / Africa/Kigali / Africa/Kinshasa / Africa/Lagos / Africa/Libreville / Africa/Lome / Africa/Luanda / Africa/Lubumbashi / Africa/Lusaka / Africa/Malabo / Africa/Maputo / Africa/Maseru / Africa/Mbabane / Africa/Mogadishu / Africa/Monrovia / Africa/Nairobi / Africa/Ndjamena / Africa/Niamey / Africa/Nouakchott / Africa/Ouagadougou / Africa/Porto-Novo / Africa/Sao_Tome / Africa/Tripoli / Africa/Tunis / Africa/Windhoek / America/Adak / America/Anchorage / America/Anguilla / America/Antigua / America/Araguaina / America/Argentina/Buenos_Aires / America/Argentina/Catamarca / America/Argentina/Cordoba / America/Argentina/Jujuy / America/Argentina/La_Rioja / America/Argentina/Mendoza / America/Argentina/Rio_Gallegos / America/Argentina/Salta / America/Argentina/San_Juan / America/Argentina/San_Luis / America/Argentina/Tucuman / America/Argentina/Ushuaia / America/Aruba / America/Asuncion / America/Atikokan / America/Bahia / America/Bahia_Banderas / America/Barbados / America/Belem / America/Belize / America/Blanc-Sablon / America/Boa_Vista / America/Bogota / America/Boise / America/Cambridge_Bay / America/Campo_Grande / America/Cancun / America/Caracas / America/Cayenne / America/Cayman / America/Chicago / America/Chihuahua / America/Ciudad_Juarez / America/Costa_Rica / America/Coyhaique / America/Creston / America/Cuiaba / America/Curacao / America/Danmarkshavn / America/Dawson / America/Dawson_Creek / America/Denver / America/Detroit / America/Dominica / America/Edmonton / America/Eirunepe / America/El_Salvador / America/Fort_Nelson / America/Fortaleza / America/Glace_Bay / America/Goose_Bay / America/Grand_Turk / America/Grenada / America/Guadeloupe / America/Guatemala / America/Guayaquil / America/Guyana / America/Halifax / America/Havana / America/Hermosillo / America/Indiana/Indianapolis / America/Indiana/Knox / America/Indiana/Marengo / America/Indiana/Petersburg / America/Indiana/Tell_City / America/Indiana/Vevay / America/Indiana/Vincennes / America/Indiana/Winamac / America/Inuvik / America/Iqaluit / America/Jamaica / America/Juneau / America/Kentucky/Louisville / America/Kentucky/Monticello / America/Kralendijk / America/La_Paz / America/Lima / America/Los_Angeles / America/Lower_Princes / America/Maceio / America/Managua / America/Manaus / America/Marigot / America/Martinique / America/Matamoros / America/Mazatlan / America/Menominee / America/Merida / America/Metlakatla / America/Mexico_City / America/Miquelon / America/Moncton / America/Monterrey / America/Montevideo / America/Montserrat / America/Nassau / America/New_York / America/Nome / America/Noronha / America/North_Dakota/Beulah / America/North_Dakota/Center / America/North_Dakota/New_Salem / America/Nuuk / America/Ojinaga / America/Panama / America/Paramaribo / America/Phoenix / America/Port-au-Prince / America/Port_of_Spain / America/Porto_Velho / America/Puerto_Rico / America/Punta_Arenas / America/Rankin_Inlet / America/Recife / America/Regina / America/Resolute / America/Rio_Branco / America/Santarem / America/Santiago / America/Santo_Domingo / America/Sao_Paulo / America/Scoresbysund / America/Sitka / America/St_Barthelemy / America/St_Johns / America/St_Kitts / America/St_Lucia / America/St_Thomas / America/St_Vincent / America/Swift_Current / America/Tegucigalpa / America/Thule / America/Tijuana / America/Toronto / America/Tortola / America/Vancouver / America/Whitehorse / America/Winnipeg / America/Yakutat / Antarctica/Casey / Antarctica/Davis / Antarctica/DumontDUrville / Antarctica/Macquarie / Antarctica/Mawson / Antarctica/McMurdo / Antarctica/Palmer / Antarctica/Rothera / Antarctica/Syowa / Antarctica/Troll / Antarctica/Vostok / Arctic/Longyearbyen / Asia/Aden / Asia/Almaty / Asia/Amman / Asia/Anadyr / Asia/Aqtau / Asia/Aqtobe / Asia/Ashgabat / Asia/Atyrau / Asia/Baghdad / Asia/Bahrain / Asia/Baku / Asia/Bangkok / Asia/Barnaul / Asia/Beirut / Asia/Bishkek / Asia/Brunei / Asia/Chita / Asia/Colombo / Asia/Damascus / Asia/Dhaka / Asia/Dili / Asia/Dubai / Asia/Dushanbe / Asia/Famagusta / Asia/Gaza / Asia/Hebron / Asia/Ho_Chi_Minh / Asia/Hong_Kong / Asia/Hovd / Asia/Irkutsk / Asia/Jakarta / Asia/Jayapura / Asia/Jerusalem / Asia/Kabul / Asia/Kamchatka / Asia/Karachi / Asia/Kathmandu / Asia/Khandyga / Asia/Kolkata / Asia/Krasnoyarsk / Asia/Kuala_Lumpur / Asia/Kuching / Asia/Kuwait / Asia/Macau / Asia/Magadan / Asia/Makassar / Asia/Manila / Asia/Muscat / Asia/Nicosia / Asia/Novokuznetsk / Asia/Novosibirsk / Asia/Omsk / Asia/Oral / Asia/Phnom_Penh / Asia/Pontianak / Asia/Pyongyang / Asia/Qatar / Asia/Qostanay / Asia/Qyzylorda / Asia/Riyadh / Asia/Sakhalin / Asia/Samarkand / Asia/Seoul / Asia/Shanghai / Asia/Singapore / Asia/Srednekolymsk / Asia/Taipei / Asia/Tashkent / Asia/Tbilisi / Asia/Tehran / Asia/Thimphu / Asia/Tokyo / Asia/Tomsk / Asia/Ulaanbaatar / Asia/Urumqi / Asia/Ust-Nera / Asia/Vientiane / Asia/Vladivostok / Asia/Yakutsk / Asia/Yangon / Asia/Yekaterinburg / Asia/Yerevan / Atlantic/Azores / Atlantic/Bermuda / Atlantic/Canary / Atlantic/Cape_Verde / Atlantic/Faroe / Atlantic/Madeira / Atlantic/Reykjavik / Atlantic/South_Georgia / Atlantic/St_Helena / Atlantic/Stanley / Australia/Adelaide / Australia/Brisbane / Australia/Broken_Hill / Australia/Darwin / Australia/Eucla / Australia/Hobart / Australia/Lindeman / Australia/Lord_Howe / Australia/Melbourne / Australia/Perth / Australia/Sydney / Europe/Amsterdam / Europe/Andorra / Europe/Astrakhan / Europe/Athens / Europe/Belgrade / Europe/Berlin / Europe/Bratislava / Europe/Brussels / Europe/Bucharest / Europe/Budapest / Europe/Busingen / Europe/Chisinau / Europe/Copenhagen / Europe/Dublin / Europe/Gibraltar / Europe/Guernsey / Europe/Helsinki / Europe/Isle_of_Man / Europe/Istanbul / Europe/Jersey / Europe/Kaliningrad / Europe/Kirov / Europe/Kyiv / Europe/Lisbon / Europe/Ljubljana / Europe/London / Europe/Luxembourg / Europe/Madrid / Europe/Malta / Europe/Mariehamn / Europe/Minsk / Europe/Monaco / Europe/Moscow / Europe/Oslo / Europe/Paris / Europe/Podgorica / Europe/Prague / Europe/Riga / Europe/Rome / Europe/Samara / Europe/San_Marino / Europe/Sarajevo / Europe/Saratov / Europe/Simferopol / Europe/Skopje / Europe/Sofia / Europe/Stockholm / Europe/Tallinn / Europe/Tirane / Europe/Ulyanovsk / Europe/Vaduz / Europe/Vatican / Europe/Vienna / Europe/Vilnius / Europe/Volgograd / Europe/Warsaw / Europe/Zagreb / Europe/Zurich / Indian/Antananarivo / Indian/Chagos / Indian/Christmas / Indian/Cocos / Indian/Comoro / Indian/Kerguelen / Indian/Mahe / Indian/Maldives / Indian/Mauritius / Indian/Mayotte / Indian/Reunion / Pacific/Apia / Pacific/Auckland / Pacific/Bougainville / Pacific/Chatham / Pacific/Chuuk / Pacific/Easter / Pacific/Efate / Pacific/Fakaofo / Pacific/Fiji / Pacific/Funafuti / Pacific/Galapagos / Pacific/Gambier / Pacific/Guadalcanal / Pacific/Guam / Pacific/Honolulu / Pacific/Kanton / Pacific/Kiritimati / Pacific/Kosrae / Pacific/Kwajalein / Pacific/Majuro / Pacific/Marquesas / Pacific/Midway / Pacific/Nauru / Pacific/Niue / Pacific/Norfolk / Pacific/Noumea / Pacific/Pago_Pago / Pacific/Palau / Pacific/Pitcairn / Pacific/Pohnpei / Pacific/Port_Moresby / Pacific/Rarotonga / Pacific/Saipan / Pacific/Tahiti / Pacific/Tarawa / Pacific/Tongatapu / Pacific/Wake / Pacific/Wallis / UTC |  | The time zone of the times above. Stored times everywhere else stay in UTC. |

## Security

Sign-in limits, session lengths and rate limits.

| Setting | Key | Default | Allowed | Environment variable | Notes |
|---|---|---|---|---|---|
| Session length when idle | `session.idle_timeout` | 1800 | 300 to 86400 seconds |  | Sign out after this much inactivity. Needs confirmation: Changes how long everyone stays signed in. |
| Keep me signed in for | `session.remember_days` | 30 | 0 to 90 days | `SW_SESSION_REMEMBER_DAYS` | People who tick "Keep me signed in" on the sign-in page stay signed in on that browser this many days (the clock restarts each time they come back). 0 turns the option off. Changing it affects new sign-ins; existing remembered devices follow the new length at their next visit. Each person can forget their devices on My Account. |
| Longest session | `session.absolute_timeout` | 28800 | 900 to 2592000 seconds |  | Sign out after this long, however active. Needs confirmation: Changes how long everyone stays signed in. |
| Require two-step verification for administrators | `security.require_mfa` | off | bool | `SW_REQUIRE_MFA` | Administrators without it can only reach My Account until they set it up. You must have it on yourself first. Break-glass: SW_REQUIRE_MFA=false on the server. Needs confirmation: Other administrators without two-step verification are limited to My Account until they enrol. |
| Failed sign-ins per account | `limits.login_attempts` | 5 | 3 to 100 |  | Wrong passwords allowed per account in the window below. Needs confirmation: Strict sign-in limits apply to everyone and let anyone lock an account by guessing. |
| Failed sign-ins per address | `limits.login_ip_attempts` | 30 | 10 to 1000 |  | Wrong passwords allowed from one IP address in the window below. Needs confirmation: Strict sign-in limits apply to everyone behind the same address, including you. |
| Sign-in limit window | `limits.login_window` | 900 | 60 to 86400 seconds |  | How long failed sign-ins are counted. Needs confirmation: A long window keeps people locked out for longer. |
| Heavy actions per minute | `limits.expensive_per_min` | 10 | 1 to 600 |  | Analyses, crawls and AI requests allowed per user per minute. |

## Integrations

Gemini, Google, geocoding and what the server may fetch.

| Setting | Key | Default | Allowed | Environment variable | Notes |
|---|---|---|---|---|---|
| Measure Core Web Vitals (Google PageSpeed) | `pagespeed.enabled` | off | bool | `SW_PAGESPEED_ENABLED` | Off by default. When on, the address of a page you measure is sent to Google PageSpeed Insights, which loads the page itself. Only public addresses are sent. |
| PageSpeed API key | `pagespeed.api_key` | (secret) | secret | `SW_PAGESPEED_KEY` | Needed in practice: without a key Google answers from a shared free quota that is almost always used up (tested). Create a free key in the Google Cloud console (enable the PageSpeed Insights API). Stored encrypted and sent in a request header, never in the address. Secret: write-only, stored encrypted. |
| Measure as | `pagespeed.strategy` | mobile | mobile / desktop |  | Mobile is what Google ranks by; desktop is the other view. |
| Gemini API key | `gemini.api_key` | (secret) | secret | `SW_GEMINI_API_KEY` | Content AI uses templates when this is empty. Secret: write-only, stored encrypted. |
| Gemini model | `gemini.model` | gemini-3.6-flash | string | `SW_GEMINI_MODEL` | For example gemini-3.6-flash. |
| Look up coordinates for new locations | `enable_geocoding` | off | bool | `SW_ENABLE_GEOCODING` | Sends the address to OpenStreetMap Nominatim when a location has no coordinates. |
| Contact in the crawler User-Agent | `contact` | (empty) |  to 200 | `SW_CONTACT` | Shown to the sites Sitewright fetches (a URL or e-mail). |
| Google OAuth client id | `google.client_id` | (empty) | string | `SW_GOOGLE_CLIENT_ID` | For Search Console. |
| Google OAuth client secret | `google.client_secret` | (secret) | secret | `SW_GOOGLE_CLIENT_SECRET` | Stored encrypted; never shown again. Secret: write-only, stored encrypted. |
| Google redirect address | `google.redirect_uri` | (empty) | url | `SW_GOOGLE_REDIRECT_URI` | Must match the authorized redirect URI in Google Cloud. Changing it clears the client secret. Needs confirmation: Clears the stored Google client secret; enter it again. |
| Only fetch these sites | `fetch.allow_hosts` | (empty) | list | `SW_FETCH_ALLOW_HOSTS` | When not empty, Sitewright fetches only these host names. Narrows what is allowed; never widens it. |
| Never fetch these sites | `fetch.block_hosts` | (empty) | list | `SW_FETCH_BLOCK_HOSTS` | Host names Sitewright refuses to fetch. |
| Connect timeout | `fetch.connect_timeout` | 5 | 1 to 30 seconds |  | How long to wait to open a connection to a site. |
| Request timeout | `fetch.timeout` | 15 | 2 to 60 seconds |  | Total time for one fetch. |
| Name lookup timeout | `fetch.dns_timeout` | 2 | 1 to 10 seconds |  | How long to wait for a host name to resolve. |
| Largest page | `fetch.max_html_bytes` | 5242880 | 65536 to 33554432 bytes |  | Pages bigger than this are cut off. |
| Largest image | `fetch.max_image_bytes` | 10485760 | 65536 to 52428800 bytes |  | Images bigger than this are skipped. |
| Redirects followed | `fetch.max_redirects` | 5 | 0 to 10 |  | How many redirects to follow before giving up. |

## Crawling & jobs

How much a crawl or image batch may do, and how often sites sync.

| Setting | Key | Default | Allowed | Environment variable | Notes |
|---|---|---|---|---|---|
| Most pages per crawl | `limits.max_pages` | 500 | 1 to 5000 |  | Upper limit; a crawl can ask for fewer. |
| Longest crawl | `limits.crawl_seconds` | 120 | 10 to 900 seconds |  | A crawl stops after this long and keeps what it found. |
| Pause between pages | `limits.crawl_delay_ms` | 200 | 0 to 5000 milliseconds |  | Be polite to the site you crawl. |
| Most images per analysis | `limits.bulk_images` | 50 | 1 to 200 |  | Images beyond this are not analysed. |
| Most rows per import | `limits.import_rows` | 5000 | 100 to 100000 |  | Larger CSV files are refused. |
| Background jobs per user | `limits.jobs_per_user` | 5 | 1 to 50 |  | Queued plus running jobs one user may have. |
| Declare a job stuck after | `limits.job_stale_seconds` | 600 | 60 to 7200 seconds |  | A job whose worker stopped reporting is re-queued after this long. |
| Site sync interval | `sites.sync_minutes` | 10 | 1 to 1440 minutes |  | How often connected WordPress sites are synced. |
| Re-read site details every | `sites.profile_days` | 7 | 0 to 90 days |  | How often each site's public pages (home page, robots.txt, sitemap) are read again for the setup guide. 0 turns the schedule off; you can still press "Read the site again". Choices you made are never overwritten. |

## Data & logging

How long records are kept and how much is logged.

| Setting | Key | Default | Allowed | Environment variable | Notes |
|---|---|---|---|---|---|
| Keep 404 entries | `retention_days_404` | 90 | 1 to 3650 days |  | Older entries are deleted automatically. |
| Keep audit log entries | `retention_days_audit` | 365 | 1 to 3650 days |  | Older entries are deleted automatically. |
| Keep finished jobs | `limits.job_retention_days` | 14 | 1 to 365 days |  | Finished and failed jobs older than this are deleted. |
| Log detail | `log.level` | info | debug / info / warn / error | `SW_LOG_LEVEL` | debug, info, warn or error. |
| Log every request | `log.requests` | on | bool |  | One line per request (path without query string). |

## Content AI

Which tones and content types are offered, and how the model is asked to write.

| Setting | Key | Default | Allowed | Environment variable | Notes |
|---|---|---|---|---|---|
| Default tone | `content_ai.default_tone` | professional | professional / casual / friendly / formal |  | Used when a request does not name a tone. Must be one of the enabled tones. |
| Tones offered | `content_ai.tones_enabled` | professional, casual, friendly, formal | professional / casual / friendly / formal |  | One per line: professional, casual, friendly, formal. At least one. |
| Content types offered | `content_ai.types_enabled` | paragraph, title, meta_description, heading, conclusion | paragraph / title / meta_description / heading / conclusion |  | One per line: paragraph, title, meta_description, heading, conclusion. At least one. |
| Creativity (temperature) | `content_ai.temperature` | 0.7 | 0 to 2 |  | Higher is more varied, lower is more predictable. 0 to 2. |
| Longest answer | `content_ai.max_tokens` | 2048 | 256 to 8192 tokens |  | Upper limit on the length of a generated text. |

## Images, sitemaps & redirects

Defaults for new sitemap rows, redirect rules and the image size check, and which 404 paths to ignore.

| Setting | Key | Default | Allowed | Environment variable | Notes |
|---|---|---|---|---|---|
| Image size that counts as optimized | `image.optimal_bytes` | 200000 | 10000 to 10000000 bytes |  | Images smaller than this are marked optimized. |
| Longest good alt text | `image.alt_max_length` | 125 | 20 to 500 characters |  | Shown in the image tips. |
| Default sitemap priority | `sitemap.default_priority` | 0.5 | 0 to 1 |  | For rows added without one (0 to 1). |
| Default sitemap change frequency | `sitemap.default_changefreq` | weekly | always / hourly / daily / weekly / monthly / yearly / never |  | For rows added without one. |
| Pages per crawl by default | `sitemap.default_max_pages` | 100 | 1 to 5000 |  | A crawl can ask for fewer or more; the server limit under Crawling & jobs still applies. |
| Default redirect type | `redirects.default_type` | 301 | 301 / 302 / 307 / 308 / 410 / 451 |  | For rules added without one. |
| Ignore these 404 paths | `monitor.ignore_patterns` | (empty) | list |  | One per line. Start with / and use * as a wildcard (for example /wp-json/*). Matching 404s are not recorded, whether added by hand or pulled from a site. |

## Dashboard & analytics

The date window and the cards shown on the dashboard.

| Setting | Key | Default | Allowed | Environment variable | Notes |
|---|---|---|---|---|---|
| Smallest search opportunity | `search.min_impressions` | 100 | 1 to 100000 impressions |  | Queries with fewer impressions than this over the last 28 days are not listed as opportunities. |
| "Striking distance" starts at position | `search.striking_from` | 8 | 2 to 50 |  | A query ranking from this position down to the next setting is listed as within reach of page one. |
| "Striking distance" ends at position | `search.striking_to` | 20 | 3 to 100 |  | See above. |
| Dashboard date window | `dashboard.window_days` | 30 | 7 to 365 days |  | The dashboard and analytics look back this many days. |
| Cards shown, in order | `dashboard.cards` | seo, keywords, impressions, clicks | seo / keywords / impressions / clicks |  | One per line: seo, keywords, impressions, clicks. At least one. |

## Users & alerts

Defaults for new accounts and which events send an alert.

| Setting | Key | Default | Allowed | Environment variable | Notes |
|---|---|---|---|---|---|
| Default role for new users | `users.default_role` | viewer | viewer / editor / admin |  | Used when an administrator creates a user without choosing a role. |
| Invitation link lifetime | `users.invite_days` | 7 | 1 to 30 days |  | How long an invitation e-mail link works. |
| Password reset link lifetime | `users.reset_minutes` | 60 | 5 to 1440 minutes |  | How long a reset e-mail link works. |

## Monitoring

How often watched pages are checked, and when a change or an outage becomes an alert.

| Setting | Key | Default | Allowed | Environment variable | Notes |
|---|---|---|---|---|---|
| Most watched pages | `watch.max_items` | 100 | 1 to 1000 |  | Pages that can be on the watchlist at once. |
| Checks started per minute | `watch.max_per_tick` | 5 | 1 to 50 |  | Scheduled checks begin at most this many per minute, and never two at once on the same site, so a long list is spread out. |
| Score drop that raises an alert | `watch.score_drop` | 10 | 1 to 100 points |  | Alert when a watched page scores at least this many points lower than at its previous check. |
| Failed checks before a page is "down" | `watch.down_after` | 2 | 1 to 10 |  | A page that cannot be fetched this many times in a row is reported down (once), and again when it recovers. |
| Recheck a failing page after | `watch.retry_minutes` | 15 | 5 to 1440 minutes |  | A page that failed is checked again after this long instead of waiting for its normal interval. |
| Keep watch history for | `watch.retention_days` | 365 | 7 to 3650 days |  | Scheduled analyses older than this are deleted. Manual analyses are kept. |
| Pages per site audit | `audit.max_pages` | 200 | 1 to 500 |  | An audit stops after this many pages (and never exceeds the crawl page limit). |
| Longest site audit | `audit.max_seconds` | 600 | 60 to 1800 seconds |  | An audit stops after this long and reports what it found. |
| Thin page below | `audit.thin_words` | 200 | 20 to 2000 words |  | Pages with fewer words than this are reported as thin content. |
| External links to check | `audit.external_links_max` | 50 | 0 to 500 |  | How many different outside links an audit tests for 404/410 (one request each). 0 turns the check off. |
| Repeat audits every | `audit.repeat_days` | 0 | 0 to 90 days |  | Audit every site automatically this often, one site per minute at most. 0 means only when you start one. |
| Audits kept per site | `audit.keep` | 10 | 1 to 100 |  | Older audits (with their pages and issues) are deleted. |
| Web Vitals on watched pages every | `pagespeed.watch_days` | 7 | 1 to 90 days |  | Watched pages that have Web Vitals switched on are measured this often (one measurement per page per period). |
| Failed health checks in a row before "down" | `health.bad_checks` | 3 | 1 to 10 |  | The health check runs every minute. It reports a problem only after this many bad minutes in a row, so a restart or a blip does not alert. |

## SEO scoring

What counts as a good title, description, speed and content, and how many points each check is worth. The score is the sum of the points, capped at 100.

| Setting | Key | Default | Allowed | Environment variable | Notes |
|---|---|---|---|---|---|
| Shortest good title | `seo.title_min` | 30 | 1 to 200 characters |  | A title shorter than this is not judged optimal. |
| Longest good title | `seo.title_max` | 60 | 1 to 300 characters |  | A title longer than this is not judged optimal. |
| Shortest good description | `seo.meta_min` | 120 | 1 to 400 characters |  | Meta description lower bound. |
| Longest good description | `seo.meta_max` | 160 | 1 to 600 characters |  | Meta description upper bound. |
| Words for a "good" page | `seo.words_good` | 300 | 1 to 20000 words |  | Pages with at least this many words earn the first content points. |
| Words for a "great" page | `seo.words_great` | 1000 | 1 to 50000 words |  | Pages with at least this many words earn the second content points. |
| Images with alt text: high | `seo.alt_high` | 90 | 1 to 100 percent |  | At or above this share of images with alt text earns the most image points. |
| Images with alt text: medium | `seo.alt_mid` | 50 | 1 to 100 percent |  | At or above this share earns the middle image points. |
| Fast page fetch | `seo.load_fast` | 3 | 0 to 60 seconds |  | Faster than this earns the most speed points. |
| Acceptable page fetch | `seo.load_ok` | 5 | 0 to 120 seconds |  | Faster than this earns the middle speed points. |
| Internal links needed | `seo.internal_min` | 5 | 0 to 1000 links |  | A page needs MORE than this many internal links to earn the link points. |
| Points: Title present | `seo.w.title_present` | 5 | 0 to 100 points |  | Points added to the score when this check passes. |
| Points: Title length good | `seo.w.title_ok` | 5 | 0 to 100 points |  | Points added to the score when this check passes. |
| Points: Description present | `seo.w.meta_present` | 5 | 0 to 100 points |  | Points added to the score when this check passes. |
| Points: Description length good | `seo.w.meta_ok` | 5 | 0 to 100 points |  | Points added to the score when this check passes. |
| Points: Exactly one main heading | `seo.w.h1` | 10 | 0 to 100 points |  | Points added to the score when this check passes. |
| Points: Alt text on most images | `seo.w.alt_high` | 15 | 0 to 100 points |  | Points added to the score when this check passes. |
| Points: Alt text on many images | `seo.w.alt_mid` | 10 | 0 to 100 points |  | Points added to the score when this check passes. |
| Points: Alt text on some images | `seo.w.alt_low` | 5 | 0 to 100 points |  | Points added to the score when this check passes. |
| Points: Fast page fetch | `seo.w.load_fast` | 15 | 0 to 100 points |  | Points added to the score when this check passes. |
| Points: Acceptable page fetch | `seo.w.load_ok` | 10 | 0 to 100 points |  | Points added to the score when this check passes. |
| Points: Slow page fetch | `seo.w.load_slow` | 5 | 0 to 100 points |  | Points added to the score when this check passes. |
| Points: Mobile viewport tag | `seo.w.viewport` | 10 | 0 to 100 points |  | Points added to the score when this check passes. |
| Points: Served over HTTPS | `seo.w.https` | 10 | 0 to 100 points |  | Points added to the score when this check passes. |
| Points: Structured data present | `seo.w.schema` | 10 | 0 to 100 points |  | Points added to the score when this check passes. |
| Points: Enough internal links | `seo.w.internal` | 5 | 0 to 100 points |  | Points added to the score when this check passes. |
| Points: Has external links | `seo.w.external` | 5 | 0 to 100 points |  | Points added to the score when this check passes. |
| Points: Enough words | `seo.w.words_good` | 5 | 0 to 100 points |  | Points added to the score when this check passes. |
| Points: Plenty of words | `seo.w.words_great` | 5 | 0 to 100 points |  | Points added to the score when this check passes. |

## Set on the server only (read-only in the app)

These cannot be changed from the Settings page: they are needed to start the app, or they control the request guard.

* `db.host`
* `db.port`
* `db.name`
* `db.user`
* `db.pass`
* `db.prefix`
* `app_key`
* `base_url`
* `allowed_hosts`
* `trusted_proxies`
* `debug`
* `hsts`
* `fetch.allow_ports`
* `fetch.dev_allow_cidrs`
* `gemini.endpoint`
* `session.secure`
* `session.name`
* `limits.body_bytes`
* `log.file`
