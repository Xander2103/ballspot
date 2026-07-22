# BallSpot API Contract

Base URL: `http://127.0.0.1:8000/api`

For physical device testing replace `127.0.0.1` with your computer's LAN IP (e.g. `192.168.1.x`).

All requests: `Content-Type: application/json`, `Accept: application/json`

Protected routes require: `Authorization: Bearer <token>`

**Email verification gate (v1.6.2):** most protected app endpoints (profile/stats,
sports, preferences, avatar, badges, leagues, rounds, daily) are behind the
`verified` middleware and return **HTTP 403 `{ "message": "Your email address is not
verified." }`** for an authenticated-but-unverified user. Endpoints reachable while
unverified: `GET /me`, `POST /logout`, `DELETE /account`, `POST /email/verify`, and
`POST /email/verification-notification`.

---

## Auth

### POST /register  *(email verification — v1.6.2)*

Creates an **unverified** account, emails a 6-digit verification code, and returns a
token. The token lets the app complete verification and read `/me`, but protected
app endpoints are gated (403) until the email is verified via `POST /email/verify`.
When `require_email_verification` is `false`, the account is auto-verified.

```json
// Request
{ "name": "Xander", "username": "xander", "email": "x@example.com", "password": "password123" }
// Response 201
{ "user": { "id": 1, "name": "Xander", "username": "xander", "email": "x@example.com" },
  "token": "1|...", "email_verified": false }
```

### POST /email/verify  *(auth required; NOT gated by verified — v1.6.2)*

Submits the 6-digit registration verification code. On success the email is marked
verified and protected endpoints unlock.

```json
// Request
{ "code": "123456" }

// Response 200 — success
{ "email_verified": true, "user": { ... }, "message": "..." }

// Response 422 — wrong / expired / consumed code (generic)
{ "message": "Invalid or expired verification code." }

// Response 401 — no bearer token
{ "message": "Unauthenticated." }
```

- A wrong code **increments the attempt counter**; after **5** wrong attempts the
  code locks. Codes are stored **hashed only** and expire after **60 minutes**.

### POST /email/verification-notification  *(auth required — v1.6.2)*

Resends a verification code, cooldown-limited to 60s.

```json
// Response 200 — code (re)sent
{ "email_verified": false, "message": "..." }

// Response 200 — already verified
{ "email_verified": true, "message": "Your email is already verified." }

// Response 401 — no bearer token
{ "message": "Unauthenticated." }
```

Both email endpoints require authentication but are **not** behind the `verified`
middleware, so an authenticated-but-unverified user can reach them. With
`MAIL_MAILER=log` the code appears in `backend/storage/logs/laravel.log`.

### POST /login  *(email+password, configurable 2FA — v1.6.2)*

Normal login is **email + password** once the email is verified. On valid
credentials there are **three** outcomes (all HTTP **200**). See
[docs/security-auth.md](security-auth.md) for the full design.

```json
// Request
{ "email": "x@example.com", "password": "password123" }

// Response 200 (a) — email NOT verified: verification code (re)sent, token returned
{
  "requires_email_verification": true,
  "email_verified": false,
  "user": { ... },
  "token": "1|...",
  "message": "..."
}

// Response 200 (b) — verified + forced 2FA (force_login_2fa=true OR admin): login code emailed, NO token
{
  "requires_2fa": true,
  "verification_id": "<uuid>",
  "message": "We sent a verification code to your email."
}

// Response 200 (c) — verified + no forced 2FA (DEFAULT): token returned directly
{ "user": { ... }, "token": "1|..." }

// Response 422 — invalid credentials OR unknown email (generic, no email sent, no enumeration)
{ "message": "Invalid credentials." }
```

- **(a)** The returned token lets the app drive the verify screen; complete via
  `POST /email/verify`. Protected endpoints stay 403 until verified.
