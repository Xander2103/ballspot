# BallSpot v1 — Product Plan

## What It Is

BallSpot is a social sports guessing game. Users create or join leagues, view hidden football images, tap where they think the ball is, and receive points based on how close their guess was.

## v1 Scope

- Football only
- No real money, no gambling, no subscriptions, no ads
- No chat, no AI, no realtime/websockets
- Backend calculates all scores — mobile never trusts its own calculations
- Positions stored as ratios 0..1 (device-independent)

## Architecture

```
backend/   Laravel 12 REST API + Blade admin
mobile/    Expo 56 React Native TypeScript app
docs/      Documentation
```

## User Flow

1. Register / Login
2. Create a league (name, duration 1/3/7 days, 1/3 rounds per day)
   - OR Join a league with a 6-character code
3. Play current round: view hidden image, tap to guess ball position
4. See score and reveal (correct position vs your guess)
5. Check leaderboard

## Scoring Formula

```
dx = guess_x_ratio - ball_x_ratio
dy = guess_y_ratio - ball_y_ratio
distance = sqrt(dx² + dy²)
score = max(0, round(100 - distance * 250))
```

Perfect guess = 100 points. Score reaches 0 at ~40% diagonal distance.

## Key Decisions

- SQLite in development (zero config), MySQL-ready for production
- Sanctum bearer token auth (stateless, works for mobile)
- Admin area uses Laravel session auth (not Sanctum); login at `/admin/login` with seeded admin credentials
- All rounds are immediately playable (opens_at/closes_at nullable)
- League join codes are 6 uppercase random characters, unique
- If not enough challenges exist, rounds cycle through available ones
