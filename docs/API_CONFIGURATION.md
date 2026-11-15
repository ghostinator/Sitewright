# Configuration reference

All settings live in `config/config.php` (template: `config/config.example.php`) or `RMW_*` environment variables (environment wins). See the README for the full variable table.

## Gemini (optional, Content AI)

1. Create an API key in Google AI Studio. Keep it private; **never commit it**. If a key was ever committed, revoke it and create a new one.
2. Set `RMW_GEMINI_API_KEY` (or `gemini.api_key` in `config/config.php`).
3. Optionally set `RMW_GEMINI_MODEL`. Model names are retired regularly; check Google's current model list. The default is `gemini-3.6-flash`. `GET /api/content-ai/status` reports whether a key is configured without calling Google.

The key is sent in the `x-goog-api-key` request header. If Gemini fails, the UI says so and shows template text clearly marked as a fallback.

## Not implemented

Google Search Console / OAuth and SEMrush integrations do **not** exist. Keyword metrics are added with `POST /api/analytics/keyword` or imported as JSON with `POST /api/analytics/import-keywords` (max 5,000 rows).

## Key API facts

* All routes except `GET /api/health` and `POST /api/auth/login` require a session. Non-GET requests need `Content-Type: application/json` and an `X-CSRF-Token` header (token from `GET /api/auth/me` or the page's embedded config).
* Errors are `{"success":false,"message":"…"}`; server errors add a `request_id` that matches the PHP error log.
