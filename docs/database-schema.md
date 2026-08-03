# BallSpot Database Schema

Database: SQLite (dev) / MySQL (production)

---

## users
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK auto |
| name | varchar(255) |
| username | varchar(255) unique |
| email | varchar(255) unique |
| email_verified_at | timestamp nullable |
| password | varchar(255) hashed |
| remember_token | varchar(100) nullable |
| is_admin | boolean | default false — true grants admin Blade access |
| preferred_sport_id | bigint FK → sports nullable | **v1.7** — user's chosen sport; `nullOnDelete`; null = no preference (defaults to football) |
| selected_theme | varchar(255) | **v1.7** — default `'classic'`; must be in the `config/ballspot.php` themes allow-list |
| avatar_path | varchar(255) nullable | **v1.7** — relative path on the `public` disk under `avatars/`; null = no avatar |
| created_at / updated_at | timestamp |

Added by migration `2026_07_22_000001_add_preferences_to_users.php` (v1.7).

## sports
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK auto |
| name | varchar(255) |
| slug | varchar(255) unique | e.g. 'football' |
| emoji | varchar(255) | default '⚽' — display icon (no copyrighted assets) |
| object_name | varchar(255) | default 'ball' — e.g. 'ball' / 'puck' |
| primary_color | varchar(255) | default '#00c853' — sport accent color |
| status | varchar(20) | **v1.7.2** — `active` \| `coming_soon` \| `hidden`; default `coming_soon`. **Source of truth** for availability (mirrors `is_active`) |
| is_active | boolean | default false — **mirrored** from `status` (`is_active == (status === 'active')`) via a model mutator; kept for back-compat so existing queries keep working |
| sort_order | int | default 0 |
| scoring_profile | varchar(255) | default 'default' — foundation for future per-sport scoring |
| created_at / updated_at | timestamp |

**`status` meanings (v1.7.2):**
- `active` — visible + selectable/playable.
- `coming_soon` — visible in the app but disabled ("Coming soon"); not selectable as a
  preference and not usable to create a tournament (`GET /api/sports` returns it; API rejects
  it with 422 "This sport is not available yet.").
- `hidden` — not shown to normal users (excluded from `GET /api/sports`).

`status` was added by a migration; the legacy `is_active` boolean is retained and kept in sync
via a model mutator, so `is_active` always equals `status === 'active'`. Preference and
tournament validation require `status = active`.

Seeded sports (v1.7.2, via `SportSeeder`): football = `active`; golf, tennis, hockey, cricket,
american_football, basketball = `coming_soon`. Admin sets status through the dropdown at
`/admin/sports` (`POST /admin/sports/{sport}/status`); football is protected and cannot be
moved off `active`.

## challenge_categories
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK auto |
| sport_id | bigint FK → sports | cascade delete |
| name | varchar(255) | e.g. 'Corner Kicks' |
| slug | varchar(255) | URL-safe, unique per sport |
| description | varchar(255) nullable |
| sort_order | integer | default 0; lower = first in lists |
| is_active | boolean | default true; inactive categories hidden from challenge assign UI |
| created_at / updated_at | timestamp |
| | unique(sport_id, slug) |

Default seeded categories (football): General, Corner Kicks, Dribbles, Goalkeeper Saves, Headers, Penalties, Hard Mode.

## challenges
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK auto |
| sport_id | bigint FK → sports | cascade delete |
| challenge_category_id | bigint FK → challenge_categories nullable | null on delete |
| title | varchar(255) |
| hidden_image_path | varchar(255) | relative to storage/public |
| original_image_path | varchar(255) nullable | reveal image shown post-guess |
| ball_x_ratio | decimal(8,6) | 0.000000 .. 1.000000 |
| ball_y_ratio | decimal(8,6) |
| difficulty | varchar | 'easy' \| 'medium' \| 'hard' |
| status | varchar | 'draft' \| 'active' \| 'archived' |
| created_at / updated_at | timestamp |

