# BallSpot

A social football guessing game. Spot the hidden ball. Beat your friends. Play the daily challenge.

> **BallSpot → BallPicker:** the backend now carries a multi-sport foundation so
> the app can broaden beyond football later. Football remains the only *active*
> sport and the default everywhere; the UI is unchanged. See "New in v1.6" below.

## What it is

BallSpot shows football images with the ball hidden. Players tap where they think the ball is and earn points based on accuracy. Play the daily challenge against everyone, create leagues with friends, or climb the weekly leaderboard.

## New in v1.7.2 — Sport Availability, Avatar Upload Fix, and User Rank XP Progression

- **Avatar upload fixed cross-platform.** Uploading a profile photo previously failed on
  Expo **web** with "The avatar field must be a file." — appending a React Native
  `{ uri, name, type }` descriptor to `FormData` on web stringifies to `"[object Object]"`,
  so Laravel saw a string, not a file. `src/api/avatarApi.ts` is now platform-aware: on web
  the picked `blob:`/`data:` URI is fetched into a real `Blob` and appended as a proper
  multipart file part (with a filename); on native the RN descriptor is used. Field name is
  exactly `avatar`; supported types are **JPEG, PNG, WebP** (max 2 MB, SVG rejected). The
  backend `POST /api/me/avatar` behaviour is unchanged — only the friendly error message was
  unified to **"Please choose a JPG, PNG or WebP image under 2MB."**
- **Sport availability statuses.** A new `sports.status` column replaces the old
  activate/deactivate toggle with three states — the **source of truth** for visibility:
  - **`active`** — visible + selectable/playable;
  - **`coming_soon`** — visible in the app but disabled (shows a "SOON" badge and
    "Coming soon");
  - **`hidden`** — never shown to normal users.
  The legacy `is_active` boolean is kept and **mirrored** (`is_active == (status === 'active')`)
  via a model mutator so existing queries keep working. `GET /api/sports` now returns only
  **visible** sports (active + coming_soon; hidden excluded) with `status`, `is_playable`,
  `is_coming_soon`, and `is_active` flags. Setting a preferred sport or creating a tournament
  for a non-active sport returns **422 "This sport is not available yet."** Seeded: Football =
  active; Golf, Tennis, Hockey, Cricket, American Football, Basketball = coming_soon.
