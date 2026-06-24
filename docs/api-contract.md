# BallSpot API Contract

Base URL: `http://127.0.0.1:8000/api`

For physical device testing replace `127.0.0.1` with your computer's LAN IP (e.g. `192.168.1.x`).

All requests: `Content-Type: application/json`, `Accept: application/json`

Protected routes require: `Authorization: Bearer <token>`

---

## Auth

### POST /register
```json
// Request
{ "name": "Xander", "username": "xander", "email": "x@example.com", "password": "password123" }
// Response 201
{ "user": { "id": 1, "name": "Xander", "username": "xander", "email": "x@example.com" }, "token": "1|..." }
```

### POST /login
```json
// Request
{ "email": "x@example.com", "password": "password123" }
// Response 200
{ "user": { "id": 1, "name": "Xander", "username": "xander" }, "token": "1|..." }
```

### POST /logout  *(auth required)*
```json
// Response 200
{ "message": "Logged out" }
```

### GET /me  *(auth required)*
```json
// Response 200
{ "data": { "id": 1, "name": "Xander", "username": "xander", "email": "x@example.com" } }
```

### GET /profile/stats  *(auth required)*
Returns aggregate stats for the current user (tournaments + daily challenge stats).
```json
// Response 200
{
  "tournaments_count": 3,
  "completed_tournaments_count": 1,
  "guesses_count": 9,
  "total_score": 720,
  "average_score": 80.0,
  "current_streak": 3,
  "best_streak": 7,
  "total_daily_challenges_played": 12,
  "average_daily_score": 74.5,
  "best_daily_score": 98
}
```

---

## Leagues (Tournaments)

### GET /leagues  *(auth required)*
Returns leagues the current user is a member of (excludes cancelled).
```json
// Response 200
{ "data": [{ "id": 1, "name": "Friday Squad", "join_code": "ABC123", "duration_days": 3, "rounds_per_day": 1, "status": "lobby", "owner_user_id": 1, "is_owner": true, "members_count": 2, "rounds_count": 0, "completed_rounds_count": 0, "remaining_rounds_count": 0, "progress_pct": 0, "starts_at": null, "ends_at": null }] }
```

### POST /leagues  *(auth required)*
Creates a new tournament in **lobby** status. No rounds are generated until `/start` is called.
```json
// Request
{ "name": "Friday Squad", "duration_days": 3, "rounds_per_day": 1 }
// duration_days: 1|3|7, rounds_per_day: 1|3
// Response 200
{ "data": { "id": 1, "name": "Friday Squad", "join_code": "ABC123", "status": "lobby", "is_owner": true, "rounds_count": 0, ... } }
```

### POST /leagues/join  *(auth required)*
Join a tournament by code. Fails if the tournament is not in `lobby` status.
```json
// Request
{ "join_code": "ABC123" }
// Response 200
{ "data": { "id": 1, "name": "Friday Squad", ... } }
// Response 422 — tournament already started
{ "message": "This tournament is no longer accepting players." }
```

### GET /leagues/{id}  *(auth required, must be member)*
```json
// Response 200
{ "data": { "id": 1, "name": "Friday Squad", "join_code": "ABC123", "status": "active", "is_owner": false, "rounds_count": 3, "completed_rounds_count": 1, "remaining_rounds_count": 2, "progress_pct": 33, "members": [{ "id": 1, "name": "Xander", "username": "xander", "is_owner": true, "joined_at": "2026-06-24T10:00:00Z" }] } }
```

### DELETE /leagues/{id}/members/{userId}  *(auth required, must be owner)*

Removes a player from a lobby tournament. Only works while status = lobby.

```json
// Response 204 — success (no content)
// Response 403 — not owner
{ "message": "Only the owner can remove players." }
// Response 422 — not lobby
{ "message": "Players can only be removed while the tournament is in lobby." }
// Response 422 — self-remove
{ "message": "The owner cannot be removed." }
```

### POST /leagues/{id}/start  *(auth required, must be owner)*
Transitions a lobby tournament to active. Generates all rounds from available challenges.
```json
// Response 200
{ "data": { "id": 1, "status": "active", "rounds_count": 3, "starts_at": "2026-06-23T...", "ends_at": "2026-06-24T...", ... } }
// Response 403 — not the owner
{ "message": "Only the owner can start this tournament." }
// Response 422 — no active challenges available
{ "message": "No active football challenges available. Add challenges in admin." }
```

### DELETE /leagues/{id}  *(auth required, must be owner)*
Soft-cancels a tournament. Sets `status = cancelled`. No data is deleted.
```json
// Response 204 — no content
// Response 403 — not the owner
{ "message": "Only the owner can cancel this tournament." }
```

### GET /leagues/{id}/current-round  *(auth required, must be member)*

**Security note:** The `challenge` object in this response intentionally omits `ball_x_ratio`,
`ball_y_ratio`, and `reveal_image_url` so players cannot cheat before guessing.

**Daily limit:** Each user may play at most `rounds_per_day` rounds per UTC calendar day per tournament.
When the daily limit is reached, this endpoint returns `reason: "daily_limit_reached"`.

