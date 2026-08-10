# BallPicker — Security Hardening

**Sprint:** v1.8.1 — Security, Privacy & Test Readiness.
**Last updated:** 2026-08-05 pre-launch security & privacy audit (new rate
limiters, gameplay-integrity guards, registered scheduler, deploy-time env
requirements, and an honest list of remaining risks).
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
| `login` | `POST /api/login` | 5/min per email+IP, 20/min per IP, **50/hour per email (IP-independent)** |
| `login-verify` | `POST /api/login/verify` | 20/min per verification_id+IP |
| `login-resend` | `POST /api/login/resend-code` | 5/min per verification_id+IP |
| `register` | `POST /api/register` | 5/min + 20/hour per IP |
| `forgot-password` | `POST /api/forgot-password` | 3/min per email+IP, 10/min per IP |
| `reset-password` | `POST /api/reset-password` | 5/min per IP |
| `email-verify` | `POST /api/email/verify` | 10/min per user |
| `email-resend` | `POST /api/email/verification-notification` | 3/min per user |
| `gameplay` | daily / tournament / pack guess submits | 30/min per user |
| `friends` | `POST`/`DELETE` friend endpoints | 20/min per user (IP fallback) |
| `push-tokens` | `POST` + `DELETE /api/me/push-tokens` | 10/min per user |
| `uploads` | `POST /api/me/avatar` | 6/min per user |
| `profile-lookup` | `GET /api/users/{user}/public-profile` | 30/min per user + 300/hour per IP |
| `export` | `GET /api/me/export` | 5/hour per user |
| `admin-login` | `POST /admin/login` | 5/min per IP |
| `admin-send` | `POST /admin/notifications` (create, which can `send_now`) and `POST /admin/notifications/{id}/send` | 10/min per admin |

Read-heavy endpoints (leaderboards, packs, ranks, stats, XP events,
notification settings) ride the global `api` limiter — high enough for normal
play, a hard cap for scraping.

**Known gap — public-profile enumeration (reduced, not eliminated).**
`GET /api/users/{user}/public-profile` takes a sequential user id, so an
authenticated account can walk the id space and harvest every player's username,
display name, avatar URL, rank/XP and aggregate stats. No private field is
exposed (see `PublicProfileTest`) and the data largely matches what leaderboards
already show, so this is a scraping concern rather than a leak.

Previously it rode only the global `api` limiter (120/min ≈ 170k profiles/day).
It now carries the dedicated `profile-lookup` limiter (**30/min per user +
300/hour per IP**), which is invisible at realistic browsing speeds but caps
bulk harvesting at ~7.2k profiles/day per IP. The endpoint is still enumerable
by id, and a **deleted (anonymized) account still returns a public profile**
with its retained gameplay stats under the "Deleted User" identity. The stronger
mitigation — restricting the endpoint to friends plus people you share a
tournament with — is a product decision and is **not** applied.

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

## Gameplay integrity (pre-launch audit)

Throttles bound fan-out; these guards stop a client from writing states the
game rules never intended. All are covered by `SecurityRegressionTest`.

- **Daily challenges are today-only.** `DailyChallengeController::guess` now
  rejects any daily whose `challenge_date` is not today. It previously checked
  only `status`, so a client could submit guesses against past or future
  dailies — enough to fabricate a streak and to farm the monthly competition.
- **Tournament guesses require an active tournament.** `RoundController::submitGuess`
  now rejects a guess when `league->status !== 'active'`; **cancelled tournaments
  were still accepting guesses.**
- **`rounds_per_day` is enforced on the write path.** It was previously enforced
  only when reading `current-round`, so a client that skipped that call could
  burn every round of a tournament in one sitting.
- **Pack replays award no XP.** `PackPlayService` awards nothing when a user
  replays a pack they have already completed. The XP ledger dedupes on
  per-attempt ids, so every replay previously re-awarded the full guess +
  completion XP.
