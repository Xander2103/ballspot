# BallPicker Club Portal (`/club`)

Web self-service for **club owners and club admins** (plan §10, contracts §6, wave D2). It is separate from the
central admin (`/admin`) and uses the same services, so every rule (last owner, code limits, competition rule lock,
photo workflow) lives in one place. It is behind the Clubs feature flag: with `BALLSPOT_CLUBS_ENABLED=false` every
`/club…` URL is a plain 404.

## Sign-in

1. `GET /club/login` → `POST /club/login` (email + password, `web` guard).
   - Throttles: the `club-portal-login` limiter (5/min per email+IP, 10/min per IP) plus `FailedLoginLimiter`
     scope `club-portal` (50 **failed** attempts/hour per address, IP-independent; a correct password clears it).
   - Only an **active owner/admin membership of an active or suspended club** passes. Wrong password, unknown
     address, unverified email, plain members, removed/banned members, owners of archived clubs and platform
     admins without such a membership all get the same sentence ("These details are not valid for the Club
     Portal."). Log events are categories only: `club_portal.login_failed {reason, user_id}`.
   - The password step does **not** sign in. The session id is regenerated and a pending challenge is stored
     (`club_portal.pending`).
2. A 6-digit code is emailed (`ClubPortalLoginCodeNotification`, sent synchronously, in the user's language).
   `GET/POST /club/verify`, `POST /club/verify/resend` (limiter `club-portal-code`: 10/min per session, 30/min per IP).
   - Table `club_portal_login_codes` (migration `2026_10_21_000001`). It stores only a bcrypt **hash** of the
     code, holds at most one row per user, and deletes the row once it is used. Codes are never logged.
   - The code expires after `code_expiry_minutes` (10). After `code_max_attempts` (5) wrong tries it is locked
     and the user has to enter the password again. Attempts are counted in the database, so replaying an old
     session cookie does not reset them. A resend replaces the code but keeps the attempt count.
   - Resend has a `code_resend_cooldown_seconds` (60) cooldown. Every email (login + resend) counts against
     `code_emails_per_hour` (6) per address.
   - Accepted code → the membership is checked again → `Auth::login()` (no "remember me") → the session is
     regenerated → `club_portal.verified_at` / `club_portal.verified_user` are set. The user then goes to the
     page they first asked for, or to `/club`.
   - Why there is a dedicated table instead of `LoginVerificationService`: that service belongs to the app's
     token login. A verified id there mints a Sanctum token on `POST /api/login/verify`, and its `start()` wipes
     the user's pending app codes.
3. `POST /club/logout` signs out of the web guard completely and invalidates the session. This also ends an
   `/admin` session in the same browser, because both use the `web` guard.

Config `ballspot.clubs.portal`: `two_factor_required` (true; when false the password alone completes the portal
sign-in, but it must still happen on `/club/login`), `code_expiry_minutes`, `code_max_attempts`,
`code_resend_cooldown_seconds`, `code_emails_per_hour`, `idle_timeout_minutes` (60). Both limiters are in
`rate_limiter.fail_closed`.

## Middleware `club.portal` (`App\Http\Middleware\EnsureClubPortalAccess`)

- Guest → `/club/login`. A web session that did not pass the portal sign-in in this session for this user (for
  example an `/admin` login) → `/club/login` with `portal_two_factor_required`.
- Idle for longer than `idle_timeout_minutes` → signed out completely. `SESSION_LIFETIME` still applies on top.
- Club pages `/club/{club:slug}/…`:
  - Archived club, outsider, or platform admin without a membership → **404**.
  - Plain member → **403**.
  - Active club → Gate `portal.access` (owner/admin of an active club).
  - Suspended club → read-only. Every non-GET request is refused with `club_unavailable` before the controller
    runs, and the policies refuse it as well.
- Unknown slugs and children from another club (`scopeBindings`) look the same as a club you may not see. A guest
  is sent to the login page and a signed-in user gets a 404, so slugs cannot be probed.
- Responses carry `Cache-Control: no-store, private` and `X-Robots-Tag: noindex`.
- Clubs are addressed by **slug**: readable, unique, and already used by the admin for URLs. The words `login`,
  `verify` and `logout` are reserved by the route pattern.

## Pages and routes (`club-portal.*`)

| Route | Page / action |
|---|---|
| `login`, `login.submit`, `verify`, `verify.submit`, `verify.resend`, `logout` | sign-in |
| `home` (`/club`) | club switcher; with exactly one club it redirects to that club's dashboard. A "Switch club" menu appears in the navbar when the user manages ≥ 2 clubs |
| `dashboard` | `ClubStatsService::overview`, live/upcoming competitions, photo status counts, recent audit events |
| `members.index`, `members.remove`, `members.ban`, `members.unban`, `members.role`, `members.transfer` | search by username, filter role / active / banned, 25 per page. Shows usernames only, never emails |
| `codes.index`, `codes.store`, `codes.revoke`, `codes.regenerate`, `codes.print` | invite codes, copy button, printable QR sheet using the admin's pinned `qrcodejs@1.0.0` from cdnjs |
| `photos.index`, `photos.store`, `photos.withdraw` | up to 10 files per upload, mandatory rights/consent checkbox, optional note → `ClubPhotoReviewService::submit(source club_upload, actorKind club_admin)`; status list with rejection reasons; a pending, unassigned upload can be withdrawn |
| `settings.edit`, `settings.update` | owner only: `join_enabled`, `member_list_visibility`, `default_replay_mode`, `default_normal_play_policy` (optimistic `version`) |
| `audit.index` | this club's audit trail (filters: action, dates). Actor and target are shown by username. BallPicker's internal reasons are not shown |
| `competitions.index`, `.create`, `.store`, `.show` (`?tab=standings`), `.update`, `.publish`, `.cancel`, `.extend` | `{competition}` by public_id within the club |
| `leaderboard.index`, `leaderboard.csv` (`/club/{club}/leaderboard[.csv]`) | Wave F: the club's all-time leaderboard (same ranking as the app, D19; 50 per page) and its CSV download |
| `competitions.standings-csv` (`/club/{club}/competitions/{competition}/standings.csv`) | Wave F: CSV of a competition's standings ("Download CSV" on the Standings tab) |

## Role matrix (portal)

| Action | admin | owner |
|---|---|---|
| View dashboard, members, codes, photos, audit, competitions | ✓ | ✓ |
| Remove / ban / unban a member or an admin | ✓ | ✓ |
| Remove / ban / unban an owner | – | ✓ (another owner must remain: `club_last_owner`) |
| Act on yourself | – | – |
| Change role admin ↔ member | – | ✓ (never to or from owner) |
| Transfer ownership (type the username to confirm; you become admin) | – | ✓ |
| Create member invite codes, revoke any code, regenerate / print member codes | ✓ | ✓ |
| Create / regenerate / reveal / print **admin-granting** codes | – | ✓ |
| Upload / withdraw photos | ✓ | ✓ |
| Settings | – | ✓ |
| Competitions: create / edit before the lock, publish, cancel before the start, extend the end after the start | ✓ | ✓ |
| Leaderboard + CSV downloads (also on a suspended club: read-only) | ✓ | ✓ |

Every mutation goes through a FormRequest, a `ClubPolicy` ability (`manageMembers`, `manageCodes`, `uploadPhotos`,
`manageSettings`, `transferOwnership`, `manageCompetitions`; all of them need an active club) and the shared service.
Each one is audited with `actor_kind = club_admin`. `ClubDomainException` → back with the `error` flash.

## Competitions

Editing uses `ClubCompetitionService` and `ClubCompetitionRulesGuard`, with the same fields as the admin editor:
the window is entered in club time and converted to UTC. Only the club's **active** packs can be linked; packs
already linked stay selectable. The portal is stricter than the guard: once the rules are locked (start time
reached), the only allowed change is **extend end**. Name and description are locked too. Standings show the
ranked visible players (D22).

## CSV export (Wave F)

`App\Services\Clubs\ClubStandingsCsvExporter`, shared with the central admin (`admin.clubs.leaderboard.csv`,
`admin.clubs.competitions.standings-csv`):
- columns `rank, username, total_score, packs_completed, attempts_used` — the rows the boards show (active,
  non-anonymized members; shared ranks). **Usernames only**: never an email, user id or other personal field;
- UTF-8 with BOM; a cell starting with `=`, `+`, `-`, `@`, tab or CR is prefixed with `'` (spreadsheet formula
  injection guard);
- filename `<club-slug>[-<competition-slug>]-leaderboard|standings-YYYYMMDD.csv` (ASCII slug →
  safe `Content-Disposition`), `Cache-Control: no-store, private`;
- every download is audited `export.downloaded` (`export`: leaderboard | competition, `count`; actor kind
  `club_admin` in the portal, `platform_admin` in the admin) and visible in the portal audit log.

Content reports (Wave F) are platform-only: `report.created` / `report.resolved` audit events are hidden from the
portal audit log and the dashboard activity (`ClubAuditEvent::PLATFORM_ONLY_ACTIONS`), so club admins never learn
who reported whom.

## Platform-only (BallPicker)

There is no portal UI or route for:
- removing the ball or positioning it, preparing or converting photos, and publishing challenges or packs;
- voiding a competition or an attempt, rebuilding standings, finalizing;
- suspend / archive / reactivate / delete a club, `max_members`, timezone, club details and logo;
- adding users without an invite code;
- moderating member content reports (`/admin/club-reports`).

## Ops

- Deploy: `php artisan migrate` (adds `club_portal_login_codes`). Nothing else is needed. The portal needs working
  outgoing mail, because without the code nobody can sign in. As an emergency fallback,
  `clubs.portal.two_factor_required` can be set to false in config.
- Watch `club_portal.login_failed`, `club_portal.code_failed`, `club_portal.code_mail_limited` and
  `club_portal.code_send_failed` in the app log.
