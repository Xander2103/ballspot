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

**Admin area:** http://127.0.0.1:8000/admin/login (credentials: `admin@ballspot.local / password`)

---

## How to Run the Mobile App

```bash
cd mobile

# Install dependencies (first time)
npm install

# Copy env file (first time)
cp .env.example .env

# Start Expo dev server (simulator/emulator)
npx expo start

# Then press:
#   a → Android emulator
#   i → iOS simulator
#   w → Web browser
```

**Physical device (iOS/Android):**

1. Find your computer's LAN IP: `ipconfig` (Windows) or `ifconfig` (Mac/Linux)
2. Edit `mobile/.env` and set:
   ```
   EXPO_PUBLIC_API_BASE_URL=http://192.168.1.x:8000/api
   ```
3. Start the backend with LAN binding:
   ```bash
   cd backend && php artisan serve --host=0.0.0.0 --port=8000
   ```
4. Start Expo:
   ```bash
   cd mobile && npx expo start --host=lan
   ```
5. Scan the QR code with Expo Go (Android) or Camera app (iOS)

---

## Backend Test Results

Run: `cd backend && php artisan test`

```
Tests:    14 passed (26 assertions)
Duration: ~1.4s
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
| AdminTest::test_unauthenticated_user_is_redirected_to_login | ✅ |
| AdminTest::test_non_admin_user_gets_403 | ✅ |
| AdminTest::test_admin_user_can_access_challenges | ✅ |
| LeaderboardTest::test_leaderboard_shows_ranked_scores | ✅ |
| ExampleTest (default) × 2 | ✅ |

## Mobile TypeScript Check

Run: `cd mobile && npx tsc --noEmit`

```
0 errors
```

---

## Known Limitations

1. **SVG images may not render in all React Native versions** — The demo challenges use SVG files. React Native's `Image` component does not natively support SVG. If images appear blank, upload JPEG/PNG replacements via the admin panel.

2. **No push notifications** — Round availability is not pushed to users. They must open the app to see new rounds.

3. **Rounds are always open** — `opens_at`/`closes_at` are nullable and unused in v1. All rounds are playable immediately after league creation.

4. **No user profile or avatar** — v1 shows name + username only.

5. **Single sport** — Only football challenges are used. The data model supports more sports but the UI and API hardcode football.

---

## Next Recommended Steps

### High Priority
- [ ] Round time windows (set opens_at/closes_at and enforce them in the API)
- [ ] Replace SVG demo images with JPEG/PNG for broad React Native compatibility

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