- **Push tokens are format-validated and capped.** `RegisterPushTokenRequest`
  requires `/^Expo(nent)?PushToken\[[A-Za-z0-9._-]{1,128}\]$/`;
  `PushTokenController` caps a user at `MAX_TOKENS_PER_USER` (10) registrations
  while still letting an already-registered device re-register at the cap.
- **Dead admin route removed.** `Route::resource('challenges', ...)` is now
  `->except(['show'])` — the `show` route had no controller method and returned
  a 500.

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
- **No "remember me" on admin web login.** `Admin\AuthController` previously
  called `Auth::attempt(..., remember: true)` unconditionally, minting a
  ~400-day recaller cookie for the single most privileged account on the
  system. Removed — an admin session now expires with `SESSION_LIFETIME`.
- **Admin web login requires a verified email**, matching the API path.
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
  push tokens, notification settings, pending verification codes → **deletes
  friendships and friend requests (both directions) and nulls `friend_code`**
  → **clears `is_admin` and `email_verified_at`, and deletes the user's rows
  from the `sessions` table** → anonymizes the user row
  (name/email/username/password). Gameplay history is retained against the
  anonymized row (disclosed in the privacy policy). Clearing `is_admin` matters
  because the row survives: a deleted admin would otherwise leave a privileged,
  password-randomized account in place; deleting `sessions` rows stops a live
  admin web session outliving the deletion.
  - `terms_accepted_at` / `terms_version` are **deliberately kept** on the
    anonymized row as the accountability record that consent was given
    (docs/privacy-data-inventory.md).
  - **Invariant:** deletion anonymizes rather than removes the `users` row, so
    `ON DELETE CASCADE` never fires. Every new table referencing `users` must be
    added to this teardown explicitly or it will survive an erasure request.
    `AccountDeletionTest` is the regression guard.
- `GET /api/me/export` (auth, not verified-gated, 5/hour): full JSON export,
  never includes password hash, tokens, or raw push-token values. Includes the
  caller's own `friend_code`, friends and pending requests; for the counterpart
  of a friendship/request it returns username + display name only, never their
  email or friend code.
- `DELETE /api/me/push-tokens`: body `{ "token": "..." }` removes that
  device's registration (scoped to the caller); empty body removes all of the
  caller's registrations. The app calls this on logout.
- **Password reset also destroys web sessions.** `PasswordResetController` now
  deletes the user's rows from the `sessions` table in addition to revoking
  Sanctum tokens. Its docblock already claimed sessions were invalidated; they
  were not, so a stolen admin session survived the password change that was
  meant to end it.

## Scheduled maintenance (cron)

`backend/routes/console.php` now registers the maintenance schedule via the
`Schedule` facade (previously **nothing was scheduled at all**, which meant the
retention promises in the privacy policy were not actually being kept — stale
login codes, dead push tokens and expired API tokens simply accumulated).

| Command | Cadence | Why it is a privacy/security control |
|---|---|---|
| `ballspot:cleanup-login-codes` | hourly | Purges expired/consumed login + email codes and push tokens unseen for 90 days |
| `ballspot:schedule-daily-challenges` | daily 00:05 | Keeps a daily available (availability, not privacy) |
| `ballspot:close-competition` | monthly, 1st at 00:15 | Closes the previous period and awards finishes |
| `sanctum:prune-expired --hours=24` | daily | Removes expired API tokens from `personal_access_tokens` |
| `ballspot:send-daily-reminders` | every 15 min | Daily Challenge reminder push (v1.8.6). No-op unless `BALLPICKER_DAILY_REMINDER_PUSH_ENABLED=true`; respects opt-out, skips played/anonymized/token-less users, at-most-once per user per daily, prunes `DeviceNotRegistered` tokens |

`withoutOverlapping()` is applied where relevant.

**One server cron entry is required** or none of the above runs:

