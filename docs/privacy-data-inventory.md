# BallPicker — Personal Data Inventory

**Sprint:** v1.8.2 — Mobile polish (friends, camera/QR, tournament hiding)
**Last reviewed:** v1.8.7 pre-beta security audit (2026-08-11) — classifications below verified against the live schema and controllers.
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
| Consent record | `users.terms_accepted_at` (timestamp), `users.terms_version` (string) — migration `2026_08_05_000001_add_consent_to_users` | Evidence that the account holder accepted the Terms + Privacy Policy and confirmed the minimum age at registration. `POST /api/register` **requires** `terms_accepted` and `age_confirmed`; both columns are stamped server-side (`now()` and `config('ballspot.legal.terms_version')`) and neither is mass-assignable | Legal obligation / accountability (GDPR Art. 7(1) — being able to demonstrate consent) | **Kept on the anonymized row after account deletion** (see below) | Server-side only; admins (DB) |
| Avatar image | `storage/app/public/avatars/*`, `users.avatar_path` | Optional profile photo. Re-encoded server-side on upload (GD), which strips ALL embedded metadata — notably EXIF GPS — so a publicly-served avatar cannot leak the uploader's location. `avatar_path` is not mass-assignable | Consent (optional upload) | Deleted immediately on account deletion or avatar removal | Public URL while account exists |
| Email-verification codes | `email_verification_codes` (bcrypt `code_hash` only) | Verify email at registration | Contract | Purged opportunistically + `ballspot:cleanup-login-codes`; deleted on account deletion | Nobody (hash only) |
| Login 2FA codes + IP + user agent | `login_verification_codes` | Secure admin/forced-2FA login; IP/UA recorded for abuse detection | Legitimate interest (security) | Same cleanup as above; deleted on account deletion | Admins (DB) |
| Password reset tokens | `password_reset_tokens` (hashed by framework) | Password reset | Contract | 60 min framework expiry | Nobody (hash only) |
| API tokens | `personal_access_tokens` (SHA-256) | Mobile session auth | Contract | Revoked on logout/deletion; optional TTL via `SANCTUM_TOKEN_EXPIRATION_MINUTES` | Nobody (hash only) |
| Guesses & scores (daily/tournament/pack) | `daily_challenge_guesses`, `guesses`, `pack_attempt_guesses` | Core gameplay, leaderboards, stats | Contract | Retained after deletion, linked to the anonymized user row (leaderboard/history integrity) | Aggregates visible to other players via leaderboards |
| XP events | `xp_events` | Progression ledger (append-only) | Contract | Retained after deletion (anonymized linkage) | User (own) |
| Badges / trophies / finishes | `user_badges`, `tournament_finishes`, `competition_finishes`, `pack_attempts` | Achievements, Trophy Room | Contract | Retained after deletion (anonymized linkage) | All verified players — earned badges are listed on the public profile since v1.8.8; placements visible in tournaments |
| Notification settings + timezone | `notification_settings` (daily_reminder_enabled, tournament_reminder_enabled, admin_notifications_enabled, reminder_time, timezone, timestamps) | Reminder scheduling, announcement opt-out. Timezone is a weak location signal | Consent / user preference | Deleted on account deletion | User (own), admins (DB) |
| Expo push tokens | `push_tokens` (user_id, token, platform, device_name, last_seen_at, timestamps). Token is plaintext at rest by necessity — Expo needs the raw value, and a deterministic `unique` index + `updateOrCreate` rule out Laravel's non-deterministic `encrypted` cast without a separate lookup-hash column. **Accepted risk**, mitigated by: `$hidden` on the model so **no API ever returns it** (export returns only platform + timestamps), and the recommendation to enable Expo's enhanced push-security access token so a leaked token is useless without the server secret | Deliver daily/tournament reminders and admin announcements | Consent (OS-level permission + in-app opt-in) | Deleted on logout (that device), account deletion (all), or after 90 days unseen (`ballspot:cleanup-login-codes`) | Nobody via API; Expo push service in transit, then APNs (iOS) / FCM (Android, where applicable) |
| Friend code | `users.friend_code` | Share code so another player can send a friend request; also the QR payload (the QR encodes the code and nothing else). In `$hidden` and never fillable — only ever returned to its own owner via `GET /api/me/friend-code` and in that user's own export | Contract | **Nulled on account deletion** so the code stops resolving | User (own) only; other players never see it |
| Friend requests & friendships | `friend_requests` (requester_id, recipient_id, status, timestamps), `friendships` (user_id, friend_id, timestamps — two rows per friendship, one per direction) | Who a player is connected to, and pending requests either way | Contract | **Explicitly deleted by `AccountController::delete`** — the FK cascade does NOT fire, because deletion anonymizes the user row rather than removing it | The two parties involved |
| Admin flag | `users.is_admin` | Authorization. In `$hidden` — never serialized | — | — | Server-side only |
| Sessions (admin web) | `sessions` table — **stores `ip_address` + `user_agent`** for the session's lifetime | Admin CMS login | Contract / legitimate interest (security) | `SESSION_LIFETIME` (120 min) | Server-side only |
| Content backups | `backups/` folders (DB snapshot + uploaded images), produced by `ballspot:backup-content` | Disaster recovery | Legitimate interest | **Define and enforce a retention window (e.g. 30 days, then delete). Backups taken before a deletion contain pre-anonymization personal data until they expire** | Operator only (never committed — gitignored) |
| Support/contact data | none collected in-app; `BALLSPOT_SUPPORT_EMAIL` (info@vanmalderstudio.be) receives email the user sends voluntarily | Support | Legitimate interest | Mailbox policy | Operator |
| Admin audit data | **none currently** — see docs/security-hardening.md recommendation | — | — | — | — |

