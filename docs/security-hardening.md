# BallPicker — Security Hardening

**Sprint:** v1.8.1 — Security, Privacy & Test Readiness.
Companion docs: docs/security-auth.md (auth flows), docs/privacy-data-inventory.md,
docs/test-readiness-checklist.md.

## Rate limits

Named limiters live in `backend/app/Providers/AppServiceProvider.php` and are
applied via `throttle:` middleware (`routes/api.php`, `routes/web.php`). A
global `throttle:api` (via `throttleApi()` in `bootstrap/app.php`) covers every
API route; stricter route-level limiters stack on top of it.

| Limiter | Applied to | Limit |
|---|---|---|
| `api` (global) | every `routes/api.php` route | 120/min per user (IP pre-auth) |
| `login` | `POST /api/login` | 5/min per email+IP, 20/min per IP |
| `login-verify` | `POST /api/login/verify` | 20/min per verification_id+IP |
| `login-resend` | `POST /api/login/resend-code` | 5/min per verification_id+IP |
| `register` | `POST /api/register` | 5/min + 20/hour per IP |
| `forgot-password` | `POST /api/forgot-password` | 3/min per email+IP, 10/min per IP |
| `reset-password` | `POST /api/reset-password` | 5/min per IP |
| `email-verify` | `POST /api/email/verify` | 10/min per user |
| `email-resend` | `POST /api/email/verification-notification` | 3/min per user |
| `gameplay` | daily / tournament / pack guess submits | 30/min per user |
| `push-tokens` | `POST` + `DELETE /api/me/push-tokens` | 10/min per user |
| `export` | `GET /api/me/export` | 5/hour per user |
| `admin-login` | `POST /admin/login` | 5/min per IP |
| `admin-send` | `POST /admin/notifications/{id}/send` | 10/min per admin |

Read-heavy endpoints (leaderboards, packs, ranks, stats, XP events,
notification settings) ride the global `api` limiter — high enough for normal
play, a hard cap for scraping.

**429 response shape (API):**

```json
{ "message": "Too many requests. Please try again in 42 seconds.", "retry_after": 42 }
```

Rendered by the `ThrottleRequestsException` handler in `bootstrap/app.php`;
`Retry-After` is also sent (and exposed via CORS) — the mobile client reads it
and shows "Too many attempts. Try again in N seconds."

The per-code 5-attempt locks and 60s resend cooldowns (docs/security-auth.md)
remain the primary guess-stoppers; throttles bound automated fan-out.

## Response caps / database protection

- Weekly/monthly leaderboard: list capped at **100 entries**
  (`DailyChallengeController::LEADERBOARD_MAX_ENTRIES`); `meta` (total players,
  your rank, nearby users) is still computed from the full field.
- Trophy Room lists (tournament/competition finishes, pack completions):
  capped at **100 rows** (`ProfileController::MAX_LIST_ROWS`).
- XP events: `limit` clamped to **1–50** (default 20).
- Data export: each list capped at 1000 rows.
- Stats endpoints use SQL aggregates (`COUNT/AVG/MAX/SUM`) — full guess
  history is never loaded into memory.
- League leaderboard: `LIMIT 100` safety net (tournaments cap at 8 players).
- Admin packs index paginates (25/page); challenges/daily/notifications
  admin lists were already paginated.
- Indexes added for SQLite (which does NOT auto-index FK columns):
  `guesses(user_id)`, `daily_challenge_guesses(user_id)`,
  `league_rounds(league_id, status)`, `league_members(user_id)`
  (migration `2026_07_30_000001_add_missing_query_indexes`).

## Sensitive storage decisions

- Passwords: bcrypt (framework default). Never encrypted/plaintext.
- Login/email verification codes: bcrypt hash only, expiring, attempt-locked
  (docs/security-auth.md).
- Password reset tokens: Laravel's hashed `password_reset_tokens` store.
- API tokens: Sanctum SHA-256; TTL configurable via
  `SANCTUM_TOKEN_EXPIRATION_MINUTES` (recommended 129600 = 90 days in
  production; empty = never expires, dev default).