```
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

Verify after deploy with `php artisan schedule:list` (5 entries as of v1.8.6) and
`php artisan ballspot:send-daily-reminders --dry-run` (reports candidates without
sending).

## Production environment checklist

See docs/test-readiness-checklist.md for the full list. The settings below are
the ones that are **security-relevant and wrong by default** — a dev-default
value here is a production vulnerability, not just a misconfiguration.

| Setting | Required value | What goes wrong otherwise |
|---|---|---|
| `APP_DEBUG` | `false` | Stack traces, env values and DB queries served to anyone who triggers an error |
| `APP_ENV` | `production` | Debug tooling and dev-friendly defaults stay enabled |
| `SANCTUM_TOKEN_EXPIRATION_MINUTES` | `129600` (90 days) | **Blank by default = tokens never expire.** A leaked mobile token is valid forever |
| `SESSION_SECURE_COOKIE` | `true` | Admin session cookie can be sent over plaintext HTTP |
| `CORS_ALLOWED_ORIGINS` | the real web origin(s) | Dev default is `*` |
| `MAIL_MAILER` | a real transactional mailer | **Defaults to `log`** — 2FA, verification and password-reset emails are silently swallowed *and* the codes are written into log files |
| Trusted proxies | configured in `bootstrap/app.php` | Behind a load balancer every request appears to come from one IP, which collapses every IP-keyed rate limit |
| HSTS + HTTPS redirect | set at the reverse proxy | No HSTS header and no HTTPS forcing exists in the app |
| nginx `client_max_body_size` | **≥ 6M** | Avatar/challenge uploads (5 MB limit plus multipart overhead) fail with a proxy-level 413 that never reaches Laravel's validation |
| `LOG_LEVEL` | `warning` | Noise, and more chance of sensitive payloads reaching disk |

Also: strong `APP_KEY`, `php artisan storage:link`, the cron entry above, and
backups taken before testers arrive.

## Remaining risks (known, accepted, not fixed)

Recorded honestly so they are decisions rather than surprises.

**Auth / session**

- **Admin web login has no second factor.** The API path forces 2FA for admins;
  the `/admin/login` form does not. Recommended follow-up: route `/admin/login`
  through the existing `LoginVerificationService`.
- **No "log out all devices" endpoint.** Combined with a blank
  `SANCTUM_TOKEN_EXPIRATION_MINUTES`, a user who suspects a stolen device has no
  self-service remedy.

**Infrastructure**

- **No trusted-proxy configuration.** `bootstrap/app.php` has no
  `trustProxies`. It must be set to match the real topology before going behind
  a load balancer, or every IP-keyed limiter above becomes a single global
  bucket.
- **No HSTS and no in-app HTTPS forcing** — delegated to the reverse proxy and
  currently undocumented there.

**Privacy surface**

- **Public profiles remain enumerable** by sequential integer user id (now rate
  limited, see above), and **anonymized accounts still return a public profile**
  with retained gameplay stats.
- **A rejected friend request can be re-sent indefinitely** — there is no block
  or ignore feature.
- **No in-app report/block UI.** Reporting objectionable content (avatars,
  usernames) goes through the support email documented in `/terms` only.

**Push notifications**

- **Expo `DeviceNotRegistered` receipts are not processed**, so dead tokens
  linger until the 90-day prune.
- **Push tokens are not revoked server-side on logout** — the client calls
  `DELETE /api/me/push-tokens` best-effort, so an offline logout leaves the row
  until the 90-day prune.
- **Announcement sending is synchronous inside the admin request with no
  idempotency guard.** A timeout mid-fan-out followed by a retry can double-send
  to every device.

## Mobile storage model

- Auth token: `expo-secure-store` on iOS/Android. **Web fallback is
  `sessionStorage`** — XSS-readable and cleared when the tab closes; accepted
  for the beta, HttpOnly-cookie session is the long-term fix.
- Theme + "notification prompt seen" flag: non-sensitive, cleared on
  logout/deletion by `src/app/signOut.ts` (which also cancels scheduled local
  reminders and removes the device push registration).
- No password, reset token, or profile data is ever persisted on device.
- The app contains zero `console.log` calls and zero analytics/tracking SDKs.
