# BallSpot

A social football guessing game. Spot the hidden ball. Beat your friends. Play the daily challenge.

> **BallSpot → BallPicker:** the backend now carries a multi-sport foundation so
> the app can broaden beyond football later. Football remains the only *active*
> sport and the default everywhere; the UI is unchanged. See "New in v1.6" below.

## What it is

BallSpot shows football images with the ball hidden. Players tap where they think the ball is and earn points based on accuracy. Play the daily challenge against everyone, create leagues with friends, or climb the weekly leaderboard.

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
cd backend && php artisan test          # 119 feature tests
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
```

**Notes:**
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
- Football is the only **active** sport (multi-sport foundation exists in the backend)
- Trophies are **virtual only** — no real prizes
- Score calculation backend-only (never trust client)
- Positions stored as ratios 0..1 (device-independent)
- All date/day logic uses UTC