```json
// Response 200 — round available
{
  "current_round": {
    "id": 5,
    "round_number": 2,
    "status": "open",
    "challenge": {
      "id": 3,
      "title": "Corner Kick",
      "difficulty": "easy",
      "hidden_image_url": "http://...",
      "category": { "id": 1, "name": "Corner Kicks", "slug": "corner-kicks" }
    }
  },
  "has_current_round": true,
  "completed": false,
  "reason": "has_pending_round",
  "progress": { "completed": 1, "total": 3, "remaining": 2, "pct": 33 },
  "rounds_per_day": 1,
  "played_today_count": 0,
  "remaining_today_count": 1,
  "next_available_at": null
}

// Response 200 — daily limit reached
{
  "current_round": null,
  "has_current_round": false,
  "completed": false,
  "reason": "daily_limit_reached",
  "message": "You have played all rounds available for today.",
  "next_available_at": null,
  "progress": { "completed": 1, "total": 3, "remaining": 2, "pct": 33 },
  "rounds_per_day": 1,
  "played_today_count": 1,
  "remaining_today_count": 0
}

// Response 200 — all rounds done
{
  "current_round": null,
  "has_current_round": false,
  "completed": true,
  "reason": "all_rounds_complete",
  "progress": { "completed": 3, "total": 3, "remaining": 0, "pct": 100 },
  "rounds_per_day": 1,
  "played_today_count": 1,
  "remaining_today_count": 0,
  "next_available_at": null
}
```

**Reason codes:**

| Reason | Meaning |
|--------|---------|
| `has_pending_round` | A round is available to play now |
| `daily_limit_reached` | User has played all allowed rounds today; try again tomorrow |
| `all_rounds_complete` | User has completed all rounds in the tournament |
| `no_rounds_yet` | Tournament started but no rounds have been generated |

### GET /leagues/{id}/leaderboard  *(auth required, must be member)*
```json
// Response 200
{ "data": [{ "rank": 1, "user_id": 1, "username": "xander", "name": "Xander", "total_score": 250, "guesses_count": 3, "avg_score": 83.3, "is_current_user": true }] }
```

---

## Rounds

### POST /rounds/{id}/guess  *(auth required, must be league member)*
```json
// Request
{ "guess_x_ratio": 0.43, "guess_y_ratio": 0.72 }
// Both must be between 0 and 1
// Response 201
{
  "data": {
    "id": 8,
    "score": 87,
    "distance": 0.052,
    "guess_x_ratio": 0.43,
    "guess_y_ratio": 0.72,
    "ball_x_ratio": 0.45,
    "ball_y_ratio": 0.71,
    "reveal_image_url": "http://.../storage/challenges/original/corner-kick.jpg"
  }
}
// reveal_image_url is null when no reveal image exists for this challenge.
// Response 422 — duplicate guess
{ "message": "You have already guessed this round." }
```

### GET /rounds/{id}/result  *(auth required)*

Returns the user's guess result. Exposes ball position and reveal image after the user has guessed.

```json
// Response 200
{
  "data": {
    "id": 8,
    "score": 87,
    "distance": 0.052,
    "guess_x_ratio": 0.43,
    "guess_y_ratio": 0.72,
    "ball_x_ratio": 0.45,
    "ball_y_ratio": 0.71,
    "reveal_image_url": "http://.../storage/challenges/original/corner-kick.jpg"
  }
}
// reveal_image_url is null when no reveal image exists.
// Response 404 — no guess found for this round
```

---

## Daily Challenge

The daily challenge is a single shared challenge published each day. All users play the same image. Score and streak are tracked per user.

### GET /api/daily/today  *(auth required)*

Returns today's daily challenge. Ball position is **never** exposed before the user has guessed.
After guessing, `already_played: true` is returned — call `/result` for score details.

```json
// Response 200 — challenge available, not yet played
{
  "has_daily": true,
  "already_played": false,
  "daily_challenge": {
    "id": 1,
    "challenge_date": "2026-06-24",
    "challenge": {
      "id": 3,
      "title": "Corner Kick",
      "difficulty": "easy",
      "hidden_image_url": "http://...",
      "category": { "id": 1, "name": "Corner Kicks", "slug": "corner-kicks" }
    }
  }
}

// Response 200 — already played today
{
  "has_daily": true,
  "already_played": true,
  "daily_challenge": { "id": 1, "challenge_date": "2026-06-24", "challenge": { ... } }
}

// Response 200 — no challenge today
{ "has_daily": false, "reason": "no_daily_challenge" }
```

### POST /api/daily/{dailyChallenge}/guess  *(auth required)*

Submit a guess for today's daily challenge. One guess per user per daily challenge.

```json
// Request
{ "guess_x_ratio": 0.43, "guess_y_ratio": 0.72 }

// Response 200 — success, includes ball position and reveal image
{
  "data": {
    "id": 1,
    "score": 87,
    "distance": 0.052,
    "guess_x_ratio": 0.43,
    "guess_y_ratio": 0.72,
    "ball_x_ratio": 0.45,
    "ball_y_ratio": 0.71,
    "reveal_image_url": "http://..."
  }
}

// Response 422 — already played
{ "message": "You have already played today's challenge." }

// Response 422 — challenge not active
{ "message": "This daily challenge is not active." }
```