- **(b)** Forced-2FA / admin path only; complete via `POST /login/verify`.
- **(c)** Default path — email+password is enough.
- The unknown-email path runs a dummy hash to equalize response timing
  (anti-enumeration).

### POST /login/verify  *(forced-2FA / admin path — v1.6.1)*

Exchanges a valid 6-digit code for a Sanctum token. The token is issued **only**
here, on success.

```json
// Request
{ "verification_id": "<uuid>", "code": "123456" }

// Response 200 — success: token issued (same shape login used to return)
{ "user": { "id": 1, "name": "Xander", "username": "xander" }, "token": "1|..." }

// Response 422 — generic failure (wrong / expired / consumed / locked are indistinguishable)
{ "message": "Invalid or expired verification code." }
```

- A wrong code **increments the attempt counter**.
- Codes expire after **10 minutes** and lock after **5** wrong attempts (a locked
  code rejects even the correct value — the user must log in again).
- A successful verify consumes the code and deletes any other pending codes for the
  user; consumed codes cannot be reused.

### POST /login/resend-code  *(v1.6.1)*

Re-issues a code on the **same** verification session (resets code/attempts/expiry).
Cooldown-limited to 60s.

```json
// Request
{ "verification_id": "<uuid>" }

// Response 200 — generic success
{ "message": "If your login is still pending, a new code has been sent to your email." }

// Response 422 — expired / consumed / unknown session
{ "message": "Please login again." }

// Response 422 — within the 60s cooldown window
{ "message": "Please wait a moment before requesting another code." }
```

**Rate limits (v1.6.1):** `throttle:login` on `/login` (5/min per email+IP, plus
20/min per IP); `throttle:login-verify` on `/login/verify` (20/min per
`verification_id`+IP); `throttle:login-resend` on `/login/resend-code` (5/min per
`verification_id`+IP).

**Local dev:** with `MAIL_MAILER=log`, the code appears in
`backend/storage/logs/laravel.log`. No API response ever returns the raw code.

### POST /logout  *(auth required)*
```json
// Response 200
{ "message": "Logged out" }
```

### GET /me  *(auth required; available even when unverified)*
```json
// Response 200
{ "data": { "id": 1, "name": "Xander", "username": "xander", "email": "x@example.com",
  // v1.6.2 — returned for the authenticated user themselves:
  "email_verified": true,
  // v1.7 — returned for the authenticated user themselves:
  "selected_theme": "classic",
  "avatar_url": "http://.../storage/avatars/ab12cd34.jpg",
  "preferred_sport": { "id": 1, "slug": "football", "name": "Football", "emoji": "⚽", "primary_color": "#00c853" }
} }
// avatar_url is null when no avatar; preferred_sport is null when no preference.
```

### DELETE /account  *(auth required)*

Anonymizes and deactivates the current user's account. All tokens are revoked immediately. The user row is preserved (not hard-deleted) to maintain leaderboard integrity. Name, email, and username are overwritten with placeholder values.

```json
// Response 200
{ "message": "Your account has been deleted." }

// Response 401 — not authenticated
{ "message": "Unauthenticated." }
```

After this call the bearer token is invalid. The mobile app clears the stored token and navigates to Login.

---

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
{ "name": "Friday Squad", "duration_days": 3, "rounds_per_day": 1, "sport_id": 1 }
// duration_days: 1|3|7, rounds_per_day: 1|3
// sport_id (v1.7): optional, must be an ACTIVE sport. Precedence:
//   explicit sport_id → user's preferred sport → football.
// Response 200 — LeagueResource now includes a sport object (v1.7)
{ "data": { "id": 1, "name": "Friday Squad", "join_code": "ABC123", "status": "lobby", "is_owner": true, "rounds_count": 0,
  "sport": { "id": 1, "slug": "football", "name": "Football", "emoji": "⚽", "primary_color": "#00c853" }, ... } }
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
// Response 422 — no active challenges available (sport name is dynamic, v1.7)
{ "message": "No active football challenges available. Add challenges in admin." }
// Tournament rounds only draw challenges from the tournament's own sport.
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