## leagues
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK auto |
| name | varchar(255) |
| join_code | varchar(255) unique | 6 uppercase chars |
| owner_user_id | bigint FK → users | cascade delete |
| sport_id | bigint FK → sports |
| duration_days | integer | 1, 3, or 7 |
| rounds_per_day | integer | 1 or 3 — max rounds each user may play per calendar day |
| starts_at | datetime nullable |
| ends_at | datetime nullable |
| status | varchar | 'lobby' \| 'active' \| 'completed' \| 'cancelled' |
| created_at / updated_at | timestamp |

## league_members
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK auto |
| league_id | bigint FK → leagues | cascade delete |
| user_id | bigint FK → users | cascade delete |
| joined_at | datetime |
| hidden_at | datetime nullable | v1.8.2. Per-user "remove this finished tournament from my list". Set by `POST /api/leagues/{league}/hide`; filtered with `wherePivotNull('hidden_at')` in `LeagueController::index`. Nothing is deleted — guesses, scores, leaderboards, XP, badges and `tournament_finishes` are untouched, and the tournament still appears in Profile history. Living on the membership pivot makes "you can only hide a tournament you belong to" structural rather than a check that can be forgotten. |
| created_at / updated_at | timestamp |
| | unique(league_id, user_id) |
| | index(user_id, hidden_at) |

## league_rounds
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK auto |
| league_id | bigint FK → leagues | cascade delete |
| challenge_id | bigint FK → challenges | cascade delete |
| round_number | integer |
| opens_at | datetime nullable | null = immediately open |
| closes_at | datetime nullable | null = never auto-close |
| status | varchar | 'open' \| 'closed' |
| created_at / updated_at | timestamp |

## tournament_finishes (v1.7.7)
Final standings for a completed tournament — one row per member, written once (idempotently) by `TournamentCompletionService`. Virtual recognition only (no prizes/money).
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK auto | |
| league_id | bigint FK → leagues | cascade delete |
| user_id | bigint FK → users | cascade delete |
| placement | unsigned int | 1 = winner |
| total_score | integer | sum of the member's round scores |
| rounds_played | unsigned int nullable | |
| metadata | json nullable | e.g. `{ "total_players": 8 }` |
| created_at / updated_at | timestamp | `created_at` = completion time |

**Unique** `(league_id, user_id)` — makes completion idempotent. Index `(user_id, placement)` for Trophy Room lookups. Tie rule: total score desc, then earliest completion (last-guess time) asc, then user id asc.

## competition_finishes (v1.8.0)
Final top-3 standings for a CLOSED competition period (monthly/weekly daily-challenge leaderboard) — written once (idempotently) by `CompetitionCloseService` via `php artisan ballspot:close-competition`, never for the current open period. Virtual recognition only (no prize/payment/money fields by design).
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK auto | |
| user_id | bigint FK → users, nullable | `nullOnDelete` — historical record survives account removal (deletion currently anonymizes in place) |
| period_type | varchar | 'monthly' \| 'weekly' |
| period_label | varchar | e.g. "June 2026" / "Week 24, 2026" |
| period_start | date | |
| period_end | date | |
| placement | unsigned int | 1 = winner; only REAL placements are stored (max top 3, never fake) |
| total_score | integer | sum of daily-challenge guess scores in the window |
| total_players | unsigned int | actual eligible players in the period |
| xp_awarded | integer default 0 | ledger amount granted on close (1st 2000 / 2nd 1000 / 3rd 500) |
| metadata | json nullable | e.g. `{ "challenges_played": 12, "avg_score": 81.5 }` |
| awarded_at | timestamp nullable | when XP was granted |
| created_at / updated_at | timestamp | |

**Unique** `(period_type, period_start, period_end, user_id)` — makes closing idempotent. Index `(user_id, placement)` for Trophy Room lookups. Tie rule (shared with the live leaderboard via `CompetitionStandingsService`): total score desc, then earliest last-qualifying guess asc, then user id asc.

## guesses
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK auto |
| league_round_id | bigint FK → league_rounds | cascade delete |
| user_id | bigint FK → users | cascade delete |
| guess_x_ratio | decimal(8,6) | 0..1 |
| guess_y_ratio | decimal(8,6) | 0..1 |
| distance | decimal(10,6) | Euclidean distance of ratios |
| score | integer | 0..100 |
| submitted_at | datetime |
| created_at / updated_at | timestamp |
| | unique(league_round_id, user_id) |