### GET /api/daily/{dailyChallenge}/result  *(auth required)*

Returns the user's result for this daily challenge. Only available after guessing.

```json
// Response 200
{
  "data": {
    "id": 1,
    "score": 87,
    "distance": 0.052,
    "guess_x_ratio": 0.43,
    "guess_y_ratio": 0.72,
    "ball_x_ratio": 0.45,
    "ball_y_ratio": 0.71,
    "reveal_image_url": "http://..."
  }
}
// Response 404 — user has not guessed yet
{ "message": "No guess found for this challenge." }
```

### GET /api/daily/leaderboard/weekly  *(auth required)*

Returns all users' total scores for daily challenges this week (Monday–Sunday, UTC).

```json
// Response 200
{
  "data": [
    {
      "rank": 1,
      "user_id": 1,
      "username": "xander",
      "name": "Xander",
      "total_score": 250,
      "challenges_played": 3,
      "avg_score": 83.3,
      "is_current_user": true
    }
  ],
  "week_start": "2026-06-22",
  "week_end": "2026-06-28"
}
```

### GET /api/daily/stats  *(auth required)*

Returns the current user's daily challenge statistics.

```json
// Response 200
{
  "current_streak": 3,
  "best_streak": 7,
  "total_played": 12,
  "average_score": 74.5,
  "best_score": 98,
  "weekly_rank": 2
}
```

**Streak logic:**
- `current_streak`: consecutive days ending today or yesterday where the user played the daily challenge
- `best_streak`: longest ever consecutive-day run
- `weekly_rank`: user's rank by total score this week (null if no score this week)

---

## Health

### GET /health
```json
// Response 200
{ "status": "ok", "timestamp": "2026-06-23T..." }
```

---

## Admin (Blade, session auth)

Login at `/admin/login` with `admin@ballspot.local / password`. All admin routes require session auth.

| Method | URL | Description |
|--------|-----|-------------|
| GET | /admin/login | Login form |
| POST | /admin/login | Submit credentials |
| POST | /admin/logout | Logout |
| GET | /admin/challenges | List all challenges (thumbnails, category/status/difficulty filters) |
| GET | /admin/challenges/create | Create form (category dropdown, click-to-set on images) |
| POST | /admin/challenges | Store new challenge |
| GET | /admin/challenges/{id}/edit | Edit form |
| PATCH | /admin/challenges/{id} | Update challenge |
| DELETE | /admin/challenges/{id} | Delete challenge |
| GET | /admin/categories | List all categories |
| GET | /admin/categories/create | Create category form |
| POST | /admin/categories | Store new category |
| GET | /admin/categories/{id}/edit | Edit category form |
| PATCH | /admin/categories/{id} | Update category |
| DELETE | /admin/categories/{id} | Delete category (uncategorises its challenges) |
| POST | /admin/categories/{id}/toggle | Toggle is_active |
| GET | /admin/daily | List all daily challenges |
| GET | /admin/daily/create | Create daily challenge form (select challenge + date) |
| POST | /admin/daily | Store new daily challenge |
| PATCH | /admin/daily/{id}/status | Update status (scheduled/active/archived) |

---

## Security Notes

### Ball position access control

| Endpoint | `ball_x_ratio` / `ball_y_ratio` | `reveal_image_url` |
|----------|----------------------------------|-------------------|
| `GET /leagues/{id}/current-round` | **Not exposed** | **Not exposed** |
| `GET /api/daily/today` | **Not exposed** | **Not exposed** |
| `POST /rounds/{id}/guess` | Exposed (post-submission) | Exposed (post-submission) |
| `GET /rounds/{id}/result` | Exposed (user already guessed) | Exposed (user already guessed) |
| `POST /api/daily/{id}/guess` | Exposed (post-submission) | Exposed (post-submission) |
| `GET /api/daily/{id}/result` | Exposed (user already guessed) | Exposed (user already guessed) |

### Coordinate system

Ball positions and guess positions are expressed as ratios (0–1):

- `x_ratio`: 0 = left edge, 1 = right edge
- `y_ratio`: 0 = top edge, 1 = bottom edge

`distance` in the result is the Euclidean distance between guess and ball as a ratio of the image diagonal (0–1 scale). Score = `max(0, round(100 - distance * 250))`.

### Daily round limit enforcement

The `GET /leagues/{id}/current-round` endpoint counts, for the authenticated user in that league:

```sql
SELECT COUNT(*) FROM guesses
JOIN league_rounds ON guesses.league_round_id = league_rounds.id
WHERE league_rounds.league_id = :id
  AND DATE(guesses.submitted_at) = CURDATE()   -- UTC
  AND guesses.user_id = :me
```

If `count >= rounds_per_day`, the endpoint returns `reason: "daily_limit_reached"` without revealing any round. This check is per-user — other users in the same league are unaffected.
