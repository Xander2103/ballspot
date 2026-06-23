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
| created_at / updated_at | timestamp |

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
| rounds_per_day | integer | 1 or 3 |
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

## Score Formula

```
dx = guess_x_ratio - ball_x_ratio
dy = guess_y_ratio - ball_y_ratio
distance = sqrt(dx² + dy²)
score = max(0, round(100 - distance * 250))
```