---

## Daily Challenge Tables

### daily_challenges
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK auto |
| challenge_id | bigint FK → challenges | cascade delete |
| challenge_date | date unique | one challenge per calendar day |
| status | varchar | 'scheduled' \| 'active' \| 'archived' |
| created_at / updated_at | timestamp |

**Status values:**
- `scheduled` — created for a future date; not yet visible to players
- `active` — visible and playable today
- `archived` — past challenge; results remain readable but it won't appear in the today endpoint

### daily_challenge_guesses
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK auto |
| daily_challenge_id | bigint FK → daily_challenges | cascade delete |
| user_id | bigint FK → users | cascade delete |
| guess_x_ratio | decimal(8,6) | 0..1 |
| guess_y_ratio | decimal(8,6) | 0..1 |
| distance | decimal(10,6) | Euclidean distance of ratios |
| score | integer | 0..100 |
| submitted_at | datetime |
| created_at / updated_at | timestamp |
| | unique(daily_challenge_id, user_id) |

The `unique(daily_challenge_id, user_id)` constraint enforces one guess per user per daily challenge at the database level (in addition to the application-level check in `DailyChallengeController::guess()`).

---

## Score Formula

```
dx = guess_x_ratio - ball_x_ratio
dy = guess_y_ratio - ball_y_ratio
distance = sqrt(dx² + dy²)
score = max(0, round(100 - distance * 250))
```

This formula is used identically for tournament round guesses (`guesses` table) and daily challenge guesses (`daily_challenge_guesses` table) via `ScoreService`.

---

## Daily Limit Enforcement

The tournament daily round limit (`leagues.rounds_per_day`) is enforced in `LeagueController::currentRound()`. It counts `guesses` rows where:

- `league_rounds.league_id = :leagueId`
- `DATE(guesses.submitted_at) = today (UTC)`
- `guesses.user_id = :authUserId`

If `count >= rounds_per_day`, the endpoint returns `reason: "daily_limit_reached"` without exposing any round data. This check is per-user, not global — different members of the same league have independent daily counters.

---

## Streak Logic

Daily challenge streaks are computed on demand in `DailyStreakService::getStreakForUser()`:

**Current streak:**
1. Fetch all `challenge_date` values (via `daily_challenges` join `daily_challenge_guesses`) for this user, ordered DESC.
2. If today has a guess, count backward from today through consecutive days.
3. If yesterday has a guess (but not today), count backward from yesterday.
4. Otherwise, streak = 0.

**Best streak:**
1. Fetch all guess dates ordered ASC.
2. Walk the list and track the longest consecutive-day run.

Streaks are not stored — computed fresh each call. No caching in v1.

---

## badges (v1.6)
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK auto |
| code | varchar(255) unique | stable identifier, e.g. 'first_daily' |
| name | varchar(255) | display name |
| description | varchar(255) | short description |
| icon | varchar(255) | emoji, default '🏅' (no copyrighted assets) |
| category | varchar(255) | general\|daily\|tournament\|streak\|skill\|sport |
| rarity | varchar(255) | common\|rare\|epic\|legendary |
| sort_order | int | default 0 |
| created_at / updated_at | timestamp |

Seeded codes: first_guess, first_daily, seven_day_streak, thirty_day_streak,
perfect_guess, top_10_percent_daily, tournament_winner, daily_champion,
weekly_top_10, football_rookie, football_expert. **Virtual only — no real value.**

## user_badges (v1.6)
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK auto |
| user_id | bigint FK users cascade |
| badge_id | bigint FK badges cascade |
| earned_at | timestamp nullable |
| context | json nullable | e.g. {"daily_challenge_id":1,"score":100} |
| created_at / updated_at | timestamp |
| — | unique(user_id, badge_id) | idempotent — a badge is earned once |

