# BallSpot API Contract

Base URL: `http://127.0.0.1:8000/api`

For physical device testing replace `127.0.0.1` with your computer's LAN IP (e.g. `192.168.1.x`).

All requests: `Content-Type: application/json`, `Accept: application/json`

Protected routes require: `Authorization: Bearer <token>`

**Email verification gate (v1.6.2):** most protected app endpoints (profile/stats,
preferences, avatar, badges, leagues, rounds, daily) are behind the
`verified` middleware and return **HTTP 403 `{ "message": "Your email address is not
verified." }`** for an authenticated-but-unverified user. Endpoints reachable while
unverified (auth only, no `verified` gate): `GET /me`, `POST /logout`, `DELETE /account`,
`POST /email/verify`, `POST /email/verification-notification`, `GET /sports`, and
`GET /ranks` (the last two are onboarding reference data, intentionally reachable before
verification).

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
As of **v1.7.2** the response also includes a `rank` object — the user's **personal**
long-term rank/level/XP progression (see [User Rank / XP](#user-rank--xp-progression-v172)).
This is distinct from **leaderboard rank** (position vs. other players), which is unchanged.
```json
// Response 200
{
  "tournaments_count": 3,
  "completed_tournaments_count": 1,
  "guesses_count": 9,
  "total_score": 720,
  "average_score": 80.0,
  "current_daily_streak": 3,
  "best_daily_streak": 7,
  "daily_challenges_played": 12,
  "average_daily_score": 74.5,
  "best_daily_score": 98,
  // v1.7.2 — personal rank/XP progression:
  "rank": {
    "name": "Amateur",
    "level": 2,
    "total_xp": 3120,
    "current_rank_min_xp": 2500,
    "next_rank_name": "Pro",
    "next_rank_xp": 10000,
    "xp_to_next_rank": 6880,
    "progress_to_next_rank_pct": 8,
    "is_max_rank": false
  }
}
```

### GET /api/me/rank  *(auth required)*  *(new v1.7.2)*

Returns the current user's personal rank object standalone (same shape as the `rank` field on
`/profile/stats`).

```json
// Response 200
{ "rank": {
  "name": "Amateur", "level": 2, "total_xp": 3120,
  "current_rank_min_xp": 2500,
  "next_rank_name": "Pro", "next_rank_xp": 10000, "xp_to_next_rank": 6880,
  "progress_to_next_rank_pct": 8, "is_max_rank": false
} }

// At max rank (Ball Master), the "next_*" fields are null and is_max_rank is true:
{ "rank": {
  "name": "Ball Master", "level": 6, "total_xp": 120000,
  "current_rank_min_xp": 100000,
  "next_rank_name": null, "next_rank_xp": null, "xp_to_next_rank": null,
  "progress_to_next_rank_pct": 100, "is_max_rank": true
} }
```

#### User Rank / XP progression (v1.7.2)

`PlayerRankService` computes **personal** progression — long-term, distinct from leaderboard
rank (which is position relative to other players).

- **`total_xp`** = lifetime score total = `sum(guesses.score)` (tournaments) +
  `sum(daily_challenge_guesses.score)` (daily). **XP currently equals the lifetime score
  total.** Badges do **not** add XP.
- Ranks come from `config('ballspot.ranks')` — 6 ranks by minimum XP:

  | Level | Rank | Min XP |
  |-------|------|--------|
  | 1 | Rookie | 0 |
  | 2 | Amateur | 2,500 |
  | 3 | Pro | 10,000 |
  | 4 | Elite | 25,000 |
  | 5 | Legend | 50,000 |
  | 6 | Ball Master | 100,000 |

- Fields returned: `name`, `level`, `total_xp`, `current_rank_min_xp`, `next_rank_name`
  (nullable), `next_rank_xp` (nullable), `xp_to_next_rank` (nullable),
  `progress_to_next_rank_pct`, `is_max_rank`.

> **Updated in v1.7.3:** `total_xp` now derives from the **XP ledger** (`xp_events`), not from
> the score sums. See [XP Ledger](#user-xp-ledger-v173) below. The old "no ledger table"
> limitation no longer applies.

### GET /api/ranks  *(auth required; NOT gated by verified)*  *(new v1.7.4)*

Returns the full rank ladder from `config('ballspot.ranks')` so the mobile "All ranks" overview
shares a single source of truth with backend progression (no duplicated thresholds in the app).
Ranks are ordered by `min_xp` ascending; the last is the max rank. Static config only (no user
data), so it is available during onboarding (before email verification).

```json
// Response 200
{
  "data": [
    { "name": "Rookie",      "level": 1, "min_xp": 0 },
    { "name": "Amateur",     "level": 2, "min_xp": 2500 },
    { "name": "Pro",         "level": 3, "min_xp": 10000 },
    { "name": "Elite",       "level": 4, "min_xp": 25000 },
    { "name": "Legend",      "level": 5, "min_xp": 50000 },
    { "name": "Ball Master", "level": 6, "min_xp": 100000 }
  ]
}
```

The mobile RankOverviewScreen combines this with `/profile/stats` (for `total_xp`/`level`) to mark
each rank **completed** (below current), **current rank**, or **future** (showing "N XP needed"),
and tags the highest as **Max rank**.

### GET /api/me/xp-events  *(auth + verified required)*  *(new v1.7.3)*

Returns the current user's recent XP ledger events, most-recent first, plus their `total_xp`
(pure ledger sum) and `rank` object.

```json
// GET /api/me/xp-events?limit=5
// limit: default 20, max 50 (clamped server-side; excessive values are capped)
// The Profile screen requests limit=5 and renders at most 5 rows (v1.7.4).
// Response 200
{
  "data": [
    { "id": 42, "amount": 511, "reason": "Daily challenge completed",
      "source_type": "daily_guess", "metadata": null, "created_at": "2026-07-22T09:00:00Z" },
    { "id": 41, "amount": 250, "reason": "Badge unlocked: Perfect Guess",
      "source_type": "badge_unlock", "metadata": { "badge_code": "perfect_guess" },
      "created_at": "2026-07-22T09:00:00Z" }
  ],
  "total_xp": 3120,
  "rank": {
    "name": "Amateur", "level": 2, "total_xp": 3120,
    "current_rank_min_xp": 2500,
    "next_rank_name": "Pro", "next_rank_xp": 10000, "xp_to_next_rank": 6880,
    "progress_to_next_rank_pct": 8, "is_max_rank": false
  }
}
```

#### User XP ledger (v1.7.3)

`XpService` records every XP award as an append-only row in `xp_events`, de-duplicated on
`(user, source_type, source_id)` so replays never double-count. `PlayerRankService` derives
`total_xp` as the ledger sum.

- **`source_type`** values: `daily_guess`, `tournament_guess`, `badge_unlock`, `streak_bonus`,
  `tournament_win`, `weekly_finish`, `admin_adjustment`.
- **XP sources awarded today:** guess submission (`+score`), badge unlock (rarity bonus:
  common 100 / rare 250 / epic 500 / legendary 1000), and daily-streak milestones (3-day +150,
  7-day +500, 30-day +2500). **Tournament-win XP is config-ready but NOT awarded yet.**
- **Fallback:** a user with **no** ledger events yet still shows XP from lifetime guess scores
  (tournament + daily) so early players never see 0 XP before the backfill runs. Once any event
  exists, the ledger is authoritative. Run `php artisan ballspot:backfill-xp` **once after
  deploy** to create the missing guess events (idempotent; `--dry-run` writes nothing;
  `--force` is a NO-OP).
- Ledger rows are **append-only** and are **never deleted** on account anonymization, so
  rank/leaderboard history is preserved.

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
// Response 201 (a resource wrapping the newly-created guess)
{
  "data": {
    "id": 8,
    "score": 87,
    "distance": 0.052,
    "guess_x_ratio": 0.43,
    "guess_y_ratio": 0.72,
    "ball_x_ratio": 0.45,
    "ball_y_ratio": 0.71,
    "reveal_image_url": "http://.../storage/challenges/original/corner-kick.jpg",
    // rank / percentile within this round (from GuessResultResource):
    "rank": 1, "total_players": 4, "better_than_percentage": 75
  },
  // freshly-earned badges for this guess (empty array if none). Never returned on result reopen:
  "new_badges": [],
  // v1.7.2 — top-level personal rank progress for THIS guess (alongside data);
  // v1.7.3 — rank now derives from the XP ledger and xp_gained includes ALL XP earned
  // in this submission (guess score + any badge/streak bonus), not just the guess score:
  "rank_progress": {
    "xp_gained": 87,
    "rank": {
      "name": "Amateur", "level": 2, "total_xp": 3120,
      "current_rank_min_xp": 2500,
      "next_rank_name": "Pro", "next_rank_xp": 10000, "xp_to_next_rank": 6880,
      "progress_to_next_rank_pct": 8, "is_max_rank": false
    }
  },
  // v1.7.3 — nullable rank-up moment; present only when THIS submission crossed a rank threshold:
  "rank_up": { "from_rank": "Rookie", "to_rank": "Amateur", "new_level": 2 },
  // else: "rank_up": null

  // v1.7.7 — present ONLY on the guess that FINISHES the tournament (every member has
  // played every round). Awarded once; never returned again on result reopen. Winner/top-3
  // XP is already included in rank_progress.xp_gained, and completion badges (tournament_winner,
  // podium_finish) are merged into new_badges above:
  "tournament_completion": { "is_completed": true, "placement": 1, "total_players": 8, "xp_awarded": 1000 }
  // omitted entirely when the tournament is not yet complete
}
// rank_up is null when the submission did not cross a rank threshold. The GET /rounds/{id}/result
// endpoint is UNCHANGED — it does NOT include rank_progress, rank_up or tournament_completion.
// reveal_image_url is null when no reveal image exists for this challenge.
// Response 422 — duplicate guess
{ "message": "Already submitted a guess for this round" }
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
  },
  // v1.7.2 — top-level personal rank progress for THIS guess (alongside data);
  // v1.7.3 — rank now derives from the XP ledger and xp_gained includes ALL XP earned
  // in this submission (guess score + any badge/streak bonus), not just the guess score:
  "rank_progress": {
    "xp_gained": 87,
    "rank": {
      "name": "Amateur", "level": 2, "total_xp": 3120,
      "current_rank_min_xp": 2500,
      "next_rank_name": "Pro", "next_rank_xp": 10000, "xp_to_next_rank": 6880,
      "progress_to_next_rank_pct": 8, "is_max_rank": false
    }
  },
  // v1.7.3 — nullable rank-up moment; present only when THIS submission crossed a rank threshold:
  "rank_up": { "from_rank": "Rookie", "to_rank": "Amateur", "new_level": 2 }
  // else: "rank_up": null
}
// rank_up is null when the submission did not cross a rank threshold. The GET /api/daily/{id}/result
// endpoint is UNCHANGED — it does NOT include rank_progress or rank_up.

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
  "week_end": "2026-06-28",
  // v1.7.6 — UI-only period label from config('ballspot.leaderboard.period_label'),
  // default "Weekly". The aggregation window is still weekly; the app renders this
  // label instead of hardcoding "Weekly" (prep for a possible future "Monthly").
  "period_label": "Weekly",
  "meta": { /* current_user_rank, total_players, better_than_percentage, top_users, nearby_users … (see meta block below) */ }
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
| GET | /admin/sports | **v1.7** — List all sports with a status dropdown (active/coming_soon/hidden) |
| POST | /admin/sports/{sport}/status | **v1.7.2** — Set a sport's status (active/coming_soon/hidden) via dropdown; replaces the old `/toggle`. Football is protected — it cannot be moved off active ("Football must stay active."). Invalid status rejected. |

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
  "total_count": 19,
  "badges": [ { "code": "first_daily_win", "name": "Daily Debut", "icon": "🌅", "rarity": "common", "category": "daily", "earned": true, "earned_at": "2026-07-21T..." }, ... ]
}
```

### Badge catalogue (v1.7.4)

The catalogue holds **20** virtual badges (`total_count`). v1.7.4 added a canonical taxonomy on
top of the original set (legacy codes are retained so already-earned badges stay valid);
v1.7.7 added `podium_finish`:

| Code | Name | Icon | Category | Rarity | Auto-awarded? |
|------|------|------|----------|--------|---------------|
| `perfect_picker` | Perfect Picker | 🎯 | skill | legendary | ✅ on a perfect 100 guess (daily + tournament) |
| `almost_perfect` | Almost Perfect | 🔥 | skill | epic | ✅ on a score ≥ 95 (daily + tournament) |
| `first_daily_win` | Daily Debut | 🌅 | daily | common | ✅ on first daily completion |
| `streak_3` | On a Roll | ⚡ | streak | common | ✅ at a 3-day streak |
| `streak_7` | Week Warrior | 🗓️ | streak | rare | ✅ at a 7-day streak |
| `streak_30` | Monthly Machine | 🚀 | streak | legendary | ✅ at a 30-day streak (streak data permitting) |
| `top_10_daily` | Top 10% | 🥇 | leaderboard | rare | ✅ finishing top 10% of a daily (field ≥ 10, snapshot at submit) |
| `multi_sport_starter` | Multi-Sport Starter | 🌍 | sport | rare | ✅ on first non-football challenge |
| `tournament_winner` | Tournament Winner | 🏆 | tournament | epic | ✅ (v1.7.7) to placement 1 when a tournament completes |
| `podium_finish` | Podium Finish | 🥉 | tournament | rare | ✅ (v1.7.7) to placements 1–3 when a tournament completes |

Perfect / almost-perfect thresholds live in **one place** — `config('ballspot.scoring')`
(`max_score` = 100, `almost_perfect_threshold` = 95) via `ScoreService::isPerfectScore()` /
`isAlmostPerfect()`. Badge XP is written to the **XP ledger** (`xp_events`, source `badge_unlock`)
by rarity, exactly once per badge per user.

> **Legacy overlap:** some legacy codes (`perfect_guess`, `first_daily`, `seven_day_streak`,
> `thirty_day_streak`, `top_10_percent_daily`) map to the same moments as the new canonical
> codes and are still awarded for backward compatibility. A future sprint may consolidate them.

### new_badges on guesses
Daily guess response (`data.new_badges`) and tournament guess response (top-level `new_badges`) return any freshly-earned badges (empty array if none) for a "New badge unlocked!" UI. Awarding is idempotent — **reopening a result endpoint never re-awards or returns `new_badges`**, and any badge XP earned in the same submission is included in that response's `rank_progress`.

### Tournament completion & finishes (v1.7.7)

**Completion rule.** A tournament is complete when it is `active` and **every member has submitted a
guess for every round** (each member plays each round once). The check runs after each round-guess
submission; the guess that finishes it completes the tournament **exactly once** (atomic
`active → completed` transition) and awards winner/top-3 recognition. A member who never plays keeps
the tournament open — the owner can still cancel it (a time-based sweep is a documented future item).

**Standings / ties.** Sort by total score DESC, then earliest completion (last-guess time) ASC — the
player who reached their score first wins the tie — then user id ASC as a final stable tiebreak.
Deterministic.

**Rewards (virtual only).** Placement 1 → `tournament_winner` + `podium_finish` badges; placements
1–3 → `podium_finish`. Placement XP via the ledger (`source_type: tournament_win`, `source_id:
league id`, deduped once per user per league): **1st +1000, 2nd +500, 3rd +250**
(`config('ballspot.xp.tournament_win')`). Reasons: "Tournament winner" / "Tournament runner-up" /
"Tournament top 3 finish".

**`GET /api/me/tournament-finishes`** *(auth + verified required)* — the current user's placements
for the Trophy Room:
```json
{
  "data": [
    { "id": 3, "placement": 1, "total_score": 640, "rounds_played": 8, "total_players": 8,
      "league": { "id": 12, "name": "Friday Football" }, "completed_at": "2026-07-23T18:00:00Z" }
  ]
}
```
Final standings are also persisted in the `tournament_finishes` table (one row per member; unique
`(league_id, user_id)`). No prizes, money, or payments — all recognition is virtual.

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

### GET /api/sports  *(auth required)*  *(updated v1.7.2)*

Returns **visible** sports only — those with `status` = `active` or `coming_soon`. Sports with
`status = hidden` are excluded and never shown to normal users. **The old
`?include_inactive=1` parameter has been removed.**

Each sport carries the new `status` field plus convenience booleans. No admin-sensitive fields
are exposed.

| Field | Type | Meaning |
|-------|------|---------|
| `status` | string | `active` \| `coming_soon` (`hidden` never returned here) |
| `is_playable` | bool | `true` only when `status = active` |
| `is_coming_soon` | bool | `true` when `status = coming_soon` |
| `is_active` | bool | back-compat mirror of `status === 'active'` |
| `tagline` | string | **v1.7.3** — per-sport onboarding tagline from `config('ballspot.sport_taglines')`; falls back to "Guess the {object_name}" |

```json
// GET /api/sports
// Response 200 — active + coming_soon (hidden excluded); tagline added in v1.7.3
{ "data": [
  { "id": 1, "name": "Football", "slug": "football", "emoji": "⚽", "object_name": "ball",
    "primary_color": "#00c853", "tagline": "Guess the ball",
    "status": "active", "is_playable": true, "is_coming_soon": false, "is_active": true },
  { "id": 3, "name": "Tennis", "slug": "tennis", "emoji": "🎾", "object_name": "ball",
    "primary_color": "#c6ff00", "tagline": "Find the tennis ball",
    "status": "coming_soon", "is_playable": false, "is_coming_soon": true, "is_active": false }
] }
```

**Taglines (v1.7.3):** `config('ballspot.sport_taglines')` — football "Guess the ball", tennis
"Find the tennis ball", golf "Spot the golf ball", hockey "Find the puck", cricket "Spot the
cricket ball", american_football "Find the ball", basketball "Spot the ball". Any sport without a
configured tagline falls back to "Guess the {object_name}". The mobile Choose Sport screen shows
each sport's tagline.

**Second-sport launch prep (v1.7.3, admin/CLI — not public API):**
- `SportReadinessService` and the admin Sports page report per-sport content readiness (ready
  challenge count = active + hidden image + ball position, scheduled daily count) with a "Ready to
  activate" / "Not enough content yet" badge for non-active sports. Thresholds live in
  `config('ballspot.sport_readiness')` (min_ready_challenges=5, min_scheduled_dailies=1).
  **Advisory only** — activation is not hard-blocked.
- `php artisan ballspot:schedule-daily-challenges --sport=<slug>` now **warns and does nothing**
  when the target sport is `coming_soon`/`hidden`, unless `--allow-coming-soon` is passed (for
  admin content prep ahead of activation). Default (no `--sport`) behaviour is unchanged; nothing
  is deleted or overwritten.

**Sport status meanings:**
- **`active`** — visible + selectable/playable.
- **`coming_soon`** — visible in the app but disabled ("Coming soon"); cannot be selected as a
  preference or used to create a tournament.
- **`hidden`** — not shown to normal users (excluded from `GET /api/sports`).

`status` is the source of truth; `is_active` is a mirrored boolean (`is_active == (status ===
'active')`) kept for backward compatibility. Seeded (v1.7.2): Football = `active`; Golf, Tennis,
Hockey, Cricket, American Football, Basketball = `coming_soon`.

**Non-active sports are rejected (422).** Setting `preferred_sport_id` (`PATCH
/api/me/preferences`) or creating a tournament (`POST /leagues`) for a `coming_soon` or `hidden`
sport returns **422 "This sport is not available yet."** Only `active` sports are playable, so
daily challenges and tournaments effectively use active sports only.

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
// preferred_sport_id: must exist AND have status = ACTIVE (coming_soon/hidden are rejected);
//   null clears the preference.
// selected_theme: must be in the config allow-list
//   (classic | tournament_blue | pitch_green | sunset_orange | high_contrast).

// Response 200 — same shape as GET /api/me/preferences
{ "preferred_sport": { ... }, "selected_theme": "tournament_blue", "avatar_url": null,
  "available_themes": [ ... ] }

// Response 422 — unknown theme
{ "message": "The selected theme is invalid.", "errors": { "selected_theme": ["..."] } }

// Response 422 — non-active sport (v1.7.2)
{ "message": "This sport is not available yet.",
  "errors": { "preferred_sport_id": ["This sport is not available yet."] } }
```

