# BallSpot

A social football guessing game. Spot the hidden ball. Beat your friends. Play the daily challenge.

> **BallSpot → BallPicker:** the backend now carries a multi-sport foundation so
> the app can broaden beyond football later. Football remains the only *active*
> sport and the default everywhere; the UI is unchanged. See "New in v1.6" below.

## What it is

BallSpot shows football images with the ball hidden. Players tap where they think the ball is and earn points based on accuracy. Play the daily challenge against everyone, create leagues with friends, or climb the weekly leaderboard.

## New in v1.7.7 — Tournament Completion, Winner XP & Trophy Finishes

Tournaments now finish and reward players — all **virtual** (badges + XP ledger), no prizes/money:

- **Completion** — a tournament completes when it is `active` and **every member has played every
  round**. Checked after each round guess; the finishing guess completes it **exactly once**
  (atomic `active → completed`). Standings tie-break: total score → earliest completion → user id.
- **Winner / top-3** — placement 1 gets the `tournament_winner` badge; placements 1–3 get
  `podium_finish` (new 🥉 badge). Placement XP via the ledger (**1st +1000, 2nd +500, 3rd +250**),
  deduped once per user per tournament, counted toward `xp_gained` (can trigger a rank-up).
- **Result screen** shows a completion card ("You finished 1st of 8", "+1000 XP").
- **Trophy Room** gains a **"Tournament trophies"** section listing your placements
  (`GET /api/me/tournament-finishes`); empty state "No tournament trophies yet." Standings are
  stored in the new `tournament_finishes` table.
- `POST /rounds/{id}/guess` returns a `tournament_completion` payload only on the finishing guess
  (never on result reopen).

## New in v1.7.6 — Home Cleanup, Tournament Delete Modal & Leaderboard UX

A polish/UX pass (no gameplay, XP, rank or badge logic changes):

- **Home header** — dropped the duplicate native "BallPicker" title bar; the horizontal
  `BallPickerHeader.png` is now the sole top hero.
- **Tournament delete modal** — Home deletes use a custom `ConfirmModal` with status-aware copy
  (**Delete lobby?** for lobbies, **Delete tournament?** for active), a loading state, an in-modal
  error, and optimistic list removal. Backend reuses the existing `DELETE /leagues/{id}`
  **soft-cancel** (status → `cancelled`, owner-only) — no hard deletes.
- **Weekly leaderboard** — single continuous list with a "You are #X of Y" summary, a highlighted
  current-user row, and **Top** / **My rank** jump buttons; no-rank users see "Play a round to
  enter the leaderboard."
- **Trophy Room** is now easier to find — a **"Trophy Room" card** on Profile opens a dedicated
  `TrophyRoom` screen (reuses the existing badges component; nothing rebuilt).
- **Period-label prep** — `config('ballspot.leaderboard.period_label')` (default "Weekly") is echoed
  as `period_label` in the weekly leaderboard response and rendered by the app, so a future switch to
  "Monthly" is a config + window change rather than a string hunt.
- **Scoring review** — `ScoreService` (max 100, `score = max(0, round(100 − distance×250))`) was
  reviewed: a perfect 100 needs distance ≤ ~0.2% (very rare/prestigious, but not literally 0),
  Almost Perfect (≥95) needs ≤ ~2.2% (achievable). No bug; scoring left unchanged. See
  `docs/test-report.md` for the full analysis and future-tuning notes.

## New in v1.7.4 — Branding, Rank Overview & Badge Expansion

- **BallPicker branding.** The Home screen now leads with the horizontal
  `mobile/assets/BallPickerHeader.png` wordmark as its hero header (scales with
  `resizeMode="contain"`, compact height, rounded bottom). Visible app copy reads
  **BallPicker** (Home, Login, native title, theme label). **BallSpot still exists
  internally** — `ballspot:*` artisan commands, `ballspot_*` storage keys, namespaces
  and config keys are intentionally unchanged for backward compatibility.
- **Rank overview.** Profile has a new **"View all ranks"** card → a `RankOverviewScreen`
  listing every rank, its level and minimum XP, with the user's current rank highlighted,
  completed ranks checked, future ranks showing "N XP needed", and the top marked "Max rank".
  Thresholds come from the backend via **`GET /api/ranks`** (source of truth =
  `config('ballspot.ranks')`), so the app never duplicates rank logic.
- **Recent XP is capped at 5** on Profile (`GET /api/me/xp-events?limit=5`, clamped
  server-side), with a clean "No XP activity yet." empty state.
- **Badge expansion (19 badges).** New canonical badges, all **virtual only**:
  **Perfect Picker** (🎯 legendary — a perfect 100% guess), **Almost Perfect** (🔥 epic —
  ≥ 95), **Daily Debut**, **On a Roll / Week Warrior / Monthly Machine** (streak 3/7/30),
  **Top 10%**, **Multi-Sport Starter**, and an updated **Tournament Winner** (🏆 epic).
  Perfect / almost-perfect thresholds are centralized in `config('ballspot.scoring')`
  (`ScoreService::isPerfectScore()` / `isAlmostPerfect()`). Badge XP uses the existing
  **XP ledger** (`xp_events`, source `badge_unlock`), once per badge per user.
- **Badge unlock feedback.** Result screens return `new_badges` after a *fresh* guess
  (never on result reopen) and show a "Badge unlocked!" card; a legendary unlock gets a
  distinct "Legendary badge unlocked" treatment. Rank-up + badge cards render together.