## tags (v1.6)
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK auto |
| name | varchar(255) | text label |
| slug | varchar(255) unique | slugified name |
| type | varchar(255) nullable | team\|country\|league\|moment_type\|difficulty_label\|custom |
| created_at / updated_at | timestamp |

Text tags only — never store copyrighted logos or imagery.

## challenge_tag (v1.6)
| Column | Type | Notes |
|--------|------|-------|
| challenge_id | bigint FK challenges cascade |
| tag_id | bigint FK tags cascade |
| — | primary(challenge_id, tag_id) | pivot |

## password_reset_tokens
| Column | Type | Notes |
|--------|------|-------|
| email | varchar(255) PK |
| token | varchar(255) | hashed reset token |
| created_at | timestamp nullable |

Laravel's standard broker table (present since the initial users migration).
Used by `POST /api/forgot-password` and `POST /api/reset-password` in v1.6.

---

## Tournament Limits (v1.6, config-driven)

Enforced in `LeagueService`, values from `config/ballspot.php` → `tournaments`:

- `max_created_per_user` (default 3) — only **lobby/active** tournaments count;
  archived/completed/cancelled do not.
- `max_players_per_tournament` (default 8) — checked on join (idempotent for
  existing members).
- `premium_max_*` placeholders exist but are **not enforced** — no billing system.

## Badge Awarding (v1.6)

`BadgeService` evaluates and awards badges after each guess/result (idempotent via
the `user_badges` unique index). Rank-based badges (top-10%, daily champion) use a
snapshot of standings at submission time — an accepted MVP simplification.

---

## User Preferences, Themes & Avatars (v1.7)

Three columns were added to `users` (see the table above): `preferred_sport_id`,
`selected_theme`, and `avatar_path`. No new tables were introduced.

- **Themes** are an **extensible allow-list** in `config/ballspot.php` → `'themes'`:
  `classic`, `tournament_blue`, `pitch_green`, `sunset_orange`, `high_contrast`.
  `PATCH /api/me/preferences` validates `selected_theme` against this list.
- **Avatars** — rules in `config/ballspot.php` → `'avatar'`: `disk=public`,
  `directory=avatars`, `max_kb=2048`, mimes `jpeg/jpg/png/webp`. Files are stored under
  `avatars/` with a randomized name. Replacing/deleting an avatar only removes the previous
  file if it lives under `avatars/` — challenge images are never deleted.
- **Preferred sport** — `preferred_sport_id` must reference an **active** sport to be set
  via the API (`nullOnDelete`; null clears it). It feeds sport resolution for the daily
  challenge and tournament creation.

### Daily challenge per-sport limitation

`daily_challenges.challenge_date` remains **unique** — there is still only ONE global daily
challenge per date. `GET /api/daily/today` is sport-aware (it filters by the resolved
sport), but true simultaneous per-sport dailies on the same date would require a schema
change: a composite unique on (`challenge_date`, `sport_id`). This is a documented future
enhancement.

---

## xp_events (v1.7.3) — XP ledger (new source of truth)

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK auto |
| user_id | bigint FK → users | **cascade delete** |
| source_type | varchar(255) | `daily_guess` \| `tournament_guess` \| `badge_unlock` \| `streak_bonus` \| `tournament_win` \| `weekly_finish` \| `admin_adjustment` |
| source_id | bigint nullable | id of the originating row (guess/badge/milestone); nullable for source-less awards (e.g. admin_adjustment) |
| amount | integer (signed) | XP delta; MVP awards positive values only |
| reason | varchar(255) | human-readable label, e.g. "Daily challenge completed" |
| metadata | json nullable | optional context, e.g. `{ "badge_code": "perfect_guess" }` |
| created_at / updated_at | timestamp |
| | index(user_id, created_at) | recent-events / history queries |
| | unique(user_id, source_type, source_id) | de-dupe; **NULL `source_id` rows are exempt**, so unlimited `admin_adjustment` rows are allowed |

- **Append-only.** Rows are inserted, never mutated or deleted in normal operation.
- **Anonymization-safe.** XP rows are **never deleted on account anonymization** (`DELETE
  /account`), so rank/leaderboard history is preserved even after a user is anonymized.