**Sport-aware (v1.7):** the sport is resolved from `?sport=<slug>`, else the authenticated
user's preferred sport, else football. If today's (single global) daily challenge's sport
does not match the requested sport, a clean no-daily payload is returned so the app can say
e.g. "No daily challenge for Tennis today. Try Football." Football-first behaviour is
unchanged for users with no preference.

> **Limitation:** there is still only ONE global daily challenge per date
> (`daily_challenges.challenge_date` is unique). True simultaneous per-sport dailies on the
> same date is a future enhancement requiring a schema change (composite unique on
> `challenge_date` + `sport`).

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

// Response 200 — a daily exists but not for the requested sport (v1.7)
{
  "has_daily": false,
  "reason": "no_daily_challenge",
  "sport": { "slug": "tennis", "name": "Tennis", "emoji": "🎾", "primary_color": "#c6ff00" }
}
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
| GET | /admin/sports | **v1.7** — List all sports (active/inactive) |
| POST | /admin/sports/{sport}/toggle | **v1.7** — Activate/deactivate a sport. Football cannot be deactivated (protected). |

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

---

## v1.6 additions

### POST /forgot-password (public)
```json
// Request
{ "email": "x@example.com" }
// Response 200 — ALWAYS generic (no email enumeration)
{ "message": "If an account exists for that email, a password reset link has been sent." }
```
With `MAIL_MAILER=log`, the reset link (token + email) is written to `storage/logs/laravel.log`.

### POST /reset-password (public)
```json
// Request
{ "email": "x@example.com", "token": "<from email>", "password": "newpass123", "password_confirmation": "newpass123" }
// Response 200
{ "message": "Your password has been reset. Please log in." }
// Response 422 (invalid/expired token, or validation failure)
{ "message": "This password reset link is invalid or has expired." }
```
Password rules match registration (`min:8`, `confirmed`). On success all existing Sanctum tokens are revoked.

### Rank / percentile on results
Daily guess/result (`data`) and tournament round result now include:
```json
{ "rank": 3, "total_players": 128, "better_than_percentage": 82 }
```
`better_than_percentage` = "closer than X% of players" (from score). Tournament round rank is within that round's guesses.

### Leaderboard meta
`GET /daily/leaderboard/weekly` and `GET /leagues/{id}/leaderboard` now return a `meta` block:
```json
{
  "meta": {
    "total_players": 128,
    "current_user_rank": 3,
    "current_user_score": 940,
    "current_user_average": 78.3,
    "better_than_percentage": 97,
    "top_users": [ /* top 3 entries */ ],
    "nearby_users": [ /* current user + neighbours */ ]
  }
}
```
(Weekly keeps `data`, `week_start`, `week_end`; tournament keeps `data`.)

### GET /badges (auth)
```json
{ "data": [ { "id": 1, "code": "first_daily", "name": "Daily Debut", "description": "...", "icon": "📅", "category": "daily", "rarity": "common", "sort_order": 2 }, ... ] }
```

### GET /me/badges (auth)
```json
{
  "earned_count": 3,
  "total_count": 11,
  "badges": [ { "code": "first_daily", "name": "Daily Debut", "icon": "📅", "rarity": "common", "category": "daily", "earned": true, "earned_at": "2026-07-21T..." }, ... ]
}
```

### new_badges on guesses
Daily guess response (`data.new_badges`) and tournament guess response (top-level `new_badges`) return any freshly-earned badges (empty array if none) for a "New badge unlocked!" UI. Awarding is idempotent.

### Daily challenge sport + tags
`GET /daily/today` challenge object now includes:
```json
{ "sport": { "slug": "football", "name": "Football", "emoji": "⚽", "primary_color": "#00c853" },
  "tags": [ { "name": "Premier League", "slug": "premier-league", "type": "league" } ] }
```

