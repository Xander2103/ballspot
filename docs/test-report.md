# BallSpot v1.8.1 — Test Report

Build date: 2026-07-30

**Backend:** 334 feature tests passing (was 302; +32 across rate limiting, endpoint caps, security headers, deletion cleanup, push-token privacy, data export, beta code).
**Mobile:** `tsc --noEmit` clean. Web bundle (`expo export --platform web`) builds cleanly.

---

## Security, Privacy & Test Readiness (v1.8.1)

Hardening pass before external testing. New/extended suites:

- **RateLimitTest (9):** register throttled per IP with clean 429 JSON
  (`message` + `retry_after`); forgot-password per email; reset-password per
  IP; email verify/resend per user; **admin login throttled** (was completely
  unthrottled); guess endpoints carry `throttle:gameplay`; every API route
  rides the global `throttle:api`; admin notification send throttled. Base
  `TestCase` now flushes the cache per test so limiter windows never leak
  between tests.
- **EndpointCapsTest (4):** weekly leaderboard `data` capped at 100 while
  `meta` still ranks the full field (verified with a 105-player field, rank
  105 preserved); Trophy Room caps; admin challenge upload **rejects gif**,
  accepts png (mimes tightened to jpeg/jpg/png/webp).
- **SecurityHeadersTest (2):** nosniff / SAMEORIGIN / Referrer-Policy /
  Permissions-Policy present on API and public web responses.
- **AccountDeletionTest (+3 → 8):** deletion now also removes the avatar
  file (and clears `avatar_path`), push tokens, notification settings and
  pending verification codes; gameplay/XP history retained anonymized.
