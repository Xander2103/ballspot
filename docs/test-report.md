# BallSpot v1.5 — Test Report

Build date: 2026-06-24

---

## What Was Implemented

### Backend (Laravel 12) — v1.5

- Full REST API: auth (register/login/logout/me/profile/stats), leagues (create/join/start/cancel/detail/current-round/leaderboard), rounds (submit-guess/result), health
- **Tournament lifecycle:** leagues start in `lobby`; rounds generated only on `POST /start`; owner can cancel (soft-delete to `cancelled`); non-lobby join blocked
- **Tournament daily round limits:** `rounds_per_day` enforced per user per UTC calendar day in `currentRound` endpoint; returns `reason: "daily_limit_reached"` with progress fields when limit reached
- **Daily progress fields:** every `current-round` response now includes `rounds_per_day`, `played_today_count`, `remaining_today_count`, `next_available_at`
- **Duplicate guess protection:** `POST /rounds/{id}/guess` returns 422 with clear message if already guessed
- **Enriched LeagueResource:** `is_owner`, `rounds_count`, `completed_rounds_count`, `remaining_rounds_count`, `progress_pct`, `starts_at`, `ends_at`, `members` (LobbyMember[])
- **Member removal:** `DELETE /leagues/{id}/members/{userId}` — owner-only, lobby-only, cannot remove self
- **Profile stats:** `GET /profile/stats` returns tournament + daily challenge aggregate stats
- **Daily Challenge system:**
  - `daily_challenges` and `daily_challenge_guesses` tables and models
  - `DailyChallengeController`: today, guess, result, weeklyLeaderboard, stats endpoints
  - `DailyStreakService`: current streak and best streak computed on demand
  - `GET /daily/today` never exposes ball position before guess
  - `POST /daily/{id}/guess` enforces one guess per user per daily challenge
  - `GET /daily/leaderboard/weekly` ranks by total score (Mon–Sun UTC week)
  - `GET /daily/stats` returns streak, total played, average, best, weekly rank
- **Admin panel:** daily challenge admin (list/create/update-status) at `/admin/daily`
- **DailyChallengeSeeder:** seeds one active daily challenge for today if active challenges exist
- Sanctum bearer token authentication
- ScoreService (server-side score calculation, shared by tournament rounds and daily challenges)
- Admin Blade area for challenge CRUD with image upload, click-to-set ball position
- 6 demo challenges seeded (football sport)
- IDOR protection: membership check on all league-scoped endpoints
- Email shown only to self in UserResource

### Mobile (Expo 56 / React Native 0.85.3) — v1.5

- **13 screens:** Login, Register, Home, CreateTournament, JoinTournament, LeagueDetail, Guess, Result, Leaderboard, Profile, DailyChallenge, DailyResult, WeeklyLeaderboard
- **HomeScreen:** cleaner header (⚽ BallSpot logo + profile icon); Daily Challenge card on top; tournament sections below; logout removed from home
- **Daily Challenge card:** shows date, difficulty/category, Play button / Already Played state / No challenge state; streak display
- **DailyChallengeScreen:** ImageGuessPicker with no-crop; ghost-ball marker; single submit; auto-redirects to DailyResult after submit or if already played
- **DailyResultScreen:** score prominently (color-coded); reveal image if available; ghost-ball user guess + green glow ring for correct ball; streak display; weekly leaderboard preview (top 3); buttons: View Weekly Leaderboard, Back Home
- **WeeklyLeaderboardScreen:** FlatList ranked by total weekly score; current user highlighted; empty state; week label
- **ProfileScreen:** name, username, email; tournament stats grid; daily stats section; logout button at bottom; app version
- **GuessScreen:** "Today: A/B played" sub-text; daily round limit progress in header
- **LeagueDetailScreen:** shows daily limit progress; hides Play button when daily_limit_reached; shows "Done for today" message
- **ResultScreen:** back button navigates to LeagueDetail (not stale stack); "Play Next Round" only shown when `has_current_round === true` and `reason !== 'daily_limit_reached'`; "Done for today" text when daily limit reached
- **dailyApi.ts:** all 5 daily endpoints typed and wired
- **daily types:** complete TypeScript interfaces in `mobile/src/types/daily.ts`
- Navigation typed with `DailyChallenge`, `DailyResult`, `WeeklyLeaderboard` routes
- React Navigation (native-stack) with typed RootStackParamList
- Bearer token stored securely via expo-secure-store (sessionStorage fallback on web)
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

## How to Test Tournament Daily Limits

```bash
# Start backend
cd backend && php artisan serve

# 1. Create an account and login via the mobile app (or use Postman)
# 2. Create a tournament with rounds_per_day = 1
# 3. Start the tournament
# 4. Play Round 1
# 5. On the Result screen you should see "You're done for today" (not "Play Next Round")
# 6. Navigate to LeagueDetail — the Play button is hidden and message shows
# 7. GET /api/leagues/{id}/current-round returns reason: "daily_limit_reached"

# To test with rounds_per_day = 3:
# Create a tournament with rounds_per_day = 3, start it with 4+ rounds
# Play 3 rounds in one day — the 4th request returns daily_limit_reached
```

