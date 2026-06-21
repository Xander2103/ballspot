# BallSpot v1 — Test Report

Build date: 2026-06-21

---

## What Was Implemented

### Backend (Laravel 12)
- Full REST API: auth (register/login/logout/me), leagues (create/join/detail/current-round/leaderboard), rounds (submit-guess/result), health
- Sanctum bearer token authentication
- Score calculation in ScoreService (server-side only)
- LeagueService for league creation, joining, and round generation
- Admin Blade area for challenge CRUD with image upload
- 6 demo challenges seeded (football sport)
- IDOR protection: membership check on all league-scoped endpoints
- Email shown only to self in UserResource

### Mobile (Expo 56 / React Native 0.85.3)
- 9 screens: Login, Register, Home, CreateLeague, JoinLeague, LeagueDetail, Guess, Result, Leaderboard
- React Navigation (native-stack) with typed RootStackParamList
- ImageGuessPicker: tap-to-guess ratio conversion, marker overlay for reveal
- Bearer token stored securely via expo-secure-store
- Dark navy/green theme throughout
- TypeScript strict — 0 errors

---

## How to Run the Backend

```bash
cd backend

# Install dependencies (first time)
composer install

# Copy env (first time)
cp .env.example .env
php artisan key:generate

# Run migrations + seed demo data
php artisan migrate
php artisan db:seed

# Create storage symlink (first time)
php artisan storage:link

# Start dev server
php artisan serve
# → http://127.0.0.1:8000
```

**Admin area:** http://127.0.0.1:8000/admin/challenges

---

## How to Run the Mobile App

```bash
cd mobile

# Install dependencies (first time)
npm install

# Start Expo dev server
npx expo start

# Then press:
#   a → Android emulator
#   i → iOS simulator
#   w → Web browser
```

**Physical device:** Replace `127.0.0.1` in `mobile/src/api/client.ts` (or set env var `EXPO_PUBLIC_API_BASE_URL`) with your computer's LAN IP, e.g. `http://192.168.1.42:8000/api`.

---

## Backend Test Results

Run: `cd backend && php artisan test`

```
Tests:    11 passed (22 assertions)
Duration: ~26s
```

| Test | Status |
|------|--------|
| HealthTest::test_health_endpoint_returns_ok | ✅ |
| AuthTest::test_user_can_register | ✅ |
| AuthTest::test_user_can_login | ✅ |
| AuthTest::test_invalid_login_fails | ✅ |
| LeagueTest::test_user_can_create_league | ✅ |
| LeagueTest::test_user_can_join_league_with_code | ✅ |
| GuessTest::test_member_can_submit_guess_and_receive_score | ✅ |
| GuessTest::test_duplicate_guess_is_rejected | ✅ |
| LeaderboardTest::test_leaderboard_shows_ranked_scores | ✅ |
| ExampleTest (default) × 2 | ✅ |

## Mobile TypeScript Check

Run: `cd mobile && npx tsc --noEmit`

```
0 errors
```

---

## Known Limitations

1. **No admin authentication** — The `/admin` area is publicly accessible. Acceptable for v1 internal use; add Laravel auth middleware before any public deployment.

2. **Image reveal is ratio-based only** — The ResultScreen shows coordinate dots. The hidden image is displayed with U/B markers overlaid, but images are placeholder JPEGs in dev — replace with real football photos.

3. **No push notifications** — Round availability is not pushed to users. They must open the app to see new rounds.

4. **Rounds are always open** — `opens_at`/`closes_at` are nullable and unused in v1. All rounds are playable immediately after league creation.

5. **Physical device requires LAN IP** — `127.0.0.1` only works on simulator/emulator. See "How to Run" above.

6. **No image click-to-set in admin** — Ball X/Y ratios must be typed manually in the admin create/edit forms.

7. **No user profile or avatar** — v1 shows name + username only.

8. **Single sport** — Only football challenges are used. The data model supports more sports but the UI and API hardcode football.

---

## Next Recommended Steps

### High Priority
- [ ] Add admin authentication (Laravel auth middleware on `/admin`)
- [ ] Add real football challenge images to storage
- [ ] Click-to-set ball position in admin (JS canvas overlay)
- [ ] Round time windows (set opens_at/closes_at and enforce them in the API)

### Medium Priority
- [ ] Push notifications when a new round opens
- [ ] Profile screen (avatar, stats)
- [ ] League chat (simple message board)
- [ ] Multiple sports support

### Quality / Infra
- [ ] Switch dev DB to MySQL for production parity
- [ ] CI pipeline (GitHub Actions: `php artisan test` + `tsc --noEmit`)
- [ ] Error monitoring (Sentry)
- [ ] API rate limiting (Laravel throttle middleware)
- [ ] Image CDN / optimization for mobile

### Gameplay
- [ ] Round closing logic (cron job to close rounds after deadline)
- [ ] Seasons / league archives
- [ ] Global leaderboard across leagues
- [ ] Streak tracking
