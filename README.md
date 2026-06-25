# BallSpot

A social football guessing game. Spot the hidden ball. Beat your friends. Play the daily challenge.

## What it is

BallSpot shows football images with the ball hidden. Players tap where they think the ball is and earn points based on accuracy. Play the daily challenge against everyone, create leagues with friends, or climb the weekly leaderboard.

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
cd backend && php artisan test          # 86 feature tests
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

## Docs

- [API Contract](docs/api-contract.md)
- [Database Schema](docs/database-schema.md)
- [Test Report](docs/test-report.md)
- [Content Safety Guide](docs/content-safety.md)
- [Challenge Content Guide](docs/challenge-content-guide.md)

## Constraints

- No real money, no gambling, no subscriptions, no ads
- No chat, no AI, no realtime/websockets
- Football only (v1)
- Score calculation backend-only (never trust client)
- Positions stored as ratios 0..1 (device-independent)
- All date/day logic uses UTC