## How to Test Locked / Replayed Rounds

```bash
# 1. Play a round via GuessScreen
# 2. After submission, Result screen shows — note the URL stack uses navigation.replace
#    (pressing native back on ResultScreen navigates to LeagueDetail, NOT back to GuessScreen)
# 3. On LeagueDetail, the round is no longer shown as "Play Round"
# 4. Attempting to POST /rounds/{id}/guess again returns 422:
#    { "message": "You have already guessed this round." }
# 5. GET /rounds/{id}/result still returns the result (result remains available)
```

## How to Create Today's Daily Challenge

**Via seeder (automated, safe — skips if today's challenge already exists):**
```bash
cd backend
php artisan db:seed --class=DailyChallengeSeeder
```

**Via admin panel (manual):**
1. Go to http://127.0.0.1:8000/admin/login
2. Ensure at least one challenge has status = **active** (Admin → Challenges)
3. Go to Admin → Daily Challenges → Create
4. Select the challenge and date (default: today)
5. Submit — status defaults to 'active'

**Via API (Postman/curl) — requires admin user:**
This cannot be done via the public API; use the admin panel or seeder.

## How to Test the Daily Challenge Flow

```bash
# 1. Seed today's challenge:
cd backend && php artisan db:seed --class=DailyChallengeSeeder

# 2. Open the mobile app and login
# 3. HomeScreen shows the Daily Ball Challenge card with a "Play Daily Challenge" button
# 4. Tap Play → DailyChallengeScreen loads with the hidden image
# 5. Tap the image to place your guess → Submit
# 6. DailyResultScreen shows: score, reveal image (if available), streak, top 3 leaderboard
# 7. Tap "View Weekly Leaderboard" → WeeklyLeaderboardScreen
# 8. Go back to Home — the Daily Challenge card now shows "Already played" state
# 9. GET /api/daily/today returns already_played: true
# 10. GET /api/daily/{id}/result returns your score
# 11. POST /api/daily/{id}/guess returns 422 "You have already played today's challenge."
```

---

## Backend Test Results

Run: `cd backend && php artisan test`

```
Tests:    54 passed (186 assertions)
Duration: ~1.16s
```

| Test File | Test | Status |
|-----------|------|--------|
| AdminTest | unauthenticated user is redirected to login | ✅ |
| AdminTest | non admin user gets 403 | ✅ |
| AdminTest | admin user can access challenges | ✅ |
| AuthTest | user can register | ✅ |
| AuthTest | user can login | ✅ |
| AuthTest | invalid login fails | ✅ |
| ChallengeCategoryTest | admin can create challenge with category | ✅ |
| ChallengeCategoryTest | admin can update challenge category | ✅ |
| ChallengeCategoryTest | current round includes category in challenge | ✅ |
| ChallengeCategoryTest | current round category is null when challenge has no category | ✅ |
| ChallengeSecurityTest | current round does not expose ball position | ✅ |
| ChallengeSecurityTest | result exposes ball position after guessing | ✅ |
| ChallengeSecurityTest | result includes reveal image url when original image exists | ✅ |
| ChallengeSecurityTest | result reveal image url is null when no original image | ✅ |
| **DailyChallengeTest** | today endpoint requires auth | ✅ |
| **DailyChallengeTest** | today returns no daily when none exists | ✅ |
| **DailyChallengeTest** | today does not expose ball position before guess | ✅ |
| **DailyChallengeTest** | user can submit daily guess and receive score | ✅ |
| **DailyChallengeTest** | duplicate daily guess is rejected | ✅ |
| **DailyChallengeTest** | result exposes ball position after guess | ✅ |
| **DailyChallengeTest** | result returns 404 if not guessed | ✅ |
| **DailyChallengeTest** | weekly leaderboard includes scores | ✅ |
| **DailyChallengeTest** | daily stats returns expected fields | ✅ |
| **DailyLimitTest** | rounds per day 1 blocks second round same day | ✅ |
| **DailyLimitTest** | rounds per day 3 allows three rounds per day | ✅ |
| **DailyLimitTest** | daily limit is per user not global | ✅ |
| **DailyLimitTest** | duplicate guess is rejected with clear message | ✅ |
| **DailyLimitTest** | result available after guessing | ✅ |
| ExampleTest | the application returns a successful response | ✅ |
| GuessTest | member can submit guess and receive score | ✅ |
| GuessTest | duplicate guess is rejected | ✅ |
| HealthTest | health endpoint returns ok | ✅ |
| LeaderboardTest | leaderboard shows ranked scores | ✅ |
| LeagueMemberTest | league detail includes members with is owner and joined at | ✅ |
| LeagueMemberTest | owner can remove lobby member | ✅ |
| LeagueMemberTest | non owner cannot remove member | ✅ |
| LeagueMemberTest | owner cannot remove themselves | ✅ |
| LeagueMemberTest | cannot remove member after tournament starts | ✅ |
| LeagueMemberTest | removed member cannot access league | ✅ |
| LeagueTest | user can create league | ✅ |
| LeagueTest | user can join league with code | ✅ |
| LeagueTournamentLifecycleTest | create league starts in lobby with no rounds | ✅ |
| LeagueTournamentLifecycleTest | owner can start tournament | ✅ |
| LeagueTournamentLifecycleTest | non owner cannot start tournament | ✅ |
| LeagueTournamentLifecycleTest | start fails when no active challenges | ✅ |
| LeagueTournamentLifecycleTest | users can join lobby tournament | ✅ |
| LeagueTournamentLifecycleTest | users cannot join active tournament | ✅ |
| LeagueTournamentLifecycleTest | owner can cancel tournament | ✅ |
| LeagueTournamentLifecycleTest | non owner cannot cancel tournament | ✅ |
| LeagueTournamentLifecycleTest | cancelled leagues not in index | ✅ |
| LeagueTournamentLifecycleTest | league resource includes enriched fields | ✅ |
| ProfileStatsTest | profile stats returns expected fields | ✅ |
| ProfileStatsTest | profile stats requires auth | ✅ |
| Unit\ExampleTest | that true is true | ✅ |

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

The `GET /leagues/{id}/current-round` and `GET /api/daily/today` endpoints deliberately omit `ball_x_ratio`, `ball_y_ratio`, and `reveal_image_url` to prevent cheating before guessing. These fields are only included in result endpoints (`POST /rounds/{id}/guess`, `GET /rounds/{id}/result`, `POST /api/daily/{id}/guess`, `GET /api/daily/{id}/result`), which are only accessible after the player has submitted their guess. This is enforced at the controller layer and verified by `ChallengeSecurityTest` and `DailyChallengeTest`.

---

## Known Limitations

1. **Timezone:** All date logic (daily challenge date, daily round limit reset, weekly leaderboard week) uses UTC (server time). Users in other timezones will see the daily challenge reset at a local time that differs from midnight local time.

2. **Daily challenge must be manually created** — No auto-scheduling. Use the admin panel at `/admin/daily/create` or run `php artisan db:seed --class=DailyChallengeSeeder` to seed today's challenge.

3. **`next_available_at` is always null** — The API includes this field in all `current-round` responses but always returns `null`. Future implementation would return "tomorrow at midnight UTC" or a specific time.

4. **Best streak is computed on demand** — `DailyStreakService::getStreakForUser()` walks all the user's daily guess dates each call. This is fine for small datasets but would need caching or a stored column for large user bases.

5. **`weekly_rank` in `/daily/stats` is computed fresh** — Rank is calculated from a live DB query on each call. There is no caching; for very high traffic this could be slow.

6. **SVG images may not render in all React Native versions** — The demo challenges ship with SVG placeholders. React Native's `Image` component does not natively support SVG. If images appear blank, upload JPEG/PNG replacements via the admin panel. See `docs/challenge-content-guide.md` for image specifications.

7. **Lobby polling is not realtime** — Member list updates every 3 seconds. A player joining will appear on the host's screen within 3 seconds. For true realtime, upgrade to WebSockets or Server-Sent Events.

8. **No push notifications** — Round availability and new daily challenges are not pushed. Users must open the app.

9. **Rounds are always open** — `opens_at`/`closes_at` are nullable and unused in v1. All rounds are playable immediately after `POST /start`.

10. **No avatar** — Profile screen shows stats only; no avatar/photo upload.

11. **Token storage on web** — expo-secure-store has no web implementation. Tokens are stored in `sessionStorage` on web (cleared on tab close). For production web, migrate to HttpOnly+Secure+SameSite cookies.

---

## Next Recommended Sprint

### High Priority
- [ ] Replace SVG demo images with JPEG/PNG for broad React Native compatibility
- [ ] Auto-schedule daily challenges (cron job or admin scheduling UI)
- [ ] Completed league status auto-trigger — currently `completed` must be set manually; add auto-complete when all rounds played by all members
- [ ] Round time windows (`opens_at`/`closes_at` enforced in API)

### Medium Priority
- [ ] Push notifications when new daily challenge is available
- [ ] `next_available_at` in current-round response (return tomorrow UTC midnight)
- [ ] Cache streak and weekly rank for performance
- [ ] Profile avatar upload
- [ ] Join tournament by link / QR code (not just manual code entry)

### Quality / Infra
- [ ] Switch dev DB to MySQL for production parity
- [ ] CI pipeline (GitHub Actions: `php artisan test` + `tsc --noEmit`)
- [ ] Error monitoring (Sentry)
- [ ] API rate limiting (Laravel throttle middleware)
- [ ] Image CDN / optimization for mobile
- [ ] HttpOnly cookie auth for web (replace sessionStorage token)

### Gameplay
- [ ] Season archives / past daily challenge history
- [ ] Global all-time leaderboard
- [ ] Share score via native share sheet
- [ ] Difficulty-based scoring multiplier
