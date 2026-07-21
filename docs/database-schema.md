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
| created_at / updated_at | timestamp |

## sports
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK auto |
| name | varchar(255) |
| slug | varchar(255) unique | e.g. 'football' |
| emoji | varchar(255) | default '⚽' — display icon (no copyrighted assets) |
| object_name | varchar(255) | default 'ball' — e.g. 'ball' / 'puck' |
| primary_color | varchar(255) | default '#00c853' — sport accent color |
| is_active | boolean | default false — **only football is active in v1.6** |
| sort_order | int | default 0 |
| scoring_profile | varchar(255) | default 'default' — foundation for future per-sport scoring |
| created_at / updated_at | timestamp |

Seeded sports (v1.6): football (active), golf, tennis, hockey, cricket,
american_football, basketball (all inactive scaffolding).

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
| created_at / updated_at | timestamp |
| | unique(league_id, user_id) |

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