### POST /api/me/avatar  *(auth required)*

Multipart form upload. Field name is **exactly** `avatar`.

- Accepts **`image/jpeg` / `image/png` / `image/webp`** (rules: `required|file|image|mimes:
  jpeg,jpg,png,webp|max:2048`); **max 2048 KB (2 MB)**. SVG is rejected.
- Stored on the `public` disk under `avatars/` with a randomized filename.
- Replaces (and best-effort deletes) the previous avatar **only if** it lives under
  `avatars/` — challenge images and other files are never deleted.
- Behaviour is unchanged in v1.7.2; only the friendly validation message was unified (below).

> **Cross-platform note (v1.7.2 client fix):** on Expo **web** the client must send a real
> `Blob` file part — appending a React Native `{ uri, name, type }` descriptor to `FormData`
> on web stringifies to `"[object Object]"`, which the server rejects as
> "The avatar field must be a file." The mobile client (`src/api/avatarApi.ts`) now fetches
> the picked `blob:`/`data:` URI into a `Blob` on web and uses the RN descriptor on native.
> The `Content-Type`/boundary is left to the runtime; the auth header is preserved.

```
POST /api/me/avatar
Content-Type: multipart/form-data
avatar: <binary jpeg/png/webp, ≤2 MB>
```
```json
// Response 200
{ "avatar_url": "http://.../storage/avatars/ab12cd34.jpg" }

// Response 422 — invalid file (wrong type / too large / SVG). Friendly message (v1.7.2):
{ "message": "Please choose a JPG, PNG or WebP image under 2MB.",
  "errors": { "avatar": ["Please choose a JPG, PNG or WebP image under 2MB."] } }
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

---

## Notifications (v1.7.7)

Opt-in reminders and admin announcements. Settings sync across devices; on-device
scheduling and permissions are handled by the mobile app (Expo Notifications).

### GET /api/me/notification-settings  *(auth + verified)*

Returns the caller's settings, **lazily creating a defaults row** on first read.

```json
// Response 200
{
  "daily_reminder_enabled": true,
  "tournament_reminder_enabled": true,
  "admin_notifications_enabled": true,
  "reminder_time": "19:00",
  "timezone": null
}
```

### PUT /api/me/notification-settings  *(auth + verified)*

Partial update — only the keys sent are changed. A user can only edit their own row.

```jsonc
// Request (all fields optional)
{
  "daily_reminder_enabled": false,
  "tournament_reminder_enabled": true,
  "admin_notifications_enabled": false,
  "reminder_time": "08:30",     // 24-hour HH:mm, validated (date_format:H:i)
  "timezone": "Europe/Brussels" // nullable string, max 64
}
// Response 200 → same shape as GET
// Response 422 → invalid reminder_time / types
```

### POST /api/me/push-tokens  *(auth + verified)*

Registers (or reassigns) an Expo push token for admin announcements. Tokens are
globally unique; re-registering an existing token moves it to the current user.
Raw tokens are never returned in any response.

```jsonc
// Request
{ "token": "ExponentPushToken[xxx]", "platform": "ios", "device_name": "iPhone" }
// platform ∈ ios|android|web (nullable); device_name nullable
// Response 201
{ "status": "registered" }
```

---

## Challenge Packs (v1.7.8)

Content-only discovery collections (e.g. "Belgium Pack"). **No prices, no purchases, no
payments.** Only `active` + `public` packs are visible to normal users.

### GET /api/packs  *(auth + verified)*

Optional query: `sport_id` or `sport_slug`. Ordered featured-first, then sort_order.

```jsonc
// Response 200
{ "data": [
  {
    "id": 1, "name": "Belgium Pack", "slug": "belgium-pack",
    "description": "…", "sport": { "slug": "football", "name": "Football", "emoji": "⚽", "primary_color": "#..." },
    "cover_image_url": null, "difficulty": "mixed", "challenge_count": 8, "is_featured": true
  }
] }
```

### GET /api/packs/{slug}  *(auth + verified)*

Pack detail + **ready challenges only** (draft/archived challenges are hidden). Draft/hidden/
archived packs return **404**. No admin-only fields (`status`, `visibility`) and challenge
summaries never include the ball position.

```jsonc
// Response 200
{ "data": {
  "id": 1, "name": "Belgium Pack", "slug": "belgium-pack", "description": "…",
  "sport": { … }, "cover_image_url": null, "difficulty": "mixed",
  "challenge_count": 1, "is_featured": true,
  "challenges": [
    { "id": 5, "title": "…", "difficulty": "easy",
      "hidden_image_url": "…", "sport": { "slug", "name", "emoji" }, "category": { "name", "slug" } }
  ]
} }
```

## Pack play mode (v1.7.9)

Packs are playable: a sequential run of the pack's ready challenges, scored with the
same ScoreService, awarding XP and pack badges. **Still content-only — no purchases.**
All endpoints are auth + verified.

### POST /api/packs/{slug}/start
Starts a new attempt or **resumes** the user's active one (one active attempt per user per
pack). 404 for draft/hidden/archived packs; **422** if the pack has no ready challenges.

```jsonc
{
  "attempt": { "id": 1, "status": "active", "current_index": 0, "total_score": 0,
               "completed_count": 0, "total_challenges": 5 },
  "challenge": { "id": 123, "title": "…", "difficulty": "easy",
                 "hidden_image_url": "…", "sport": {…}, "category": {…} }   // no ball position
}
```

### GET /api/packs/{slug}/attempt
Returns the active attempt (with the current challenge) or the latest completed one
(`challenge: null`). `{ "attempt": null, "challenge": null }` if never played.

### POST /api/pack-attempts/{attempt}/guess
Body: `challenge_id`, `guessed_x`, `guessed_y` (0..1). Validates the attempt belongs to the
caller (**403** otherwise) and that `challenge_id` is the current expected challenge
(**422** otherwise). Scores, stores the guess, awards per-guess XP, advances progress, and
on the final challenge completes the attempt (+completion XP + pack badges).

```jsonc
{
  "result": { "score": 100, "distance": 0.0, "guessed_x": 0.5, "guessed_y": 0.5,
              "ball_x_ratio": 0.5, "ball_y_ratio": 0.5, "reveal_image_url": null },
  "progress": { "id": 1, "status": "active|completed", "completed_count": 1, "total_challenges": 5, … },
  "next_challenge": { … } | null,
  "rank_progress": { "xp_gained": 100, "rank": {…} },
  "rank_up": null,
  "new_badges": [ { "code": "first_pack_completed", … } ],
  "pack_completed": false,
  "final_score": null,        // set on completion
  "completion_xp": null       // set on completion (config ballspot.xp.pack_completion, default 250)
}
```

**XP sources:** per guess `pack_guess` (source_id = pack_attempt_guess id, amount = score);
on completion `pack_completion` (source_id = attempt id, +250). Deduped via the ledger.

### GET /api/me/pack-completions
Completed packs for the Trophy Room: `id`, `pack {id,name,slug}`, `total_score`,
`challenge_count`, `is_perfect`, `completed_at`.

`GET /api/packs` now also returns a per-pack `progress` block
(`{ status, completed_count, total_challenges }`) or `null` if the user never played it.

## Competition period on the leaderboard (v1.7.8)

`GET /api/daily/leaderboard/weekly` (route name unchanged) and `GET /api/daily/stats` now
follow `config('ballspot.competition.period')` — **monthly by default** (or weekly). The
window drives both the leaderboard totals and the top-finishers badge.

```jsonc
// leaderboard response — added `period`; week_start/week_end kept for BC (= period boundaries)
{
  "data": [ … ],
  "week_start": "2026-07-01", "week_end": "2026-07-31",
  "period_label": "Monthly",
  "period": { "period_type": "monthly", "period_label": "Monthly",
              "period_start": "2026-07-01", "period_end": "2026-07-31" },
  "meta": { … }
}
```

Subcategories are curated admin taxonomy (managed at `/admin/subcategories`); they are not
exposed on the mobile API in this sprint (organisation/filtering is admin-side for now).

### Admin announcements (web admin, not a mobile API)

`/admin/notifications` (admin-only Blade page) composes announcements
(title ≤120, body ≤500, plain text). Delivery is via Expo's push HTTP API to
opted-in tokens; a user who disabled admin announcements is **never** delivered
to, regardless of the announcement's audience. Status reflects the real outcome
(`draft` / `sent` / `failed`) — a send is never faked when push is disabled.
