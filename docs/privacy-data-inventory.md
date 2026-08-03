# BallPicker — Personal Data Inventory

**Sprint:** v1.8.1 — Security, Privacy & Test Readiness
**Status:** living document — update whenever a new field/table stores personal data.

Scope: everything BallPicker stores that relates to an identifiable person.
Storage is the Laravel backend database (SQLite in dev; managed SQL in production)
plus `storage/app/public` for uploaded images, unless stated otherwise.

Legal-basis suggestions are developer guesses for a GDPR framing — **have them
reviewed by a lawyer before public launch.**

| Data | Where | Purpose | Suggested legal basis | Retention | Who can access |
|---|---|---|---|---|---|
| Email address | `users.email` | Account identity, login, verification/reset/2FA emails | Contract (providing the service) | Until account deletion (then replaced with `deleted-{id}@ballspot.deleted`) | User (own), admins (DB), mail provider in transit |
| Display name + username | `users.name`, `users.username` | Identity in-app and on leaderboards | Contract | Until deletion (anonymized to "Deleted User" / `deleted-{id}`) | All players (leaderboards show username/name) |
| Password hash | `users.password` | Authentication. Bcrypt hash — never plaintext, never exported | Contract | Until deletion (replaced with a random hash) | Nobody (hash only) |
| Avatar image | `storage/app/public/avatars/*`, `users.avatar_path` | Optional profile photo | Consent (optional upload) | Deleted immediately on account deletion or avatar removal | Public URL while account exists |
| Email-verification codes | `email_verification_codes` (bcrypt `code_hash` only) | Verify email at registration | Contract | Purged opportunistically + `ballspot:cleanup-login-codes`; deleted on account deletion | Nobody (hash only) |
| Login 2FA codes + IP + user agent | `login_verification_codes` | Secure admin/forced-2FA login; IP/UA recorded for abuse detection | Legitimate interest (security) | Same cleanup as above; deleted on account deletion | Admins (DB) |
| Password reset tokens | `password_reset_tokens` (hashed by framework) | Password reset | Contract | 60 min framework expiry | Nobody (hash only) |
| API tokens | `personal_access_tokens` (SHA-256) | Mobile session auth | Contract | Revoked on logout/deletion; optional TTL via `SANCTUM_TOKEN_EXPIRATION_MINUTES` | Nobody (hash only) |
| Guesses & scores (daily/tournament/pack) | `daily_challenge_guesses`, `guesses`, `pack_attempt_guesses` | Core gameplay, leaderboards, stats | Contract | Retained after deletion, linked to the anonymized user row (leaderboard/history integrity) | Aggregates visible to other players via leaderboards |
| XP events | `xp_events` | Progression ledger (append-only) | Contract | Retained after deletion (anonymized linkage) | User (own) |
| Badges / trophies / finishes | `user_badges`, `tournament_finishes`, `competition_finishes`, `pack_attempts` | Achievements, Trophy Room | Contract | Retained after deletion (anonymized linkage) | User (own); placements visible in tournaments |
| Notification settings + timezone | `notification_settings` | Reminder scheduling, announcement opt-out. Timezone is a weak location signal | Contract / consent for announcements | Deleted on account deletion | User (own), admins (DB) |
| Expo push tokens | `push_tokens` (plaintext by necessity — Expo needs the raw value; `$hidden`, never returned by any API) | Deliver admin announcements | Consent (OS-level permission + in-app opt-in) | Deleted on logout (that device), account deletion (all), or after 90 days unseen (`ballspot:cleanup-login-codes`) | Nobody via API; Expo push service in transit |
| Friend code | `users.friend_code` | Share code so another player can send a friend request. In `$hidden` and never fillable — only ever returned to its own owner via `GET /api/me/friend-code` | Contract | Until account deletion | User (own) only; other players never see it |
| Friend requests & friendships | `friend_requests`, `friendships` | Who a player is connected to, and pending requests either way | Contract | Deleted with the account (cascade on `users`) | The two parties involved |
| Admin flag | `users.is_admin` | Authorization. In `$hidden` — never serialized | — | — | Server-side only |
| Sessions (admin web) | `sessions` table | Admin CMS login | Contract | `SESSION_LIFETIME` (120 min) | Server-side only |
| Support/contact data | none collected in-app; `BALLSPOT_SUPPORT_EMAIL` receives email the user sends voluntarily | Support | Legitimate interest | Mailbox policy | Operator |
| Admin audit data | **none currently** — see docs/security-hardening.md recommendation | — | — | — | — |

## Visible to other players (v1.8.2)

`GET /api/users/{id}/public-profile` is readable by **any authenticated, verified
player who has the user's id**. It exposes, by explicit allow-list: username,
display name, avatar URL, rank/level/XP, aggregate gameplay stats (tournaments
played and completed, guess count, total and average score, daily challenges
played, best daily score) and badge counts — plus the viewer's own relationship
to that player (`is_friend`, `has_pending_request`).

It never exposes email, password hash, `is_admin`, `email_verified_at`, the
target's `friend_code`, or any per-guess detail. `PublicProfileTest` asserts this.

## What BallPicker deliberately does NOT collect

- No tracking or analytics SDKs, no advertising identifiers (IDFA/GAID).
- No payment data (no payments exist).
- No precise location, contacts, or device fingerprinting.
- No chat/messages between users.
- `device_name` for push tokens is supported by the API but the app never sends it.

## Export & deletion

- `GET /api/me/export` returns the user's data as JSON (no secrets: no password
  hash, no tokens, no raw push-token values).
- `DELETE /api/account` revokes all API tokens, deletes the avatar file, push
  tokens, notification settings and pending verification codes, and anonymizes
  the user row. Gameplay history is retained linked to the anonymized row —
  this policy is disclosed in the privacy policy.
