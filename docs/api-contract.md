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
{ "id": 1, "name": "Xander", "username": "xander", "email": "x@example.com" }
```

---

## Leagues

### GET /leagues  *(auth required)*
Returns leagues the current user is a member of.
```json
// Response 200
[{ "id": 1, "name": "Friday Squad", "join_code": "ABC123", "duration_days": 3, "rounds_per_day": 1, "status": "active", "total_rounds": 3, "members_count": 2 }]
```

### POST /leagues  *(auth required)*
```json
// Request
{ "name": "Friday Squad", "duration_days": 3, "rounds_per_day": 1 }
// duration_days: 1|3|7, rounds_per_day: 1|3
// Response 201
{ "id": 1, "name": "Friday Squad", "join_code": "ABC123", ... }
```

### POST /leagues/join  *(auth required)*
```json
// Request
{ "join_code": "ABC123" }
// Response 200
{ "id": 1, "name": "Friday Squad", "join_code": "ABC123", ... }
```

### GET /leagues/{id}  *(auth required, must be member)*
```json
// Response 200
{ "id": 1, "name": "Friday Squad", "join_code": "ABC123", "members": [...], ... }
```

### GET /leagues/{id}/current-round  *(auth required, must be member)*
```json
// Response 200 — round available
{
  "current_round": { "id": 5, "round_number": 2, "status": "open", "challenge": { "id": 3, "title": "Corner Kick", "difficulty": "easy", "hidden_image_url": "http://..." } },
  "has_current_round": true,
  "completed": false,
  "reason": "has_pending_round",
  "progress": { "completed": 1, "total": 3, "remaining": 2, "pct": 33 }
}

// Response 200 — all rounds done
{
  "current_round": null,
  "has_current_round": false,
  "completed": true,
  "reason": "all_rounds_complete",
  "progress": { "completed": 3, "total": 3, "remaining": 0, "pct": 100 }
}
```

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
// Response 200
{ "id": 8, "score": 87, "distance": 0.052, "guess_x_ratio": 0.43, "guess_y_ratio": 0.72, "ball_x_ratio": 0.45, "ball_y_ratio": 0.71 }
// Response 422 — duplicate or closed round
```

### GET /rounds/{id}/result  *(auth required)*
```json
// Response 200
{ "id": 8, "score": 87, "distance": 0.052, "guess_x_ratio": 0.43, "guess_y_ratio": 0.72, "ball_x_ratio": 0.45, "ball_y_ratio": 0.71 }
// Response 404 — no guess found
```

---

## Health

### GET /health
```json
// Response 200
{ "status": "ok", "timestamp": "2026-06-21T..." }
```

---

## Admin (Blade, session auth)

Login at `/admin/login` with `admin@ballspot.local / password`. All admin routes require session auth.

| Method | URL | Description |
|--------|-----|-------------|
| GET | /admin/login | Login form |
| POST | /admin/login | Submit credentials |
| POST | /admin/logout | Logout |
| GET | /admin/challenges | List all challenges |
| GET | /admin/challenges/create | Create form (with click-to-set ball position) |
| POST | /admin/challenges | Store new challenge |
| GET | /admin/challenges/{id}/edit | Edit form (with click-to-set ball position) |
| PATCH | /admin/challenges/{id} | Update challenge |
| DELETE | /admin/challenges/{id} | Delete challenge |