### Tournament limit errors (422)
- Create over limit: `{ "message": "You have reached the free tournament limit. Finish or cancel an existing tournament to create a new one." }`
- Join when full: `{ "message": "This tournament is full." }`

No payment/purchase endpoints exist. Badges and trophies are virtual only.

---

## v1.7 additions — Sports, Preferences, Themes & Avatar

All endpoints below require `auth:sanctum`.

### GET /api/sports  *(auth required)*

Returns active sports by default. Pass `?include_inactive=1` to also return inactive
("Coming soon") sports.

```json
// GET /api/sports
// Response 200
{ "data": [
  { "id": 1, "name": "Football", "slug": "football", "emoji": "⚽", "object_name": "ball", "primary_color": "#00c853", "is_active": true }
] }

// GET /api/sports?include_inactive=1 — also includes inactive sports
{ "data": [
  { "id": 1, "name": "Football", "slug": "football", "emoji": "⚽", "object_name": "ball", "primary_color": "#00c853", "is_active": true },
  { "id": 3, "name": "Tennis", "slug": "tennis", "emoji": "🎾", "object_name": "ball", "primary_color": "#c6ff00", "is_active": false }
] }
```

### GET /api/me/preferences  *(auth required)*

```json
// Response 200
{
  "preferred_sport": { "id": 1, "slug": "football", "name": "Football", "emoji": "⚽", "primary_color": "#00c853" },
  "selected_theme": "classic",
  "avatar_url": "http://.../storage/avatars/ab12cd34.jpg",
  "available_themes": ["classic", "tournament_blue", "pitch_green", "sunset_orange", "high_contrast"]
}
// preferred_sport is null when the user has no preference; avatar_url is null when no avatar.
```

### PATCH /api/me/preferences  *(auth required)*

Partial updates supported — send either or both fields.

```json
// Request (any subset)
{ "preferred_sport_id": 1, "selected_theme": "tournament_blue" }
// preferred_sport_id: must exist AND be an ACTIVE sport; null clears the preference.
// selected_theme: must be in the config allow-list
//   (classic | tournament_blue | pitch_green | sunset_orange | high_contrast).

// Response 200 — same shape as GET /api/me/preferences
{ "preferred_sport": { ... }, "selected_theme": "tournament_blue", "avatar_url": null,
  "available_themes": [ ... ] }

// Response 422 — validation error (unknown theme, or inactive/nonexistent sport)
{ "message": "The selected theme is invalid.", "errors": { "selected_theme": ["..."] } }
```

### POST /api/me/avatar  *(auth required)*

Multipart form upload. Field name: `avatar`.

- Accepts `jpeg` / `jpg` / `png` / `webp`; **max 2048 KB (2 MB)**. SVG is rejected (the
  `image` rule + mimes allow-list).
- Stored on the `public` disk under `avatars/` with a randomized filename.
- Replaces (and best-effort deletes) the previous avatar **only if** it lives under
  `avatars/` — challenge images and other files are never deleted.

```
POST /api/me/avatar
Content-Type: multipart/form-data
avatar: <binary jpeg/png/webp, ≤2 MB>
```
```json
// Response 200
{ "avatar_url": "http://.../storage/avatars/ab12cd34.jpg" }

// Response 422 — invalid file (wrong type / too large / SVG)
{ "message": "The avatar must be an image.", "errors": { "avatar": ["..."] } }
```

### DELETE /api/me/avatar  *(auth required)*

Clears `avatar_path` and deletes the file (only if it lives under `avatars/`).

```json
// Response 200
{ "avatar_url": null }
```

**Config:** themes and avatar rules live in `config/ballspot.php` (`'themes'` allow-list;
`'avatar'` → disk=public, directory=avatars, max_kb=2048, mimes jpeg/jpg/png/webp).

> **Not yet included:** avatar URLs are not present in leaderboard or tournament-lobby
> payloads yet — those responses do not include `avatar_url`.