## Visible to other players (v1.8.2)

`GET /api/users/{id}/public-profile` is readable by **any authenticated, verified
player who has the user's id**. It exposes, by explicit allow-list: username,
display name, avatar URL, rank/level/XP, aggregate gameplay stats (tournaments
played and completed, guess count, total and average score, daily challenges
played, best daily score) and badge counts — plus, since v1.8.8, the list of
**earned** trophies (badge code, name, description, emoji icon, category,
rarity, earned-at date; never locked/unearned ones) — plus the viewer's own
relationship to that player (`is_friend`, `has_pending_request`).

It never exposes email, password hash, `is_admin`, `email_verified_at`, the
target's `friend_code`, or any per-guess detail. `PublicProfileTest` asserts this.
Since v1.8.6, anonymized (deleted) accounts return **404** here.

## Friend suggestions (v1.8.6)

`GET /api/friends/suggestions` derives up to 10 suggestions from data BallPicker
already holds: shared `league_members` rows ("played in the same tournament") and
recent `daily_challenge_guesses` ("active player"). The response contains the
**same public-safe fields as the friends list** (id, name, username, avatar URL,
rank/level/XP) plus a coarse reason label — never email, `friend_code`, or any
private data (`FriendSuggestionsTest` asserts this). Excluded: self, existing
friends, users with a pending request either way, recently-rejected pairs (30
days) and anonymized accounts. No new data is collected or stored for this
feature.

## Fullscreen guessing (v1.8.6)

Placing a guess in the fullscreen image view stores exactly the same single
coordinate pair (0–1 ratios) as normal guessing — no additional data.

## New trophies (v1.8.6)

Seven new badges (33 total). Badges remain gameplay/profile data: earned rows in
`user_badges` linked to the account, retained on deletion like all gameplay
history (see below).

## Public profile trophies + tournament limits (v1.8.8)

- The public profile (`GET /api/users/{id}/public-profile`) now includes the
  user's **earned trophies** as a safe allow-list (code, name, description,
  emoji icon, category, rarity, `earned_at`). Rank, badges and trophies are
  public gameplay profile data. No new personal data is collected; locked
  badges are not listed; anonymized accounts still return 404;
  `PublicProfileTest` asserts the allow-list and the absence of email/tokens.
- Four new badges (37 total): `rising_star`, `golden_touch`, `legend_status`
  (rank milestones) and `tournament_beast` (three podium finishes). Same
  storage and retention as all badges.
- Tournament rules tightened (gameplay config, not personal data): a user can
  host at most 1 lobby/active tournament, be a member of at most 2 lobby/active
  tournaments, and every new tournament serves exactly 1 photo per day
  (`rounds_per_day` forced to 1 server-side; legacy tournaments keep their
  stored value and stay playable).

## Device permissions (v1.8.2)

| Permission | When it is requested | What is done with it | If denied |
|---|---|---|---|
| Camera (`expo-camera`) | Only when the user taps "Scan QR code" on the Friends screen — never at launch, never in the background | Reads a QR code to extract a friend code. **No image or video frame is stored, uploaded or transmitted.** The scanner only ever reads a friend-code payload | Screen explains why and offers manual friend-code entry. Permanently-denied state points to device settings and still offers manual entry |
| Clipboard (`expo-clipboard`) | Only when the user taps "Copy friend code" | **Write only** — `Clipboard.setStringAsync(code)`. The app never calls any clipboard *read* API, so it cannot see what else is on the clipboard | Nothing else is affected |
| Photo library (`expo-image-picker`) | Only when choosing an avatar | Uploads the single selected image | Avatar stays unchanged |