- **PushTokenPrivacyTest (6):** register response never echoes the token;
  `DELETE /me/push-tokens` removes own row(s) only (cannot touch another
  user's registration); `ballspot:cleanup-login-codes` prunes tokens unseen
  for 90+ days; `is_admin` never serializes.
- **DataExportTest (4):** `GET /api/me/export` requires auth, works for
  unverified users, contains account + activity data, and **never contains**
  the password hash, API-token value, or raw push-token value.
- **BetaCodeTest (4):** registration open when no code configured; required
  + validated (case-insensitive, non-echoing) when `BALLPICKER_BETA_CODE` set.

Stats endpoints were rewritten onto SQL aggregates and missing SQLite indexes
added (`guesses.user_id`, `daily_challenge_guesses.user_id`,
`league_rounds(league_id,status)`, `league_members.user_id`) — all existing
suites still green, confirming no behavior change.

Mobile: consent checkbox + Terms/Privacy links + optional beta-code field on
Register; 429-aware API client; `signOut()` clears token, scheduled
reminders, prompt flag and theme on logout/delete and de-registers the
device push token on logout.

---

## Monthly Competition Close & Award Top 3 (v1.8.0)

Completed competition periods can be closed and their top 3 awarded virtual trophies.
Adds `CompetitionCloseTest` (16):

- **dry-run writes nothing** (no finishes, no XP).
- close creates **top-3 finishes** with correct period type/label/window, scores,
  total_players, `xp_awarded` (2000/1000/500) and reasons ("Monthly competition winner" /
  "runner-up" / "top 3"); badges: `monthly_winner` (1st only) + `monthly_podium` (top 3).
- **no fake placements**: 1 eligible player → a single 1st place, `total_players = 1`.
- **no players** → clean "no eligible players" exit, zero records.
- **idempotent**: second run reports "already closed", finish count and total ledger XP
  unchanged.
- **current open period is not closed by default** (default targets the previous period;
  explicit `--period` on the open period fails without `--force`, succeeds with it).
- `--period=YYYY-MM` override selects the requested month; invalid formats fail cleanly.
- **tie handling deterministic**: equal totals → earliest last-qualifying guess wins; equal
  time → lower user id (via the shared `CompetitionStandingsService`).
- `monthly_top_10` awarded only in fields of **≥10 players** (top 10% by placement) and never
  creates extra finish records.
- **weekly close** stores weekly finishes/XP ("Weekly competition winner") without monthly_*
  badges.
- `--announce` saves exactly one **draft** admin notification (never sent, no duplicate on
  rerun).
- **anonymized user keeps the historical finish** (account deletion anonymizes in place).
- `GET /api/me/competition-finishes` returns the finish payload; empty state is `[]`.

`BadgeTest` catalogue-count assertions updated 23 → **26** (added the 3 competition badges).
The live monthly leaderboard was refactored onto `CompetitionStandingsService` (shared with
the close flow) — covered by the existing leaderboard/period tests, all still green.

---

## Pack Play Mode & Completion Badges (v1.7.9)

Packs are now playable. Adds `PackPlayTest` (11):

- start an active+public pack; draft/hidden → 404; no-ready-challenges → 422; **start resumes**
  the active attempt (no duplicate).
- submit a guess for the current challenge; **wrong challenge → 422**; **another user's
  attempt → 403**; progress advances.
- completion after the final challenge: attempt marked completed, `final_score`, `+250`
  completion XP; `pack_guess` XP per guess and one `pack_completion` XP recorded.
- badges: `first_pack_completed` + `perfect_pack` (all perfect); `perfect_pack` withheld when a
  guess is imperfect.
- `GET /api/me/pack-completions` returns the completion; `/api/packs` includes per-pack progress.

`BadgeTest` catalogue-count assertions updated 20 → **23** (added the 3 pack badges).

---

## Subcategories, Packs & Monthly Competitions (v1.7.8)

Content organisation + content-only packs + configurable competition period. Adds
`SubcategoryTest` (6), `ChallengePackTest` (8), `CompetitionPeriodTest` (5):

- **Subcategories:** admin can create; invalid type rejected; non-admin blocked
  (guest→login, authed→403); a challenge takes multiple subcategories and deleting one only
  detaches; `active()` scope excludes inactive; slug de-duplicated within (sport, type).
- **Packs:** admin can create; non-admin blocked; pack holds challenges (detach keeps the
  challenge); active+public appear in `/api/packs`; draft/hidden/archived do **not**; detail
  hides admin fields (`status`/`visibility`) and the ball position, lists only ready
  challenges, and 404s for hidden packs.
- **Competition period:** monthly config → "Monthly" label + calendar-month window; weekly
  config → Mon–Sun window; label override; leaderboard response includes the `period` block;
  monthly aggregation spans the whole month (not just the current week).

Existing `DailyChallengeTest` leaderboard assertion updated to the competition-service label
(default Monthly) and the new `period` structure.

---

## Notifications & Notification Settings (v1.7.7 add-on)

Opt-in local reminders + notification settings + admin announcements. All virtual — no
gambling/prizes/money. Adds `NotificationSettingsTest` (11) and `AdminNotificationTest` (7):

- **Settings:** require auth; lazy-create defaults on first read; user updates own row
  (partial); invalid `reminder_time` (`25:00`, `7pm`, `9:5`, `noon`, `24:00`, `19-00`)
  rejected 422; a user's update never affects another user's row.
- **Push tokens:** register own token; token is unique and re-registering reassigns it;
  raw token is never serialized (`$hidden`).
- **Admin composer:** `/admin/notifications` is admin-only (guest→login, non-admin→403);
  title/body validated (required, body ≤500); save-as-draft sends nothing; send-now
  delivers to opted-in tokens via a faked Expo endpoint and marks `sent`; **opt-out is
  always respected** even for `target=all`; users with no settings row count as enabled;
  with push disabled the announcement stays `draft` (never a faked send).

### Password-reset link/branding fix

`PasswordResetTest` (+3): reset link is built from `FRONTEND_URL`
(`{FRONTEND_URL}/reset-password?token=…&email=…`), never the old bare
`http://localhost/reset-password`; `PASSWORD_RESET_URL` override honored; email copy is
BallPicker, not Laravel.

---

## v1.7.7 — Tournament Completion, Winner XP & Trophy Finishes

Robust tournament completion with virtual winner/top-3 recognition. All rewards are virtual
(badges + XP ledger) — no prizes, money or payments.

### Completion rule & standings
- A tournament is complete when it is `active` and **every member has submitted a guess for every
  round** (each member plays each round once). Checked after each round-guess submission; the
  finishing guess completes it via an **atomic `active → completed` transition** so awards happen
  exactly once (idempotent even if the check re-runs).
- **Standings/ties:** total score DESC → earliest completion (last-guess time) ASC → user id ASC.
  Deterministic.

### Rewards
- `tournament_winner` (placement 1) + `podium_finish` (placements 1–3) badges.
- Placement XP via the ledger (`source_type: tournament_win`, `source_id: league id`, deduped once
  per user per league): **1st +1000, 2nd +500, 3rd +250** (`config('ballspot.xp.tournament_win')`).
  Included in `rank_progress.xp_gained` so it can trigger a rank-up in the same response.
- New badge `podium_finish` (🥉 rare) → catalogue is now **20** badges.

### Trigger & response
- `POST /rounds/{id}/guess` returns `tournament_completion { is_completed, placement, total_players,
  xp_awarded }` **only on the finishing guess** (never on result reopen). Completion badges merge
  into the existing `new_badges`.
- Standings persisted in the new **`tournament_finishes`** table (one row per member, unique
  `(league_id, user_id)`). New endpoint `GET /api/me/tournament-finishes` for the Trophy Room.

### Backend (230 passing, +9 — `TournamentCompletionTest`)
Incomplete → not completed; complete → status `completed` + winner/runner-up awards; 3rd place gets
podium + 250 XP; **idempotent** (second run awards nothing, no duplicate XP/finishes); tie broken by
earliest completion; cancelled tournament awards nothing; round-guess endpoint returns the
completion payload with `tournament_winner`/`podium_finish` in `new_badges`; result reopen returns no
completion and XP is awarded once; `/me/tournament-finishes` returns the user's finishes.
`BadgeTest` counts updated to 20.

> **Doc fix:** the v1.7.6 QA pass mislabeled `POST /rounds/{id}/guess` as returning **200** — it
> actually returns **201** (a resource wrapping the newly-created guess; `GuessTest` asserts 201).
> Corrected in `api-contract.md`.

### Mobile (`tsc --noEmit` clean, web bundle builds)
- Result screen shows a premium `TournamentCompletionCard` ("You finished 1st of 8", "+1000 XP",
  podium/winner tone) when `tournament_completion` is present; optional, never blocks navigation.
- Trophy Room gained a **"Tournament trophies"** section (medal + placement + tournament name + score
  /players/date) with a "No tournament trophies yet." empty state; finishes fetch is non-fatal.
- Types added: `TournamentCompletion`, `TournamentFinish`. Completed tournaments already show under
  Home's "Completed" section with no delete button (unchanged, verified).

### Known limitations (v1.7.7)
1. A member who never plays keeps a tournament open (completion needs all-members-all-rounds). The
   owner can still cancel; a scheduled time-based completion sweep is a recommended future item.
2. Trophy Room lists all finishes (not just podium); non-podium finishes still render (e.g. "#5").
3. Carried over: 512px app icon; fail-open `/me` startup routing.

---

## v1.7.6 (part 2) — Home Cleanup, Tournament Delete Modal & Leaderboard UX

A UX polish pass (no gameplay/XP/rank/badge logic changes). +1 backend test (221 total).

### Home header cleanup
- Removed the duplicate native "BallPicker" nav-header title on Home (`headerShown: false`) so the
  horizontal `BallPickerHeader.png` is the true top hero. No empty bar; the branded surface header
  sits directly below the safe-area/status bar.

### Tournament delete / lobby delete modal
- Deleting a tournament from Home now uses the shared `ConfirmModal` with **status-aware copy**:
  - **Active/other:** "Delete tournament?" / "This will remove the tournament from your active list…"
    / buttons **Cancel** · **Delete tournament**.
  - **Lobby:** "Delete lobby?" / "This lobby has not started yet…" / buttons **Keep lobby** ·
    **Delete lobby**.
- Loading spinner while deleting, in-modal error on failure ("Could not delete the tournament…"),
  and **optimistic removal** from the list (no full refresh). Backend uses the existing
  `DELETE /leagues/{id}` **soft-cancel** (status → `cancelled`, owner-only) — unchanged.
- New backend test `LeagueTournamentLifecycleTest::test_owner_can_cancel_active_tournament`
  (existing tests already cover lobby cancel, non-owner 403, and cancelled-not-in-index).

### Leaderboard UX (weekly)
- Single continuous list (replaced the top/nearby view-toggle). Kept the "You are #X of Y" summary
  (`YourPositionCard`) and highlighted current-user row; added **Top** and **My rank** jump buttons
  (`FlatList` `scrollToOffset`/`scrollToIndex`, with `onScrollToIndexFailed` fallback). No infinite
  scroll added. No-rank state shows "Play a round to enter the leaderboard."
- Backend meta already provided `current_user_rank`/`total_players`/`better_than_percentage` — no
  fake numbers, guarded against NaN/undefined.

### Trophy Room discoverability
- New `TrophyRoomScreen` route reusing the existing self-fetching `TrophyRoom` component (earned +
  locked badges) — **not rebuilt**. Profile now has a **"Trophy Room" card** (CTA "Open ›"); the
  inline Trophy Room was moved off the long Profile page into its own screen.

### Period naming prep (weekly → monthly)
- Added `config('ballspot.leaderboard.period_label')` (default **"Weekly"**), echoed in the weekly
  leaderboard response as `period_label` and rendered by the app instead of a hardcoded string.
  **Only the label is centralized** — the aggregation window is still weekly; a real "Monthly"
  competition also needs the window/query changed (deliberately out of scope). Test asserts the
  field mirrors config.

### Scoring review (Part G) — no change made
- `ScoreService`: `score = max(0, round(100 - distance × 250))`, max **100**; linear falloff hitting
  **0 at distance ≥ 0.40** (of the normalized image space).
- **Perfect (100)** requires distance ≤ **0.002** (~0.2%) — very hard but **not literally distance 0**
  (rounding gives a small tolerance), so it is achievable and prestigious.
- **Almost Perfect (≥95)** requires distance ≤ **0.022** (~2.2%) — achievable.
- This matches the desired product feel (100 very rare, Almost Perfect attainable). **No bug found;
  scoring left unchanged.** If a future sprint wants 100 slightly more attainable, the safe levers
  are config-driven (loosen `max_score` rounding band or reduce the 250 slope) with new tests — a
  dedicated scoring-balancing sprint.

---

## v1.7.6 — QA, Product Polish & Release Readiness Pass

A QA pass across all main user flows (no new features). Bugs found and fixed:

- **[HIGH] Daily result was broken for every user** — `DailyChallengeGuess` did not cast
  `guess_x_ratio`/`guess_y_ratio`/`distance` to float (the tournament `Guess` model did), so the
  API returned them as strings. The app's `Number.isFinite()` guards then hid the distance, the
  "Right on it!"/"Way off" feedback, the ghost-ball "your guess" marker, and the guess
  coordinates. Fixed by adding the float casts; **new regression test**
  `DailyChallengeTest::test_daily_guess_returns_numeric_coordinates_not_strings` locks it (guess +
  result endpoints).
- **[MED] Destructive confirm modals could double-submit and hid failures** — `ConfirmModal` now
  accepts `loading` (disables both buttons, spinner on confirm, blocks backdrop/back dismissal) and
  `errorText` (shown inside the dialog instead of behind it). Wired into Delete account
  (Profile), Cancel tournament (Home), and Start tournament / Remove player (League detail), each
  with a re-entry guard. Fixes the "Start tournament twice → false 'can only start from lobby'
  error" and the invisible delete-account error.
- **[MED] Weekly leaderboard overflow** — the (uncapped) weekly leaderboard `FlatList` was not
  height-bounded, so rows and the "Back Home" button fell off-screen on a busy week. Bounded with
  `flex: 1` so it scrolls internally. (Tournament leaderboard is capped at 8 players → not
  affected.)
- **[LOW] Register ignored `email_verified`** — when email verification is disabled by config, a
  new account is already verified but the app still forced the (dead) verification screen. Register
  now routes straight into the app when `email_verified === true`.
- **[LOW] Register double-submit** via the keyboard "done" key — added a re-entry guard.
- **[LOW] Tournament guess screen** now shows an "Image unavailable" fallback (mirrors daily) if a
  round's hidden image is missing, instead of a blank tappable box.

Visible branding copy: replaced remaining user-facing "BallSpot" with "BallPicker" in the
public web pages (privacy, terms, footer/header) and the password-reset email subject (now
`config('ballspot.app_name')`). Internal `ballspot:*` commands, storage keys, namespaces, config
keys, admin-panel branding, and the backup-manifest `app_name` (test-locked) are unchanged.

Docs: fixed `docs/api-contract.md` discrepancies found in QA — the email-verification gate list
(sports/ranks are not verified-gated), `GET /profile/stats` field names
(`current_daily_streak`/`best_daily_streak`/`daily_challenges_played`), `POST /rounds/{id}/guess`
status (200, not 201) and its duplicate-guess message, plus the round guess response's `new_badges`
and rank/percentile fields.

### Known limitations carried forward (v1.7.6)

1. On a transient (non-401) `/me` failure at startup, unverified/onboarding users are routed to
   Home (a deliberate "fail-open rather than lock out" choice); `verified`-gated calls then 403
   until the next successful `/me`. Behavior intentionally unchanged.
2. `tournament_winner` badge remains seeded-but-not-auto-awarded (winner logic is a future sprint).
3. `VirtualizedList nested in ScrollView` warning on League detail (leaderboard sliced to 3 items)
   is benign and left as-is.

---

## v1.7.4 — Branding, Rank Overview & Badge Expansion

### Backend (219 passing, +12)

- **`RanksApiTest` (3):** `GET /api/ranks` returns all configured ranks, ordered by `min_xp`
  ascending, and requires authentication (mirrors existing policy; not verified-gated).
- **`XpLedgerTest` (+2):** `GET /api/me/xp-events?limit=5` returns at most 5 rows; an excessive
  `limit` is clamped to the server cap (50).
- **`BadgeExpansionTest` (7):** a perfect 100 guess awards **Perfect Picker** (and Almost Perfect);
  reopening the result does **not** re-award or return `new_badges`; a score of 97 awards
  **Almost Perfect** but not Perfect Picker; **Daily Debut** on first daily; badge XP is written to
  `xp_events`; **streak_3** and **streak_7** award on a 7-day streak; **Multi-Sport Starter** on a
  first non-football challenge.
- **`BadgeTest` updated:** catalogue is now **19** badges; `perfect_picker` seeded; seeder is
  idempotent (`updateOrCreate` on `code`); legacy awarding still verified.

### Mobile (`tsc --noEmit` clean)

- Home renders the horizontal **BallPickerHeader.png** hero (`resizeMode="contain"`, height 120,
  rounded bottom); the old "⚽ BallSpot" text header is gone. Native Home title is now "BallPicker".
- New **RankOverviewScreen** (`/ranks` + `/profile/stats`): completed / current / future / max-rank
  states, back button, typed route. Reached from a new "View all ranks" card on Profile.
- Profile Recent XP is capped at 5 with a clean "No XP activity yet." empty state.
- **NewBadgesCard** shows rarity and a distinct "Legendary badge unlocked" headline; renders
  cleanly alongside the rank-up card and with 1 or many badges.
- Visible "BallSpot" copy replaced with "BallPicker" (Home, Login, theme label). Internal
  identifiers (`ballspot:*` commands, `ballspot_*` storage keys, namespaces, config) unchanged.

### Constraints honored (v1.7.4)

- No gambling / prizes / money / payments / ads / chat / realtime.
- Did **not** run `migrate:fresh --seed`; `BadgeSeeder` is idempotent and was run standalone
  (`db:seed --class=BadgeSeeder`) — existing `user_badges` preserved.
- Existing Daily, Tournament, XP ledger and rank flows unchanged; no new XP table.

### Known limitations (v1.7.4)

1. **`tournament_winner`** is seeded and has idempotent awarding logic
   (`BadgeService::evaluateTournamentWin`), but nothing invokes it on tournament completion this
   sprint — treated as future.
2. **`streak_30`** relies on 30 days of real streak data; correct but only observable at scale.
3. **Legacy/canonical overlap:** some legacy badge codes still fire alongside the new canonical
   ones (kept so earned badges stay valid); a future sprint may consolidate.
4. Prior v1.7.x limitations still apply.

---

## v1.7.3 — XP Ledger, Rank-Up Moments, and Second Sport Launch Prep

This sprint introduces the **XP ledger** (`xp_events`) as the new source of truth for personal
rank/XP, adds **rank-up** moments and an **XP history** endpoint, and lays **second-sport launch
prep** (per-sport taglines, a readiness service, and a guarded daily scheduler). See the
[API contract](api-contract.md), [database schema](database-schema.md),
[store readiness](store-readiness.md), and
[prizes & trophy room](prizes-and-trophy-room.md) for details.

### Backend (207 passing, +18)

New test files:

- **XpLedgerTest** (new) — covers:
  - an XP event is created for an award;
  - a duplicate `(user, source_type, source_id)` is **not** awarded twice;
  - `getRecentXpEvents()` returns most-recent events;
  - `PlayerRankService` uses the **ledger** over the lifetime score when events exist;
  - **falls back** to lifetime score when the user has **no** ledger events;
  - a **daily guess** creates a `daily_guess` XP event (`+score`);
  - **reopening a result does not double-award** (deduped per guess id);
  - a **badge unlock** awards rarity XP **once** (deduped by badge id);
  - a **streak milestone** awards XP **once** (deduped by milestone day);
  - a guess that crosses a threshold returns the **`rank_up`** payload; otherwise `rank_up: null`;
  - `GET /api/me/xp-events` returns events + `total_xp` + `rank`;
  - **backfill dry-run** writes nothing;
  - **backfill** creates missing events without duplicates (idempotent).
- **SportReadinessTest** (new) — covers:
  - readiness counts **ready** challenges (active + hidden image + ball position);
  - a sport reports **ready** when the configured thresholds are met;
  - `ballspot:schedule-daily-challenges --sport=<coming_soon>` **warns and skips**;
  - the same command **proceeds** when `--allow-coming-soon` is passed.

Rank/XP is now **ledger-backed** (`xp_events`, append-only, anonymization-safe) with a
lifetime-score fallback until `ballspot:backfill-xp` runs. `GET /api/sports` gained a `tagline`
field. No migrations or tests were run as part of documenting this release.

### Mobile

- `npx tsc --noEmit` passes clean.
- New types: `RankUp`, `XpEvent`, `XpEventsResponse`; `Sport` gained `tagline`.
- Result screens (DailyResultScreen + tournament ResultScreen): new **RankUpCard** (premium
  static "RANK UP! You reached <Rank> · Level N", gold accent, no animation dependency) shown
  **only** when `rank_up` is present; the existing **RankProgressCard** shows "+N XP" and the
  progress bar. Both are optional — no broken placeholder when absent (e.g. viewing an old result).
- ProfileScreen: new "Recent XP" section (compact **RecentXpCard**) listing recent ledger events
  ("+511 Daily challenge completed", "+250 Badge unlocked: …"), fetched from
  `GET /me/xp-events` (limit 6); hidden when empty.
- Choose Sport shows each sport's **tagline**; `coming_soon` sports stay visible-but-disabled with
  a SOON badge and become selectable with **no new mobile code** once an admin flips them to
  `active` (data-driven).

### Constraints honored (v1.7.3)

No payments/IAP/ads/chat/realtime/betting/real-prizes. Rank/XP remains **cosmetic** progression —
XP cannot be bought, sold, or redeemed for anything of value. Football remains the only `active`
sport; other sports are `coming_soon` roadmap teasers with no purchasable content.

### Known limitations (v1.7.3)

1. **Tournament-win XP is config-ready but not awarded yet** — deferred until robust tournament
   completion/winner logic exists (`config('ballspot.xp.tournament_win')` exists but is unused).
2. **XP fallback (lifetime score) applies only until `ballspot:backfill-xp` runs** — run it once
   after deploy so the ledger becomes authoritative for all existing guesses.
3. **No anti-cheat beyond existing guess validation**, and **no full sport-specific scoring** yet.
4. Prior v1.7.x limitations still apply (one global daily per date; avatars not shown in
   leaderboards/lobbies; per-screen theming still partial).

---

## v1.7.2 — Sport Availability, Avatar Upload Fix, and User Rank XP Progression

This sprint adds sport availability statuses (`active` / `coming_soon` / `hidden`), fixes
cross-platform avatar upload, and introduces **personal** rank/level/XP progression (distinct
from leaderboard rank). See the [API contract](api-contract.md),
[database schema](database-schema.md), and [store readiness](store-readiness.md) for details.

### Backend (189 passing, +16)

New / updated test files:

- **PlayerRankTest** (new) — personal rank/XP progression: Rookie at 0 XP; both tournament
  (`guesses.score`) and daily (`daily_challenge_guesses.score`) scores count toward `total_xp`;
  crossing a rank threshold promotes the user; `progress_to_next_rank_pct` is computed; max rank
  (Ball Master) returns null `next_*` fields and `is_max_rank: true`; `GET /api/profile/stats`
  includes the `rank` object; `GET /api/me/rank` returns `{ rank: {...} }`.
- **SportStatusTest** (new) — tournament creation is rejected (422) for `coming_soon`/`hidden`
  sports; `is_active` stays synced with `status`; admin can set status via
  `POST /admin/sports/{sport}/status`; football cannot be moved off `active` ("Football must
  stay active."); an invalid status value is rejected.
- **SportsApiTest** (rewritten) — `GET /api/sports` returns **visible** sports (`active` +
  `coming_soon`) and **excludes** `hidden`; each sport carries `status`, `is_playable`,
  `is_coming_soon`, and the back-compat `is_active` flag. (The old `?include_inactive=1`
  parameter is gone.)
- **AvatarTest** (updated) — added a **valid PNG** upload case alongside the existing
  jpeg/webp/oversized/SVG/wrong-type coverage; still verifies storage under `avatars/` and
  that replacing/deleting only removes files under `avatars/`.
- **PreferencesTest** (updated) — setting `preferred_sport_id` to a `coming_soon` **or**
  `hidden` sport is rejected (422 "This sport is not available yet."); active sport succeeds;
  null clears the preference.
- **SportFilteringTest** (updated) — sport filtering now uses the status-based helper
  (`status = active`) rather than the raw `is_active` boolean.

Rank/XP is **derived on read** (sum of tournament + daily scores) — there is **no XP
transaction/ledger table** (documented limitation). `POST /api/me/avatar` backend behaviour is
unchanged; only the friendly validation message was unified to "Please choose a JPG, PNG or
WebP image under 2MB."

### Mobile

- `npx tsc --noEmit` passes clean.
- `src/api/avatarApi.ts` is now platform-aware: on web the picked `blob:`/`data:` URI is fetched
  into a real `Blob` and appended as a proper multipart file part (fixes "The avatar field must
  be a file." on Expo web); on native the RN `{ uri, name, type }` descriptor is used. Field
  name is exactly `avatar`.
- New types: `PlayerRank`, `RankProgress`; `Sport` gained `status`, `is_playable`,
  `is_coming_soon`.
- ProfileScreen shows a premium **RankCard** (rank name · level, total XP, progress bar,
  "N XP to <NextRank>", or "Max level" at the top). DailyResultScreen and tournament
  ResultScreen show a small **RankProgressCard** ("+N XP", "<Rank> progress: N%") **only** when
  rank progress is passed from a fresh guess — viewing an old result shows no card (no broken
  placeholder).
- Choose Sport / Profile "change sport": `active` sports are selectable (checkmark when
  selected); `coming_soon` sports are visible-but-dimmed with a "SOON" badge (tapping shows
  "<Sport> is coming soon."); `hidden` sports are never shown.

### Constraints honored (v1.7.2)

No payments/IAP/ads/chat/realtime/betting/real-prizes. No migrations or tests were run as part
of documenting this release. Football remains the only `active` sport; other sports are
`coming_soon` roadmap teasers with no purchasable content. Rank/XP is cosmetic progression with
no real rewards or money.

### Known limitations (v1.7.2)

1. **No XP transaction/ledger table** — `total_xp` is derived on read from the sum of tournament
   + daily scores each call. Non-score XP sources (badges, bonuses) would warrant a ledger.
2. **XP equals lifetime score total** — badges do not add XP yet.
3. Prior v1.7 limitations still apply (one global daily per date; avatars not shown in
   leaderboards/lobbies; per-screen theming still partial).

---

## v1.6.2 — Email Verification at Registration + Configurable Login 2FA

This sprint **adjusts** the always-on email 2FA from v1.6.1. Email verification now
happens at **registration**; normal login is email+password once verified; the
6-digit login 2FA is **off by default** and opt-in via `force_login_2fa` (admins
always get 2FA). See [security-auth.md](security-auth.md) for the design.

### Backend (172 passing, +11)

New test file:

- **EmailVerificationTest** (11 tests) — covers:
  - registration creates an **unverified** user and sends a verification code;
  - the code is stored **hashed**, not in plain text;
  - an unverified user gets **403** on protected endpoints but **200** on `/me`;
  - verifying the code **grants access** to protected endpoints;
  - a **wrong code** fails (generic 422);
  - `POST /email/verify` and `POST /email/verification-notification` **require auth**
    (401 without a token);
  - resend sends a **new** code and is **cooldown-limited** (60s);
  - login with an unverified account returns `requires_email_verification`;
  - login with a verified account returns a **token** (no 2FA) on the default path;
  - **forced 2FA** works when `force_login_2fa` is enabled;
  - **admin** login always goes through 2FA.

Updated test files:

- **AuthTest** — a verified login now asserts a **token** is returned (email+password
  is enough by default).
- **PasswordResetTest** — the user is verified; after a password reset, the
  subsequent login asserts a **token** is returned.
- **EmailTwoFactorLoginTest** — sets `config force_login_2fa=true` in `setUp()` to
  exercise the forced-2FA path (its existing 2FA assertions are unchanged).

### Mobile

- `npx tsc --noEmit` passes clean.
- `authApi.login` now returns a **3-way** `LoginResult` union (`AuthResponse` |
  `TwoFactorRequired` | `EmailVerificationRequired`) with `isTwoFactorRequired` and
  `isEmailVerificationRequired` guards; new `authApi.verifyEmail` and
  `authApi.resendEmailVerification`; `User` gains `email_verified`.
- New `EmailVerificationScreen` ("Check your email"): 6-digit autofocus input,
  "Verify email", resend with 60s cooldown, "Back to login" (clears the pending
  token). RegisterScreen routes here after register; LoginScreen routes here on
  `requires_email_verification`; AppNavigator boot routes unverified logged-in users
  here. The existing `LoginVerificationScreen` is retained for the forced-2FA/admin
  path.

### Constraints honored (v1.6.2)

Email-only codes (no SMS/TOTP/passkeys). No migrations or tests were run as part of
documenting this release. No payments/IAP/ads/chat/realtime/betting. Account
deletion still works for an unverified user.

---

## v1.6.1 — Secure Email Two-Factor Login (adjusted by v1.6.2)

> **Superseded:** always-on login 2FA is now opt-in (see v1.6.2). The
> `EmailTwoFactorLoginTest` below now runs with `force_login_2fa=true` in `setUp()`;
> `AuthTest`/`PasswordResetTest` were updated to expect a token on verified login.

### Backend (161 passing, +15 net)

Login was email two-factor: a correct password emailed a one-time 6-digit code
and returned a `verification_id` instead of a token; the token was only issued after
the code was verified. See [security-auth.md](security-auth.md) for the design.

New test file:

- **EmailTwoFactorLoginTest** — covers:
  - valid login starts 2FA and returns **no token**;
  - invalid credentials send **no code**;
  - unknown email returns the generic error and sends **no code** (no enumeration);
  - the code is stored **hashed**, not in plain text;
  - `/me` is blocked before verification;
  - verify returns a token and grants `/me` access;
  - a wrong code fails and **increments the attempt counter**;
  - an expired code fails;
  - a consumed code cannot be reused;
  - reaching max attempts **locks** the code (even the correct value is rejected);
  - resend creates a new code and invalidates the old one;
  - resend is **cooldown-limited** (60s);
  - resend on an unknown/expired session responds "Please login again.";
  - account deletion still works after a verified login;
  - the `ballspot:cleanup-login-codes` command removes stale (expired + consumed) codes.

Updated test files:

- **AuthTest** — login now asserts `requires_2fa` and that **no token** is returned.
- **PasswordResetTest** — after a password reset, the subsequent login asserts
  `requires_2fa` (post-reset login also goes through 2FA).

### Mobile

- `npx tsc --noEmit` passes clean.
- `authApi.login` now returns a `LoginResult` union (`AuthResponse` |
  `TwoFactorRequired`) with an `isTwoFactorRequired` type guard; new
  `authApi.verifyLoginCode` and `authApi.resendLoginCode`.
- New `LoginVerificationScreen` ("Check your email"): 6-digit autofocus input,
  "Verify and continue", "Resend code" with a 60s cooldown countdown, and "Back to
  login". The token is stored **only** after successful verification, then the app
  resets to Home (or SportSelection for users with no sport).

### Constraints honored (v1.6.1)

Email-only 2FA (no SMS/TOTP/passkeys this sprint). No migrations or tests were run
as part of documenting this release. No payments/IAP/ads/chat/realtime/betting.
Registration is unchanged (no 2FA on register).

---

## v1.7 — Sport Selection, Themes, and Profile Avatar

### Backend (146 passing, +27 new)

New/updated test files:

- **PreferencesTest** — `GET`/`PATCH /api/me/preferences`: returns preferred sport, theme,
  avatar URL, and the available-themes allow-list; partial updates; theme must be in the
  allow-list (422 otherwise); `preferred_sport_id` must exist AND be active; null clears the
  preference; clean 422 validation.
- **AvatarTest** — `POST /api/me/avatar` accepts jpeg/jpg/png/webp up to 2 MB and rejects
  SVG / oversized / wrong-type files (422); stores under `avatars/` on the public disk with
  a randomized name; replacing an avatar deletes only the previous file under `avatars/`
  (never challenge images); `DELETE /api/me/avatar` clears `avatar_path` and returns
  `{ avatar_url: null }`.
- **SportsApiTest** — `GET /api/sports` returns active sports by default and inactive ones
  with `?include_inactive=1`; verifies the sport fields (id, name, slug, emoji, object_name,
  primary_color, is_active).
- **SportFilteringTest** — sport-aware behaviour: daily-by-sport (matching vs. mismatched
  sport returns the clean no-daily payload with a `sport` block), tournament-by-sport
  (`sport_id` precedence and rounds drawn only from the tournament's sport), and the
  schedule command's `--sport=<slug>` flag (only that sport's active challenges; unknown
  slug fails friendly).
- **LeagueTournamentLifecycleTest** (updated) — the "no active challenges" start error now
  asserts the **dynamic sport name** in the message.

Account deletion and all prior v1.6 tests still pass. `GET /api/me` (UserResource) now also
returns `selected_theme`, `avatar_url`, and `preferred_sport` for the authenticated user.

Admin: `GET /admin/sports` index + `POST /admin/sports/{sport}/toggle` (football protected
from deactivation).

### Mobile

- `npx tsc --noEmit` passes clean.
- New theme system: `mobile/src/theme/themes.ts` (5 themes, full token set), `ThemeProvider`
  (React context; persists to AsyncStorage `ballspot_theme`; syncs to backend via
  `PATCH /me/preferences`; applies the server theme on login), `useTheme.ts`. App wrapped in
  `<ThemeProvider>`.
- New `SportSelectionScreen` (after register always; after login/app-start when no preferred
  sport; reachable in `mode: 'change'` from Profile and the Home sport chip).
- Home: selected-sport chip with "Change sport", sport-scoped daily fetch, sport-named empty
  state, small avatar in the top bar.
- Profile: avatar (photo or initials) with "Change photo" (`expo-image-picker`, permission
  request, uploads to `POST /me/avatar`), a "Your sport" row, and a 5-card "App theme"
  picker applied immediately and persisted.
- `expo-image-picker` (~56.0.21) added; `app.json` plugin with a `photosPermission` string.
- Fully themed: shared components (Screen, AppButton, AppInput) plus HomeScreen,
  ProfileScreen, DailyChallengeScreen, LoginScreen, CreateLeagueScreen, SportSelectionScreen.

### Constraints honored (v1.7)

No payments/IAP/ads/chat/realtime/betting/real-prizes. No migrations or tests were run as
part of documenting this release. The "Tournament Blue" theme is original styling and uses
no UEFA logos, names, or protected assets. Football remains the only active sport by default.

### Known limitations (v1.7)

1. **One global daily challenge per date** — `daily_challenges.challenge_date` is unique.
   Simultaneous per-sport dailies on the same date need a schema change (composite unique on
   `challenge_date` + sport).
2. **Only football is active by default** — other sports need admin activation + content
   before they appear as selectable.
3. **Avatars not shown in leaderboards / tournament lobbies** — those payloads don't include
   `avatar_url` yet.
4. **Full per-screen theming pending** — core screens + shared components are themed; other
   secondary screens still use the classic palette for inline text/cards (functional, with a
   themed background).
5. **No payments/ads/chat/realtime/gambling** — unchanged.

---

## v1.6 — Gamification, Leaderboards, Password Reset, Multi-Sport Foundation

### Backend

- **Password reset** (`PasswordResetController`, `ResetPasswordNotification`): forgot
  returns generic success; reset validates token, revokes tokens, enforces
  registration password rules. `PasswordResetTest` — 6 tests (generic success, no
  enumeration, invalid token fails, valid token changes password + old fails/new
  works, tokens revoked, password rules).
- **Rank / percentile**: daily result + weekly/tournament leaderboards return
  rank/`better_than_percentage` + `meta` block. `DailyRankMetaTest` — 3 tests.
- **Badges** (`badges`, `user_badges`, `BadgeService`, `BadgeController`): idempotent
  awarding, `GET /badges`, `GET /me/badges`, `new_badges` on guesses.
  `BadgeTest` — 6 tests (seeded, awarded once, first-daily, top-10%, me/badges
  earned+locked, catalogue).
- **Multi-sport + tags** (`sports` columns, 7 seeded; `tags`/`challenge_tag`):
  `SportAndTagTest` — 3 tests (all sports seeded/football active, tags attach,
  findOrCreate idempotent). Admin challenge create/edit gains sport + tags.
- **Tournament limits** (`config/ballspot.php` → `tournaments`; enforced in
  `LeagueService`): `TournamentLimitsTest` — 4 tests (create limit, archived/
  cancelled excluded, full blocks join, member under limit joins).

### Mobile

- ForgotPassword + ResetPassword screens; Forgot link on Login.
- RankInsight + YourPositionCard on results/leaderboards; weekly Top/My-rank toggle.
- TrophyRoom in Profile; NewBadgesCard on daily + tournament results.
- Sport badge + tag chips on daily challenge; free-plan note on Create Tournament.

### Foundation-only (docs, not implemented)

- `docs/notifications-plan.md` — push notification plan (opt-in, privacy).
- `docs/prizes-and-trophy-room.md` — virtual trophies now; real prizes gated behind
  legal review, no gambling, no purchase-to-enter.

### Constraints honored

No payments/IAP/ads/chat/realtime/betting/real-prizes. `migrate:fresh --seed` not
run. Storage, git, and uploaded images untouched. Football Daily Challenge flow
unchanged; football remains the only active sport.

---

## What Was Implemented

### Backend (Laravel 12) — v1.5.5

- **`DELETE /api/account`:** anonymizes and deactivates current user's account (name→"Deleted User", email→deleted-{id}@ballspot.deleted, username→deleted-{id}, password randomized, all Sanctum tokens revoked). Row preserved for leaderboard FK integrity.
- **Public legal pages:** `/privacy`, `/terms`, `/support` — Blade views with dark mobile-first theme, no auth required
- **`PublicController`:** renders privacy/terms/support views with `support_email` and `web_url` from `config/ballspot.php`
- **`config/ballspot.php`:** reads `BALLSPOT_SUPPORT_EMAIL` and `BALLSPOT_WEB_URL` env vars with sensible defaults
- **`AccountController@delete`:** API endpoint for account deletion via Sanctum auth
- **`AccountDeletionTest`:** 5 tests (unauthenticated→401, authenticated→200+message, all tokens revoked in DB, data anonymized, row not hard-deleted)
- **`ballspot:store-readiness-check` command:** read-only report covering env config, content readiness, daily challenge schedule, storage symlink, backups .gitignore, public legal routes. FAIL only for broken infrastructure; content gaps are WARN.
- **`StoreReadinessCheckTest`:** 5 tests (runs successfully, warns on no challenges, passes with 7 challenges, warns on demo content, reports public routes as passing)

### Mobile (Expo 56) — v1.5.5

- **ProfileScreen Settings section:** Privacy Policy / Terms of Service / Support links — opens in device browser via `Linking.openURL` with URL derived from `EXPO_PUBLIC_WEB_URL`
- **ProfileScreen Delete account:** "Delete account" button → ConfirmModal (destructive) → `DELETE /api/account` → clear token → navigate to Login; error message shown inline without crash
- **`authApi.deleteAccount()`:** `DELETE /account` typed method added
- **`mobile/.env.example`:** `EXPO_PUBLIC_WEB_URL` variable added

### Backend (Laravel 12) — v1.5.3

- **`ballspot:schedule-daily-challenges` command:** schedules eligible active challenges for N days using LRU selection; `--dry-run`, `--force`, `--start=YYYY-MM-DD` options; demo-only fallback with warning; never deletes challenges or images
- **Daily `today()` guard:** returns `has_daily: false` if linked challenge was archived or deleted after scheduling, instead of crashing
- **Daily admin index:** shows upcoming 14-day schedule table, artisan helper text, and warning banner when fewer than 7 ready challenges exist
- **ScheduleDailyChallengesTest:** 8 new tests (dry-run, creates N days, skips existing, ignores draft/incomplete, avoids duplicates in range, force-replace, no-eligible-failure, invalid-start-date validation)
- **DailyChallengeTest:** 1 new test (archived challenge guard in today endpoint)

### Mobile (Expo 56) — v1.5.3

- **ResultScreen:** "Back to League" renamed to "Back to Tournament"; when no next round available, "Back to Tournament" is shown as primary button; done-for-today flavour text shown as subtitle rather than styled box

---

### Backend (Laravel 12) — v1.5.2

- **Challenge model helpers:** `isReady()`, `isReadyForDaily()`, `isDemoContent()`, `dailyChallenges()` relationship
- **Activation guard:** `update()` and `updateStatus()` block activating a challenge without a hidden image
- **Quick status actions:** `POST /admin/challenges/{id}/status` — archive/activate/draft without full edit form
- **Preview route:** `GET /admin/challenges/{id}/preview` — shows hidden image with ball marker, readiness status, set-as-daily shortcut
- **Set-as-daily route:** `POST /admin/challenges/{id}/set-as-daily` — assigns challenge to a date, guards against replacing a daily with existing guesses
- **Index overhaul:** new filters (title search, has_reveal, used_as_daily), readiness badges, demo content badges, daily-used badges, archive quick action replaces delete button in UI
- **Edit view:** readiness indicator at top, set-as-daily shortcut when ready, Preview link
- **Daily admin views:** readiness column, warn about missing image, link to challenge edit, demo badge
- **Admin nav:** Daily link added
- **Error flash:** `session('error')` rendered in layout alongside success
- **AdminChallengeWorkflowTest:** 17 new tests covering readiness helpers, activation guard, quick actions, archive-not-delete, preview route, ball position persistence, index filters, set-as-daily

### Backend (Laravel 12) — v1.5.1

- **Content backup command:** `php artisan ballspot:backup-content` — copies SQLite DB, uploaded images, and exports JSON metadata to a timestamped folder outside the web root
- **Backup inspect command:** `php artisan ballspot:inspect-backup <folder>` — prints manifest summary of a backup
- **Orphaned image recovery:** `php artisan ballspot:recover-challenges [--dry-run]` — creates draft challenge records for uploaded images not referenced by any challenge
- **Admin safety warning:** backup reminder alert on challenge index, create, and edit pages
- **Seeder hardening:** `DailyChallengeSeeder` fixed to use `whereDate()` for idempotent lookup (avoids unique constraint violation on second run)
- **Content safety docs:** `docs/content-safety.md` added with full backup/restore/recovery workflow

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

## How to Back Up Content

```bash
cd backend
php artisan ballspot:backup-content
# → creates backups/ballspot-content/YYYY-MM-DD-HHMMSS/

php artisan ballspot:inspect-backup backups/ballspot-content/2026-06-24-120000
# → prints manifest summary

php artisan ballspot:recover-challenges --dry-run
# → preview orphaned image recovery without writing

php artisan ballspot:recover-challenges
# → create draft challenges for orphaned images
```

---

## Backend Test Results

Run: `cd backend && php artisan test`

```
Tests:    97 passed (285 assertions)
Duration: ~2s
```

| Test File | Test | Status |
|-----------|------|--------|
| **AccountDeletionTest** | unauthenticated request returns 401 | ✅ |
| **AccountDeletionTest** | authenticated user can delete account | ✅ |
| **AccountDeletionTest** | all tokens are deleted after deletion | ✅ |
| **AccountDeletionTest** | user data is anonymized after deletion | ✅ |
| **AccountDeletionTest** | user row is not hard deleted | ✅ |
| **StoreReadinessCheckTest** | command runs successfully | ✅ |
| **StoreReadinessCheckTest** | warns when no active ready challenges | ✅ |
| **StoreReadinessCheckTest** | passes when enough active ready challenges exist | ✅ |
| **StoreReadinessCheckTest** | warns about demo content when present | ✅ |
| **StoreReadinessCheckTest** | reports public routes as passing | ✅ |
| **AdminChallengeWorkflowTest** | is ready true when all fields set | ✅ |

| **AdminChallengeWorkflowTest** | is ready false when hidden image missing | ✅ |
| **AdminChallengeWorkflowTest** | is ready for daily requires active status | ✅ |
| **AdminChallengeWorkflowTest** | is demo content returns true for known titles | ✅ |
| **AdminChallengeWorkflowTest** | is demo content returns false for custom title | ✅ |
| **AdminChallengeWorkflowTest** | cannot activate challenge without hidden image | ✅ |
| **AdminChallengeWorkflowTest** | can save draft challenge without hidden image | ✅ |
| **AdminChallengeWorkflowTest** | quick status archive works | ✅ |
| **AdminChallengeWorkflowTest** | quick activate blocked without hidden image | ✅ |
| **AdminChallengeWorkflowTest** | archive does not delete the challenge | ✅ |
| **AdminChallengeWorkflowTest** | preview route loads for existing challenge | ✅ |
| **AdminChallengeWorkflowTest** | ball position persists through update | ✅ |
| **AdminChallengeWorkflowTest** | index filter by status | ✅ |
| **AdminChallengeWorkflowTest** | index filter by title search | ✅ |
| **AdminChallengeWorkflowTest** | index filter no reveal image | ✅ |
| **AdminChallengeWorkflowTest** | set as daily creates daily challenge record | ✅ |
| **AdminChallengeWorkflowTest** | set as daily blocked when not active | ✅ |
| **ContentSafetyTest** | backup command creates manifest | ✅ |
| **ContentSafetyTest** | backup command exports challenges json | ✅ |
| **ContentSafetyTest** | recover dry run does not create records | ✅ |
| **ContentSafetyTest** | recover creates draft for orphaned image | ✅ |
| **ContentSafetyTest** | recover does not create record for referenced image | ✅ |
| **ContentSafetyTest** | challenge seeder does not duplicate on second run | ✅ |
| **ContentSafetyTest** | daily challenge seeder is idempotent | ✅ |
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
| ScheduleDailyChallengesTest | dry run does not create daily challenges | ✅ |
| ScheduleDailyChallengesTest | command creates daily challenges for requested days | ✅ |
| ScheduleDailyChallengesTest | command skips existing daily challenges | ✅ |
| ScheduleDailyChallengesTest | command does not use incomplete or draft challenges | ✅ |
| ScheduleDailyChallengesTest | command avoids duplicate challenge use within range | ✅ |
| ScheduleDailyChallengesTest | force replaces existing daily challenge without duplication | ✅ |
| ScheduleDailyChallengesTest | no eligible challenges prints error and fails | ✅ |
| ScheduleDailyChallengesTest | invalid start date shows friendly error and fails | ✅ |
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

2. **Daily challenge bulk scheduling via Artisan** — Use `php artisan ballspot:schedule-daily-challenges --days=14` to fill the schedule automatically (LRU selection). Individual challenges can also be created via `/admin/daily/create` or `php artisan db:seed --class=DailyChallengeSeeder`. No cron wiring yet — the command must be run manually or scheduled via server cron.

3. **`next_available_at` is always null** — The API includes this field in all `current-round` responses but always returns `null`. Future implementation would return "tomorrow at midnight UTC" or a specific time.

4. **Best streak is computed on demand** — `DailyStreakService::getStreakForUser()` walks all the user's daily guess dates each call. This is fine for small datasets but would need caching or a stored column for large user bases.

5. **`weekly_rank` in `/daily/stats` is computed fresh** — Rank is calculated from a live DB query on each call. There is no caching; for very high traffic this could be slow.

6. **SVG images may not render in all React Native versions** — The demo challenges ship with SVG placeholders. React Native's `Image` component does not natively support SVG. If images appear blank, upload JPEG/PNG replacements via the admin panel. See `docs/challenge-content-guide.md` for image specifications.

7. **Lobby polling is not realtime** — Member list updates every 3 seconds. A player joining will appear on the host's screen within 3 seconds. For true realtime, upgrade to WebSockets or Server-Sent Events.

8. **No push notifications** — Round availability and new daily challenges are not pushed. Users must open the app.

9. **Rounds are always open** — `opens_at`/`closes_at` are nullable and unused in v1. All rounds are playable immediately after `POST /start`.

10. **Avatar (v1.7)** — Profile avatar upload is now implemented (`POST`/`DELETE
    /api/me/avatar`, `expo-image-picker`). Avatars are not yet shown in leaderboards or
    tournament lobbies (those payloads don't include `avatar_url`).

11. **Token storage on web** — expo-secure-store has no web implementation. Tokens are stored in `sessionStorage` on web (cleared on tab close). For production web, migrate to HttpOnly+Secure+SameSite cookies.

---

## Next Recommended Sprint

### High Priority
- [ ] Replace SVG demo images with JPEG/PNG for broad React Native compatibility
- [x] Auto-schedule daily challenges — `ballspot:schedule-daily-challenges` Artisan command (v1.5.3)
- [ ] Completed league status auto-trigger — currently `completed` must be set manually; add auto-complete when all rounds played by all members
- [ ] Round time windows (`opens_at`/`closes_at` enforced in API)

### Medium Priority
- [ ] Push notifications when new daily challenge is available
- [ ] `next_available_at` in current-round response (return tomorrow UTC midnight)
- [ ] Cache streak and weekly rank for performance
- [x] Profile avatar upload — implemented in v1.7 (`POST`/`DELETE /api/me/avatar`)
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

---

## v1.8.2 — mobile polish

Sprint plan: `docs/superpowers/plans/2026-08-02-mobile-polish-v182.md` (17 tasks, all implemented).

### Backend suite

Run: `cd backend && php artisan test`

```
Tests:    1 skipped, 395 passed (1470 assertions)
Duration: ~13s
```

Up from 369 passed at the start of the sprint. New test files:

| File | Tests | Covers |
|---|---|---|
| `FriendsTest` | 16 | Friend code generation/uniqueness, auth gate on all 7 routes, send/accept/reject/remove, case-insensitive lookup, self-request, duplicate-request and already-friends guards, incoming/outgoing split |
| `PublicProfileTest` | 4 | Auth gate, field allow-list, **never leaks email / password / is_admin / friend_code / email_verified_at**, friendship state |
| `LeagueHideTest` | 8 | Auth gate, member-only, completed-only (422 on active/lobby), idempotency, list filtering, other members unaffected, **hidden tournament still in Profile history** |
| `ImageUrlTest` | 1 | Absolute storage URL regression guard (Task 7) |

The single skipped test is pre-existing and unrelated to this sprint.

### Mobile typecheck

Run: `cd mobile && npx tsc --noEmit` — **clean, no output.**

### Web export smoke test

Run: `cd mobile && npx expo export --platform web` — **succeeded.**

```
Web Bundled 7552ms index.ts (763 modules)
Exported: dist
```

`expo-camera` bundled for web without error, so the `Platform.OS === 'web'` guard the
plan held in reserve for `CameraView` was **not needed**. Camera capability on web is
still limited at runtime; the scanner's permission-denied path offers manual code entry.

### Manual device checklist

**NOT RUN — no device or simulator was available in this environment.** Every item in
Task 17 Step 4 remains outstanding and must be worked through on real hardware before
release. The items that no automated check in this sprint covers, and which therefore
carry the most risk:

- Fullscreen viewer open/close via X, background tap and Android hardware back.
- Game-mode back behaviour (tournament → Home, pack → Packs, Android hardware back).
- Reveal-image placement directly under the score card on both result screens.
- Network inspector: no `/result` 404 before submitting, no repeated failing image requests.
- Friends end-to-end across two accounts (send → accept → both lists).
- **QR scan against a second device** — QR *rendering* and the scanner's parse/permission
  branches are typechecked only; no scan has been executed.
- Scroll behaviour on iPhone and Android after the `Screen` contentContainer fix below.

### Fix worth re-verifying on device

`Screen`'s scroll branch passed `flex: 1` as `contentContainerStyle`, which pins the
content container to the viewport height so taller content is clipped instead of
scrolling. Changed to `flexGrow: 1`. This is the canonical fix and cannot regress a
screen that already fitted, but it touches **all 22 screens** that use `<Screen scroll>`,
so scrolling deserves a pass on device.

### Deployment note

`expo-camera`, `expo-clipboard`, `react-native-svg` and `react-native-qrcode-svg` are
native dependencies and `app.json` gained the `expo-camera` config plugin. **A JS-only
OTA update is not sufficient — a new EAS build and store submission is required.**

Migrations added: `users.friend_code` (+ backfill), `friend_requests`, `friendships`,
`league_members.hidden_at`. All are guarded with `Schema::hasColumn`/`hasTable` and are
safe to run on production data with `php artisan migrate`.
