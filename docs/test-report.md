# BallSpot v1 — Test Report

Build date: 2026-06-23

---

## What Was Implemented

### Backend (Laravel 12)
- Full REST API: auth (register/login/logout/me), leagues (create/join/detail/current-round/leaderboard), rounds (submit-guess/result), health
- Sanctum bearer token authentication
- Score calculation in ScoreService (server-side only)
- LeagueService for league creation, joining, and round generation
- Admin Blade area for challenge CRUD with image upload
  - Click-to-set ball position on hidden image and reveal image
  - Status/difficulty filters on challenge list
  - Thumbnail previews in challenge list
- 6 demo challenges seeded (football sport)
- **Challenge reveal images** — `original_image_path` is the post-guess reveal image; exposed only via result endpoint
- IDOR protection: membership check on all league-scoped endpoints
- Email shown only to self in UserResource
- **Security:** `current-round` endpoint never exposes `ball_x_ratio`, `ball_y_ratio`, or `reveal_image_url`

### Mobile (Expo 56 / React Native 0.85.3)
- 9 screens: Login, Register, Home, CreateLeague, JoinLeague, LeagueDetail, Guess, Result, Leaderboard
- React Navigation (native-stack) with typed RootStackParamList
- ImageGuessPicker: tap-to-guess ratio conversion, marker overlay for reveal
- Bearer token stored securely via expo-secure-store (sessionStorage fallback on web)
- Dark navy/green theme throughout
- TypeScript strict — 0 errors
- **ResultScreen:** shows reveal image if available, else hidden image; updated score ratings; friendly distance feedback

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
Tests:    18 passed (41 assertions)
Duration: ~0.6s
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
| **ChallengeSecurityTest::test_current_round_does_not_expose_ball_position** | ✅ |
| **ChallengeSecurityTest::test_result_exposes_ball_position_after_guessing** | ✅ |
| **ChallengeSecurityTest::test_result_includes_reveal_image_url_when_original_image_exists** | ✅ |
| **ChallengeSecurityTest::test_result_reveal_image_url_is_null_when_no_original_image** | ✅ |
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

## Tap Coordinate System

`ImageGuessPicker` converts a tap into normalised ratios (0–1) relative to the rendered image container:

```
xRatio = clamp(tapX / containerWidth,  0, 1)
yRatio = clamp(tapY / containerHeight, 0, 1)
```

Dimensions are captured live via React Native's `onLayout` callback (not `Dimensions.get`), so the calculation is correct regardless of screen size or orientation.

**Web / native event handling** — On React Native Web, `Pressable.onPress` maps to a browser `onClick`. The `nativeEvent.locationX/Y` fields are derived from `offsetX/offsetY` of the click event, which are relative to the event-target element. To guarantee the target is always the `Pressable` (not a child image or marker), all child views use `pointerEvents="none"`. A `measureInWindow` fallback is applied when `locationX/Y` fall outside the container bounds, which can happen in some browser versions.

## Challenge Image Security

The current-round endpoint (`GET /leagues/{id}/current-round`) deliberately omits `ball_x_ratio`, `ball_y_ratio`, and `reveal_image_url` from the challenge object to prevent cheating before guessing. These fields are only included in the result endpoints (`POST /rounds/{id}/guess`, `GET /rounds/{id}/result`), which are only meaningful after the player has already submitted their guess. This is enforced at the resource layer (`LeagueRoundResource` vs `GuessResultResource`) and verified by `ChallengeSecurityTest`.

## Known Limitations

1. **SVG images may not render in all React Native versions** — The demo challenges ship with SVG placeholders. React Native's `Image` component does not natively support SVG. If images appear blank, upload JPEG/PNG replacements (4:3 ratio recommended) via the admin panel. See `docs/challenge-content-guide.md` for image specifications.

2. **Coordinate mapping assumes image fills the container** — `ImageGuessPicker` uses `resizeMode="cover"`, so the tappable area matches the visible image area. If the uploaded image has a very different aspect ratio from 4:3, portions may be cropped and the ball could theoretically lie in the cropped region. Upload 4:3 images to avoid this.

3. **No push notifications** — Round availability is not pushed to users. They must open the app to see new rounds.

4. **Rounds are always open** — `opens_at`/`closes_at` are nullable and unused in v1. All rounds are playable immediately after league creation.

5. **No user profile or avatar** — v1 shows name + username only.

6. **Single sport** — Only football challenges are used. The data model supports more sports but the UI and API hardcode football.

7. **Token storage on web** — expo-secure-store has no web implementation. Tokens are stored in `sessionStorage` on web (cleared on tab close). For production web, migrate to HttpOnly+Secure+SameSite cookies.

---

## Next Recommended Steps

### High Priority
- [ ] Round time windows (set opens_at/closes_at and enforce them in the API)
- [ ] Replace SVG demo images with JPEG/PNG for broad React Native compatibility
- [ ] Add real football pitch photos to `backend/public/demo/challenges/hidden/` and `reveal/` (see `docs/challenge-content-guide.md`)

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
- [ ] HttpOnly cookie auth for web (replace sessionStorage token)

### Gameplay
- [ ] Round closing logic (cron job to close rounds after deadline)
- [ ] Seasons / league archives
- [ ] Global leaderboard across leagues
- [ ] Streak tracking