iOS usage strings live in `mobile/app.json` → `ios.infoPlist`
(`NSCameraUsageDescription`, `NSPhotoLibraryUsageDescription`).

## Retention on account deletion — actual behaviour

`AccountController::delete` **anonymizes** the `users` row rather than deleting
it (gameplay history stays referentially intact as "Deleted User"). Because the
row survives, **`ON DELETE CASCADE` never fires** — anything that must not
outlive the account has to be deleted explicitly. Currently deleted explicitly:

- API tokens, avatar file, push tokens, notification settings (including the
  v1.8.6 `last_daily_reminder_date` reminder-dedupe column, which lives in the
  deleted `notification_settings` row)
- Email- and login-verification codes
- **Friendships and friend requests** (both directions) — added v1.8.2
- **`friend_code` is nulled** — added v1.8.2
- **`anonymized_at` is set** — added v1.8.6. This timestamp marks the row as a
  deleted account. It contains no personal data and powers three things: an
  **aggregate-only** count on the admin competition page ("Deleted/anonymized
  accounts: X" — no per-user detail, no list), exclusion from friend
  suggestions, and a 404 on the public-profile endpoint. Rows deleted before
  v1.8.6 were backfilled using `updated_at` as the best-available timestamp.

Retained, deliberately, linked to the anonymized row: guesses, XP events,
badges, tournament/competition finishes, pack attempts. Rationale: leaderboard
and other players' history integrity.

Also retained, deliberately: **`terms_accepted_at` and `terms_version`**. They
are not cleared on deletion — they are the accountability record that consent
was actually given, and by then the identity they were attached to has already
been severed (the row is "Deleted User" with a `@ballspot.deleted` address).
Keeping them proves *that* a consent existed for that account id without
identifying anyone.

> Anything added later that references `users` must be added to this list, or it
> will silently survive an erasure request. `AccountDeletionTest` is the guard.

## What BallPicker deliberately does NOT collect

- No tracking or analytics SDKs, no advertising identifiers (IDFA/GAID).
- No payment data (no payments exist).
- No precise location, contacts, or device fingerprinting.
- No chat/messages between users — including between friends.
- No clipboard reading, and no camera use outside the QR scanner screen.
- `device_name` for push tokens is supported by the API but the app never sends it.

## Export & deletion

- `GET /api/me/export` returns the user's data as JSON (no secrets: no password
  hash, no tokens, no raw push-token values). Since v1.8.2 it also includes the
  subject's own `friend_code`, their friends list and their pending friend
  requests. For the counterpart of a friendship or request it returns only
  username + display name — **never that other person's email or friend code**
  (`DataExportTest::test_export_never_exposes_other_users_emails_or_friend_codes`).
- `DELETE /api/account` revokes all API tokens, deletes the avatar file, push
  tokens, notification settings and pending verification codes, and anonymizes
  the user row. Gameplay history is retained linked to the anonymized row —
  this policy is disclosed in the privacy policy.

## Legal basis summary (draft — not legal-reviewed)

Plain-language mapping of each processing purpose to a GDPR basis. These are
developer judgements; have them confirmed by a lawyer before public launch.

| Processing | Plain-language reason | Suggested basis |
|---|---|---|
| Account, login, gameplay, leaderboards | You asked us to run the game for you | Contract (performance of the service) |
| Friends: friend code, requests, friendships, public profile | A social feature you choose to use — nothing happens until you share a code or send a request | Contract (performance of a user-initiated feature) |
| Push notifications: tokens, reminders, announcements | You turned them on, and you can turn them off | Consent / user preference (withdrawable at any time in Profile or device settings) |
| Camera for QR scanning | You tapped "Scan QR code"; nothing is retained | Consent (OS permission, per-use) |
| Login IP + user agent, rate limiting, abuse detection | Keeping accounts and the leaderboard honest | Legitimate interest (security) |
| Retaining gameplay history after deletion (anonymized) | Other players' past results must stay intact and leaderboards must not silently change | Legitimate interest (integrity of others' data), with identity severed |

Withdrawing consent for notifications stops all optional sends immediately;
it does not affect the lawfulness of anything sent before.