- **Admin sport status control.** `POST /admin/sports/{sport}/status` sets a sport's status
  (active/coming_soon/hidden) via a dropdown on the admin Sports page (replaces the old
  toggle). **Football is protected** — it cannot be moved off active ("Football must stay
  active."). Invalid status values are rejected.
- **Personal rank / level / XP progression (distinct from leaderboard rank).** This is
  long-term **personal** progression — how far a player has come overall — *not* the
  leaderboard rank (your position vs. other players), which is separate and unchanged. Six
  ranks are defined in `config('ballspot.ranks')` by minimum lifetime XP: **Rookie** (L1, 0),
  **Amateur** (L2, 2,500), **Pro** (L3, 10,000), **Elite** (L4, 25,000), **Legend** (L5,
  50,000), **Ball Master** (L6, 100,000). `total_xp` currently **equals lifetime score total**
  (sum of tournament `guesses.score` + daily `daily_challenge_guesses.score`); badges do **not**
  add XP. `GET /api/profile/stats` now includes a `rank` object, `GET /api/me/rank` returns it
  standalone, and fresh guess responses (daily + tournament round) include a top-level
  `rank_progress`. Mobile Profile shows a premium **RankCard**; result screens show a small
  **RankProgressCard** ("+N XP") only right after a fresh guess. **Known limitation:** there is
  **no XP transaction/ledger table** — XP is derived on read.
- Backend tests: **189 passing** (was 173; +16). See [docs/test-report.md](docs/test-report.md).

## New in v1.6.2 — Email Verification at Registration + Configurable Login 2FA

This sprint **adjusts** the always-on email 2FA introduced in v1.6.1. Email
verification now happens at **registration**, and normal login is plain
email+password once the email is verified. The 6-digit login 2FA still exists but
is **off by default** and opt-in via config; admins always get login 2FA.

- **Email verification at registration.** `POST /api/register` creates an
  **unverified** account, emails a one-time **6-digit verification code**, and
  returns `{ user, token, email_verified: false }` (HTTP 201). The token lets the
  app drive verification and read `/me`, but full app endpoints are gated until the
  email is verified.
- **New email endpoints (auth required):**
  - `POST /api/email/verify` — body `{ code }`; on success marks the email verified
    and returns `{ email_verified: true, user, message }`. Wrong/expired/consumed
    codes return a generic **422**. 5-attempt lock, hash-only storage, expires after
    **60 minutes**.
  - `POST /api/email/verification-notification` — resends a code (60s cooldown);
    already-verified accounts get a friendly no-op.
- **Access gating.** The `User` model implements `MustVerifyEmail`. Protected app
  endpoints (profile/stats, sports, preferences, avatar, badges, leagues, rounds,
  daily) are behind the `verified` middleware and return **HTTP 403 "Your email
  address is not verified."** for unverified users. An unverified user can still hit
  `/me`, `/logout`, `/account` (delete), and the two email endpoints — inspect,
  verify, or delete, but not play. `GET /api/me` now includes `email_verified`.
- **Login is now email+password.** `POST /api/login` with valid credentials has
  three outcomes:
  1. **Email not verified** → `{ requires_email_verification: true, email_verified:
     false, user, token, message }` (a verification code is re-sent);
  2. **Verified + forced 2FA** (config `force_login_2fa=true` OR the user is an
     admin) → `{ requires_2fa: true, verification_id, message }` (login code emailed;
     complete via `POST /api/login/verify`);
  3. **Verified + no forced 2FA (DEFAULT)** → `{ user, token }` directly.
  Invalid credentials still return a single generic 422 (no enumeration).
- **Login 2FA is opt-in** via `BALLPICKER_FORCE_LOGIN_2FA` (default false). Admins
  always get login 2FA regardless of the flag. Toggle auto-verify + email
  requirement with `BALLPICKER_REQUIRE_EMAIL_VERIFICATION` (default true); the
  verification-code lifetime is `BALLPICKER_EMAIL_CODE_EXPIRY_MINUTES` (default 60).
- **Mail — local dev vs production:**
  - **Local dev:** `MAIL_MAILER=log` writes the full verification/login email
    (**including the code**) to `backend/storage/logs/laravel.log`. Copy the code
    from there when testing. Automated tests use `MAIL_MAILER=array` (faked).
  - **Production:** a deliverable email is **required** to activate an account, so
    real transactional mail is mandatory. Configure `MAIL_MAILER=smtp`, `MAIL_HOST`,
    `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`/`MAIL_SCHEME`,
    and `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME` (or an API mailer).
- See **[docs/security-auth.md](docs/security-auth.md)** for the full write-up.

## New in v1.6.1 — Email Two-Factor Login (superseded by v1.6.2)

> The always-on login 2FA below is now **opt-in** (see v1.6.2). Login 2FA is off by
> default; when `force_login_2fa` is enabled (or the user is an admin), login uses
> the same 6-digit code flow described here.

- **Login could require an emailed code.** When forced, `POST /api/login` emails a
  one-time **6-digit code** and returns `{ requires_2fa: true, verification_id,
  message }`. The user submits that code to `POST /api/login/verify` to receive their
  token; `POST /api/login/resend-code` re-issues a code (60s cooldown). Codes are
  stored **hashed only**, expire after 10 minutes, lock after 5 wrong attempts, and
  errors are generic (no email enumeration).
- **Local dev:** with `MAIL_MAILER=log`, the full verification email (including the
  code) is written to `backend/storage/logs/laravel.log`.
- See **[docs/security-auth.md](docs/security-auth.md)** for the full write-up.

## New in v1.7 — Sport Selection, Themes, and Profile Avatar

- **Sport selection & onboarding** — new `sports` API (`GET /api/sports`) and per-user
  preferences (`GET`/`PATCH /api/me/preferences`). A **Sport Selection** screen is shown
  after register (always) and after login/app-start when the user has no preferred sport.
  Active sports are selectable; inactive sports show "Coming soon" and are disabled. The
  chosen sport persists to the backend and can be changed again from Profile ("Change
  sport") and the Home sport chip. Football remains the default when no preference is set.
- **App themes** — an extensible allow-list of 5 themes (`classic`, `tournament_blue`,
  `pitch_green`, `sunset_orange`, `high_contrast`) defined in `config/ballspot.php` and a
  mobile `ThemeProvider` (React context). The selected theme is persisted to AsyncStorage
  (`ballspot_theme`), synced to the backend via `PATCH /api/me/preferences`, and re-applied
  from the server on login. A theme picker lives in Profile with 5 theme cards applied
  immediately. **"Tournament Blue" disclaimer:** this theme is original styling inspired by
  the general energy/vibe of televised European sport nights (deep navy, turquoise, vivid
  red accent, cool silver). It is **NOT UEFA branding** and uses no UEFA logos, names, or
  protected assets.
- **Profile avatar upload** — `POST`/`DELETE /api/me/avatar`. Users can upload a photo
  (jpeg/jpg/png/webp, max 2 MB; SVG rejected) stored on the public disk under `avatars/`
  with a randomized name. Uploading replaces (and best-effort deletes) the previous avatar
  only if it lives under `avatars/` — challenge images are never touched. Profile shows the
  avatar (or initials) with "Change photo" (via `expo-image-picker`); Home shows a small
  avatar in the top bar.
- **Sport-aware daily challenge** — `GET /api/daily/today` resolves the sport from
  `?sport=<slug>`, else the user's preferred sport, else football. If today's (single
  global) daily challenge does not match the requested sport, it returns a clean no-daily
  payload so the app can say e.g. "No daily challenge for Tennis today. Try Football."
  Football-first behaviour is unchanged for users with no preference. **Limitation:** there
  is still only ONE global daily challenge per date; true simultaneous per-sport dailies is
  a future enhancement requiring a schema change.
- **Sport-aware tournaments** — `POST /api/leagues` accepts an optional `sport_id` (must be
  active); precedence is explicit `sport_id` → user's preferred sport → football. Rounds
  only draw challenges from the tournament's sport, and `LeagueResource` now includes a
  `sport` object.
- **Schedule command `--sport`** — `php artisan ballspot:schedule-daily-challenges` gained
  `--sport=<slug>`. Omitting it is unchanged (all active challenges); providing it fills the
  schedule from only that sport's active challenges. An unknown slug fails with a friendly
  error. Default (no flag) is football-safe.
- **Admin sport activation** — new `GET /admin/sports` index + `POST
  /admin/sports/{sport}/toggle` to activate/deactivate sports. **Football cannot be
  deactivated.** Challenge create/edit label inactive sports "(inactive)" and carry a tag
  guidance note (use text tags like country/team/league/moment type; no copyrighted logos).

See "New in v1.6" below for the prior sprint.

## New in v1.6 — Gamification, Leaderboards, Password Reset, Multi-Sport Foundation

- **Password reset** — `POST /api/forgot-password` and `POST /api/reset-password`
  (Laravel password broker). Forgot-password always returns a generic success (no
  email enumeration); reset revokes existing API tokens. Mobile Forgot/Reset
  screens. With `MAIL_MAILER=log` the reset link is written to
  `storage/logs/laravel.log` for local dev.
- **Leaderboard rank + percentile** — daily & tournament results return `rank`,
  `total_players`, `better_than_percentage` ("closer than X% of players"). Weekly
  and tournament leaderboards return a `meta` block (current-user rank/score,
  better-than-%, top & nearby users). Mobile: "You are #X of Y" card and a
  Top / My-rank toggle.
- **Trophy Room & virtual badges** — `badges` / `user_badges`, idempotent
  `BadgeService`, `GET /api/badges` + `GET /api/me/badges`. 11 seeded virtual
  trophies (emoji, no copyrighted assets). Awarded on guesses; "New badge
  unlocked!" card on result screens; Trophy Room in Profile. **Virtual only — no
  real prizes.**
- **Multi-sport foundation** — `sports` gains emoji/color/active/scoring_profile;
  7 sports seeded (football active, others inactive scaffolding). Admin challenge
  create/edit gains a sport picker. `ScoreService` documents the future
  per-sport scoring hook.
- **Tags / subcategories** — `tags` + `challenge_tag`; admin comma-separated text
  tags; daily API exposes challenge sport + tags; mobile shows sport badge + tag chips.
- **Free tournament limits** — configurable max created (3) & max players (8) with
  premium placeholders. **No payments/IAP.**

See [docs/notifications-plan.md](docs/notifications-plan.md) and
[docs/prizes-and-trophy-room.md](docs/prizes-and-trophy-room.md) for
foundation-only plans (not implemented this sprint).

## Structure

```
backend/   Laravel 12 REST API + Blade admin area
mobile/    Expo 56 React Native (iOS + Android)
docs/      API contract, database schema, test report
```

## Quick Start

### Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan serve
# → http://127.0.0.1:8000
# Admin: http://127.0.0.1:8000/admin/login
# Admin credentials: admin@ballspot.local / password
```

### Mobile

```bash
cd mobile
npm install
cp .env.example .env       # edit EXPO_PUBLIC_API_BASE_URL if needed
npx expo start
# Press: a=Android  i=iOS  w=Web
```

**Physical device:** set `EXPO_PUBLIC_API_BASE_URL=http://<LAN-IP>:8000/api` in `mobile/.env`, start backend with `php artisan serve --host=0.0.0.0`, then `npx expo start --host=lan`.

## Tests

```bash
cd backend && php artisan test          # 189 feature tests
cd mobile && npx tsc --noEmit          # 0 TypeScript errors
```

## Daily Challenge Quick Start

1. Start the backend and log in to the admin panel at `/admin/login`
2. Go to `/admin/challenges` and ensure at least one challenge has status = **active**
3. Go to `/admin/daily` and create a daily challenge for today (or run `php artisan db:seed --class=DailyChallengeSeeder`)
4. Open the mobile app — the Daily Ball Challenge card appears on the Home screen
5. Tap **Play Daily Challenge** to guess, then see your score and the weekly leaderboard

## Scheduling Daily Challenges

Fill the next 14 days automatically using the least-recently-used eligible challenges:

```bash
cd backend

# Dry-run first — see what would be scheduled without writing:
php artisan ballspot:schedule-daily-challenges --days=14 --dry-run

# Run backup before scheduling real content:
php artisan ballspot:backup-content

# Schedule 14 days from today (skips dates that already have a challenge):
php artisan ballspot:schedule-daily-challenges --days=14

# Force-replace existing scheduled challenges:
php artisan ballspot:schedule-daily-challenges --days=14 --force

# Start from a specific date:
php artisan ballspot:schedule-daily-challenges --days=7 --start=2026-07-01

# Restrict the schedule to a single sport's active challenges (v1.7):
php artisan ballspot:schedule-daily-challenges --days=14 --sport=football
php artisan ballspot:schedule-daily-challenges --days=14 --sport=tennis
```

**Notes:**
- `--sport=<slug>` (v1.7): when omitted, behaviour is unchanged (all active challenges).
  When provided, only that sport's active challenges fill the schedule; an unknown slug
  fails with a friendly error. Default (no flag) is football-safe because football is the
  only active sport with content.
- Only `active` challenges with a hidden image and ball position are eligible.
- Demo challenges are used as fallback if no real content exists (a warning is printed).
- New challenges are created with `status=scheduled`. Change to `active` in the daily admin when ready to go live.
- The command never deletes challenges or images.
- Run a backup before bulk-scheduling real content.

## Tournament Daily Round Limits

- Each tournament has a `rounds_per_day` setting (1 or 3)
- The `GET /leagues/{id}/current-round` endpoint enforces this per user per UTC calendar day
- If a user has played all allowed rounds today, the endpoint returns `reason: daily_limit_reached`
- The mobile app hides the Play button and shows a "Done for today" message in that state
- Limits reset at UTC midnight

## Content Safety

Before adding real challenge images or running any destructive database commands:

```bash
cd backend && php artisan ballspot:backup-content
```

The `backups/` folder is listed in `.gitignore` — backups are never committed. See [docs/content-safety.md](docs/content-safety.md) for full backup, restore, and recovery instructions.

## Account Deletion

Users can delete their account from the Profile screen (Settings → Delete account). A confirmation modal is shown before deletion. On confirm:

1. `DELETE /api/account` is called — account is anonymized, all tokens revoked
2. The stored token is cleared from the device
3. The app navigates back to the Login screen

This satisfies Google Play and Apple App Store account deletion requirements.

## Legal Pages

The backend serves three public legal pages (no auth required):

| URL | Content |
|-----|---------|
| `/privacy` | Privacy Policy |
| `/terms` | Terms of Service |
| `/support` | Support & Contact |

Set `BALLSPOT_WEB_URL` in `backend/.env` (and `EXPO_PUBLIC_WEB_URL` in `mobile/.env`) to your deployed domain so the mobile app's legal links point to the correct URLs.

## Store Readiness Check

```bash
cd backend
php artisan ballspot:store-readiness-check
```

Prints a read-only report covering environment config, content readiness, daily challenge schedule, storage setup, and public legal routes. Exit code 0 = OK for internal testing; exit code 1 = infrastructure failures that must be fixed. See [docs/store-readiness.md](docs/store-readiness.md) for the full checklist.

## Docs

- [API Contract](docs/api-contract.md)
- [Security & Authentication](docs/security-auth.md)
- [Database Schema](docs/database-schema.md)
- [Test Report](docs/test-report.md)
- [Content Safety Guide](docs/content-safety.md)
- [Challenge Content Guide](docs/challenge-content-guide.md)
- [Store Readiness](docs/store-readiness.md)
- [Notifications Plan (foundation only)](docs/notifications-plan.md)
- [Prizes & Trophy Room](docs/prizes-and-trophy-room.md)

## Password Reset (local dev)

`MAIL_MAILER=log` by default, so reset emails are written to
`backend/storage/logs/laravel.log` rather than sent. To test the flow locally:

1. `POST /api/forgot-password` with `{ "email": "..." }` — always returns a generic success.
2. Open `storage/logs/laravel.log` and copy the reset link (contains `token` + `email`).
3. `POST /api/reset-password` with `email`, `token`, `password`, `password_confirmation`
   (or use the mobile Reset Password screen). Old API tokens are revoked on success.

## Constraints

- No real money, no gambling, no subscriptions, no ads, no in-app purchases
- No chat, no AI, no realtime/websockets
- Football is the only **active** sport by default (multi-sport foundation exists in the
  backend; other sports require admin activation + content before they are selectable)
- Themes and sport selection add no new store risk; the "Tournament Blue" theme is original
  styling and uses no UEFA logos, names, or protected assets
- Trophies are **virtual only** — no real prizes
- Score calculation backend-only (never trust client)
- Positions stored as ratios 0..1 (device-independent)
- All date/day logic uses UTC