- **Push tokens stay plaintext at rest by design**: Expo requires the raw
  value on every send, so encrypted-at-rest would put the decryption key next
  to the data with no security gain for an online attacker. Compensating
  controls: `$hidden` on the model, no API ever returns a token value, rows
  deleted on logout/account-deletion, stale rows pruned after 90 days by
  `ballspot:cleanup-login-codes`.
- `users.is_admin` is in `$hidden` — a serialized User can never advertise
  admin targets.
- Logging: the only `Log::` call in the app logs an Expo error message, never
  tokens/codes/personal payloads. Keep it that way — never log request bodies
  on auth routes.

## Security headers

`App\Http\Middleware\SecurityHeaders` (appended globally) sets on every response:

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy: camera=(), geolocation=(), microphone=()`

**CSP is intentionally NOT set** — the admin Blade views use inline
styles/scripts and would break. Recommended production CSP for the web-served
pages once admin assets are cleaned up:

```
Content-Security-Policy: default-src 'self'; img-src 'self' data:;
  style-src 'self' 'unsafe-inline'; script-src 'self';
  frame-ancestors 'self'; base-uri 'self'; form-action 'self'
```

Serve HSTS (`Strict-Transport-Security: max-age=31536000; includeSubDomains`)
at the reverse proxy once HTTPS is confirmed stable.

## CORS

`backend/config/cors.php` reads `CORS_ALLOWED_ORIGINS` (comma-separated).
Local dev default is `*`; production MUST list the deployed web origin(s).
`supports_credentials` stays `false` (bearer-token API). `Retry-After` is an
exposed header.

## Admin hardening

- All admin routes behind the `admin` middleware (session auth + `is_admin`);
  CSRF active on every admin form.
- Admin web login throttled (5/min per IP); admins always get login 2FA on
  the API path.
- Uploads restricted to `jpeg,jpg,png,webp`, max 5 MB (SVG was already
  excluded by Laravel 12's `image` rule; gif/bmp now rejected too).
- Announcements are plain text (validated fields, escaped Blade output).
- The three `{!! !!}` usages in `admin/daily/create.blade.php` interpolate
  hardcoded strings only — no user input reaches unescaped output.

**Recommended next (not built this sprint):** an `admin_audit_logs` table
recording admin id, action, subject type/id, and timestamp for challenge,
pack, notification and status changes. Keep it append-only.

## Account deletion & export

- `DELETE /api/account`: revokes all tokens → deletes avatar file → deletes
  push tokens, notification settings, pending verification codes → anonymizes
  the user row (name/email/username/password). Gameplay history is retained
  against the anonymized row (disclosed in the privacy policy).
- `GET /api/me/export` (auth, not verified-gated, 5/hour): full JSON export,
  never includes password hash, tokens, or raw push-token values.
- `DELETE /api/me/push-tokens`: body `{ "token": "..." }` removes that
  device's registration (scoped to the caller); empty body removes all of the
  caller's registrations. The app calls this on logout.

## Production environment checklist

See docs/test-readiness-checklist.md — in short: `APP_ENV=production`,
`APP_DEBUG=false`, `LOG_LEVEL=warning`, HTTPS everywhere,
`SESSION_SECURE_COOKIE=true`, strong `APP_KEY`, real mail, `storage:link`,
restricted `CORS_ALLOWED_ORIGINS`, Sanctum TTL, cron for the three artisan
maintenance commands, backups before testers arrive.

## Mobile storage model

- Auth token: `expo-secure-store` on iOS/Android. **Web fallback is
  `sessionStorage`** — XSS-readable and cleared when the tab closes; accepted
  for the beta, HttpOnly-cookie session is the long-term fix.
- Theme + "notification prompt seen" flag: non-sensitive, cleared on
  logout/deletion by `src/app/signOut.ts` (which also cancels scheduled local
  reminders and removes the device push registration).
- No password, reset token, or profile data is ever persisted on device.
- The app contains zero `console.log` calls and zero analytics/tracking SDKs.