- **Auto-awarded now:** Perfect Picker, Almost Perfect, Daily Debut, streak 3/7/30, Top 10%
  daily, Multi-Sport Starter. **Seeded but future:** `tournament_winner` (awaits robust
  tournament winner logic). No real prizes, money, or payments — everything is cosmetic.

## New in v1.7.3 — XP Ledger, Rank-Up Moments, and Second Sport Launch Prep

- **XP is now ledger-backed (new source of truth).** A new `xp_events` table records every XP
  award as an append-only row (`user_id`, `source_type`, `source_id`, signed `amount`, `reason`,
  `metadata`). `XpService.awardXp(...)` de-duplicates on `(user, source_type, source_id)` so
  replays never double-count, and `getTotalXp()` is a pure ledger sum. `PlayerRankService` now
  derives `total_xp` from the ledger. **Fallback:** a user with **no** ledger events yet still
  shows XP from lifetime guess scores (tournament + daily) so early players never see 0 XP before
  the backfill runs; once any event exists, the ledger is authoritative. XP rows are **never**
  deleted on account anonymization (rank/leaderboard history is preserved).
- **XP sources (v1.7.3):**
  - **Guess XP** — awarded when a guess is **submitted** (not when the result is viewed). Daily
    guess → `+score` ("Daily challenge completed"); tournament round guess → `+score` ("Tournament
    round completed"). Deduped per guess id, so reopening a result never re-awards.
  - **Badge XP** — on unlock, a rarity bonus (`config('ballspot.xp.badge')`): common 100, rare
    250, epic 500, legendary 1000. Awarded once per badge per user.
  - **Streak XP** — daily-streak milestones (`config('ballspot.xp.streak')`): 3-day +150, 7-day
    +500, 30-day +2500. Awarded once per milestone per user.
  - **Tournament-win XP** — config exists (`config('ballspot.xp.tournament_win')`: winner 1000 /
    2nd 500 / 3rd 250) but **is not awarded yet** — deferred until robust tournament
    completion/winner logic exists (config-ready-but-not-awarded).
- **Rank-up moments.** Both guess responses now carry a nullable `rank_up` field. When a
  submission crosses a rank threshold: `rank_up: { from_rank, to_rank, new_level }` (else `null`).
  Both responses also include `rank_progress: { xp_gained, rank: {...} }`, where `xp_gained` is the
  **total** XP earned in that submission (guess + any badge/streak bonus), not just the guess score.
- **XP history API.** New `GET /api/me/xp-events?limit=N` (default 20, max 50; auth + verified)
  returns `{ data: [ { id, amount, reason, source_type, metadata, created_at } ], total_xp,
  rank }`, most-recent first.
- **Backfill command.** Run `php artisan ballspot:backfill-xp` **once after deploy** to create
  missing `daily_guess` + `tournament_guess` XP events for existing guesses. Idempotent (never
  duplicates); supports `--dry-run` (writes nothing), `--user=ID`, and `--force` (intentionally a
  NO-OP — history is never deleted/rebuilt). Never touches guesses/challenges/images.
- **Second sport launch prep.**
  - **Per-sport taglines** in `config('ballspot.sport_taglines')` (football "Guess the ball",
    tennis "Find the tennis ball", golf "Spot the golf ball", hockey "Find the puck", cricket
    "Spot the cricket ball", american_football "Find the ball", basketball "Spot the ball"),
    returned by `GET /api/sports` as a new `tagline` field (falls back to "Guess the {object_name}").
  - **`SportReadinessService`** + the admin Sports page show content readiness per sport (ready
    challenge count = active + hidden image + ball position, scheduled daily count) with a "Ready
    to activate" / "Not enough content yet" badge for non-active sports. Thresholds in
    `config('ballspot.sport_readiness')` (min_ready_challenges=5, min_scheduled_dailies=1).
    **Advisory only** — activation is not hard-blocked.
  - **Scheduler polish** — `php artisan ballspot:schedule-daily-challenges --sport=tennis` now
    **warns and does nothing** if the sport is `coming_soon`/`hidden`, unless `--allow-coming-soon`
    is passed (admin content prep). Default (no `--sport`) behaviour is unchanged; nothing is
    deleted/overwritten.
  - **Mobile** — Choose Sport shows each sport's tagline; `coming_soon` sports stay
    visible-but-disabled with a SOON badge and become selectable with **no new mobile code** once
    an admin flips them to `active` (data-driven).
- Backend tests: **207 passing** (was 189; +18). See [docs/test-report.md](docs/test-report.md).

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
cd backend && php artisan test          # 207 feature tests
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

# Prep content for a not-yet-live sport (v1.7.3) — coming_soon/hidden sports warn and
# do nothing unless --allow-coming-soon is passed:
php artisan ballspot:schedule-daily-challenges --days=14 --sport=tennis --allow-coming-soon
```

**Notes:**
- `--sport=<slug>` (v1.7): when omitted, behaviour is unchanged (all active challenges).
  When provided, only that sport's active challenges fill the schedule; an unknown slug
  fails with a friendly error. Default (no flag) is football-safe because football is the
  only active sport with content.
- `--allow-coming-soon` (v1.7.3): scheduling for a `coming_soon`/`hidden` sport otherwise
  **warns and does nothing** (guards against filling the schedule from a sport users can't
  play yet). Pass this flag to prepare content ahead of activation. Default (no `--sport`)
  behaviour is unchanged; nothing is ever deleted or overwritten.
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
