# BallSpot

A social football guessing game. Spot the hidden ball. Beat your friends.

## What it is

BallSpot shows football images with the ball hidden. Players tap where they think the ball is and earn points based on accuracy. Create leagues, invite friends, climb the leaderboard.

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
cd backend && php artisan test          # 14 feature tests
cd mobile && npx tsc --noEmit          # 0 TypeScript errors
```

## Docs

- [API Contract](docs/api-contract.md)
- [Database Schema](docs/database-schema.md)
- [Test Report](docs/test-report.md)

## Constraints

- No real money, no gambling, no subscriptions, no ads
- No chat, no AI, no realtime/websockets
- Football only (v1)
- Score calculation backend-only (never trust client)
- Positions stored as ratios 0..1 (device-independent)