- **De-duplication.** `XpService.awardXp(user, source_type, source_id, amount, reason, metadata)`
  relies on the `unique(user_id, source_type, source_id)` index, so replays (e.g. reopening a
  result) never double-count. NULL `source_id` is exempt from the constraint on purpose.
- Written by `XpService` for: guess submission (`daily_guess` / `tournament_guess`, `+score`),
  `badge_unlock` (rarity bonus), and `streak_bonus` (milestone). `tournament_win` is config-ready
  but **not awarded yet**. `weekly_finish` is reserved.

## User Rank / Level / XP (v1.7.3) — ledger-backed, with lifetime-score fallback

**Personal** long-term progression (rank/level/XP), computed by `PlayerRankService`. This is
distinct from **leaderboard rank** (a user's position relative to others), which is unchanged
and computed separately.

- Rank thresholds live in `config('ballspot.ranks')` (6 ranks by minimum XP): Rookie (L1, 0),
  Amateur (L2, 2,500), Pro (L3, 10,000), Elite (L4, 25,000), Legend (L5, 50,000),
  Ball Master (L6, 100,000).
- **`total_xp` now derives from the `xp_events` ledger** (sum of `amount`), not from a live score
  sum. This is the source of truth as of v1.7.3.
- **Fallback (documented):** if a user has **no** ledger events yet, `total_xp` **falls back** to
  the lifetime score total = `SUM(guesses.score)` + `SUM(daily_challenge_guesses.score)` so early
  players don't show 0 XP before backfill. Once **any** ledger event exists, the ledger is
  authoritative and the fallback no longer applies.
- **XP bonus config:** badge XP (`config('ballspot.xp.badge')` — common 100 / rare 250 / epic 500
  / legendary 1000) and streak XP (`config('ballspot.xp.streak')` — 3-day +150 / 7-day +500 /
  30-day +2500) are awarded to the ledger, so XP can now exceed the raw score sum. Tournament-win
  XP config exists but is not awarded yet.
- **Backfill:** run `php artisan ballspot:backfill-xp` **once after deploy** to create the missing
  `daily_guess` + `tournament_guess` events for existing guesses. Idempotent; `--dry-run` writes
  nothing; `--user=ID` scopes to one user; `--force` is intentionally a NO-OP (never
  deletes/rebuilds history). It never touches guesses/challenges/images.

Exposed via `GET /api/me/rank`, the `rank` object on `GET /api/profile/stats`, the `rank_progress`
+ nullable `rank_up` blocks on fresh daily/round guess responses, and `GET /api/me/xp-events`.

---

## Notifications (v1.7.7)

### `notification_settings`
One row per user (unique `user_id`), created lazily on first read.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| user_id | FK → users | unique; cascade on delete |
| daily_reminder_enabled | boolean | default true |
| tournament_reminder_enabled | boolean | default true |
| admin_notifications_enabled | boolean | default true |
| reminder_time | string(5) | `HH:mm`, default `19:00` (config `ballspot.notifications.default_reminder_time`) |
| timezone | string, nullable | optional IANA tz (only personal data stored) |
| timestamps | | |

### `push_tokens`
Expo push tokens for admin announcements. Never exposed in API responses (`$hidden`).

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| user_id | FK → users | cascade on delete; indexed |
| token | string | **unique** (device belongs to one account; re-register reassigns) |
| platform | string, nullable | ios/android/web |
| device_name | string, nullable | |
| last_seen_at | timestamp, nullable | refreshed on register |
| timestamps | | |

### `admin_notifications`
Admin-composed announcements. Plain text (Blade escapes on output — no HTML injection).

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| title | string | ≤120 |
| body | text | ≤500 |
| target_type | string | `all` \| `opted_in` (both exclude explicitly opted-out users) |
| status | string | `draft` \| `sent` \| `failed` — reflects the real send outcome |
| send_at | timestamp, nullable | future scheduling is not auto-dispatched yet |
| sent_at | timestamp, nullable | |
| created_by_user_id | FK → users, nullable | null on creator delete |
| metadata | json, nullable | send summary: recipients / sent / failed |
| timestamps | | |

**Privacy:** tokens + settings are personal data; they cascade-delete with the user
(account deletion removes them). Users can opt out of any category at any time.

---

## Content organisation (v1.7.8)

### `challenge_subcategories` (+ `challenge_subcategory` pivot)
Curated, admin-managed taxonomy for organising/filtering content. **Distinct from the
free-text `tags` table**: tags are ad-hoc labels created inline on the challenge form;
subcategories are a styled, activatable, sport-scoped taxonomy managed at `/admin/subcategories`.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| sport_id | FK → sports, nullable | null = global (all sports); nullOnDelete |
| name / slug | string | slug unique within `(sport_id, type)` |
| type | string | team\|country\|league\|club\|difficulty\|moment_type\|player_type\|custom |
| description | text, nullable | |
| color / icon | string, nullable | hex color + emoji for admin display |
| is_active | boolean | default true; inactive hides from app filters, keeps history |
| sort_order | integer | default 0 |

Pivot `challenge_subcategory` (challenge_id, challenge_subcategory_id, timestamps) — many-to-many.
Deleting a subcategory only **detaches** challenges (pivot cascade) — never deletes them or images.

### `challenge_packs` (+ `challenge_pack_challenge` pivot)
Content-only collections (e.g. "Belgium Pack"). **No price/purchase/payment columns.**

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| sport_id | FK → sports, nullable | null = global |
| name | string | |
| slug | string | **unique** |
| description | text, nullable | |
| cover_image_path | string, nullable | on the public disk |
| status | string | draft \| active \| archived (default draft) |
| visibility | string | public \| hidden (default public) |
| difficulty | string, nullable | easy/medium/hard/mixed |
| sort_order | integer | default 0 |
| is_featured | boolean | default false |

Pivot `challenge_pack_challenge` (challenge_pack_id, challenge_id, `sort_order`, timestamps).
Only **active + public** packs are exposed to normal users; detail lists only ready challenges.
Detaching never deletes challenges or images.

### Pack play (v1.7.9)

`pack_attempts` — a user's play-through of a pack. Virtual progress only.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| user_id | FK → users | cascade on delete |
| challenge_pack_id | FK → challenge_packs | cascade on delete |
| status | string | active \| completed \| abandoned (default active) |
| started_at / completed_at | timestamp, nullable | |
| total_score | integer | running sum of guess scores |
| current_index | unsigned int | index into the snapshotted challenge list |
| metadata | json, nullable | `{ challenge_ids: [...] }` snapshot at start (stable vs later pack edits) |

`pack_attempt_guesses` — one row per answered challenge in an attempt.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| pack_attempt_id | FK → pack_attempts | cascade on delete; **unique with challenge_id** |
| challenge_id | FK → challenges | cascade on delete |
| score | integer | from ScoreService |
| guessed_x / guessed_y / distance | decimal, nullable | |
| result | json, nullable | `{ score, distance }` |

**XP:** per-guess `pack_guess` (= score) + `pack_completion` (+250, config
`ballspot.xp.pack_completion`) in the existing `xp_events` ledger, deduped by source id.
**Badges:** `first_pack_completed` (📦 common), `perfect_pack` (💎 legendary), `pack_master`
(🧠 epic, 10 packs) — catalogue is now **23** badges. Completed attempts stay historical and
feed the Trophy Room. No paid packs, no purchases.

## Query indexes added in v1.8.1

SQLite does not auto-index foreign key columns, so migration
`2026_07_30_000001_add_missing_query_indexes` adds:

| Table | Index | Serves |
|---|---|---|
| guesses | (user_id) | profile stats aggregates |
| daily_challenge_guesses | (user_id) | daily stats, streaks |
| league_rounds | (league_id, status) | leaderboards, current-round |
| league_members | (user_id) | "my leagues" listing |

Other hot paths were already covered by existing unique/composite indexes
(xp_events, pack_attempts, push_tokens, notification_settings,
competition_finishes, tournament_finishes — see the per-table sections).
