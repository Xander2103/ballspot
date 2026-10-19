# BallPicker Clubs — implementation contracts & ownership

Companion to `docs/clubs-architecture-plan.md` (the source of truth for *what* to build). This file fixes the
shared names, file ownership, service signatures and API JSON shapes, so the sprint's agents can work in
parallel without diverging. If this file and the plan disagree, the plan's intent wins. Fix this file and say so
in your report.

Branch: `feature/ballpicker-clubs`. Local commits are allowed; pushing, merging, deploying and EAS builds are not.

---

## 1. Sprint decisions (supersede plan §16 where they differ)

| # | Decision |
|---|---|
| D1 | Platform admins create clubs. No self-service creation. |
| D2 | Replay modes per competition: `no_replay` · `single_attempt` (default) · `multiple_attempts` (max 2–10, `first`\|`best`). |
| D3 | Global XP, completion reward and badges at most once per player per club pack (reward claim). Competition placements give club trophies only, no global XP. |
| D4 | Club packs may include eligible public challenges. Club challenges never become public. |
| D5 | Roles are `owner` / `admin` / `member`. |
| D6 | Sole owner leaves/deletes their account → the club stays manageable by platform admins and is flagged `needs_owner` in the admin index. |
| D7 | Minimum age stays 16. |
| D9 | Ordinary members do **not** see the member list by default (club setting `member_list_visibility`: `admins` default \| `members`). |
| D10 | Club co-membership never feeds friend suggestions. |
| D11 | Limits come from config (`ballspot.clubs.limits.*`): members per club 500, clubs per player 50, active codes per club 20. |
| D12 | Leaderboards show usernames + avatars only. |
| D13 | Tab order: Play → Tournaments → **Clubs** → Friends → Profile. |
| D14 | No payments or billing. |
| D15 | Configurable per competition: `normal_play_policy` = `blocked` (**default**: normal play of linked packs is refused from publish until finalize) \| `allowed`. Locked at start like the other rules. Security review 2026-10: with `blocked`, normal attempts already in progress are **frozen** (not finished) until finalize/cancel/void; with `allowed`, a player never has a normal and a competition run open on the same pack at once (sequential play — finish one, then the other — stays allowed in `single_attempt` / `multiple_attempts`; `no_replay` refuses anyone with a guess on the pack). One published competition per pack and overlapping window. |
| D16 | Partial attempts count their in-window score. |
| D17 | Tie-break: total desc → `score_reached_at` asc → `attempts_used` asc → shared rank. |
| D18 | Archived clubs are hidden from My Clubs. Suspended clubs are shown flagged and read-only. |
| D19 | Club all-time leaderboard = sum over club packs of each member's best score on that pack across both tracks (normal attempt total vs competition attempt totals). |
| D20 | `ends_at` may be extended after start (never shortened); every extension is audited. |
| D21 | Every `is_admin` user has full club management. Abilities go through policies so narrower admin roles can be added later. |
| D22 | Removed/left/banned members are hidden from live standings. Their attempts are kept. |
| D23 | A banned user entering a valid code gets the generic `club_code_invalid`. |
| D24 | Club logos live on the public disk under random names. Photos and club challenge images live on the private disk. |

---

## 2. Waves & ownership

| Wave | Agent | Location | Owns (create/modify) |
|---|---|---|---|
| A | **Foundation** | main tree | all club migrations; all club models + factories; `app/Enums/Club*` and the other enums; `config/ballspot.php` `clubs` block; `EnsureFeatureEnabled`; `ClubDomainException` + its render hook in `bootstrap/app.php`; `lang/*/clubs.php` (error messages); `app/Policies/*`; `Gate::before` + the `club-join` limiter in `AppServiceProvider`; `ClubAuditLogger`, `ClubService`, `ClubMembershipService`, `ClubInviteCodeService`, `ClubMediaService`, `ChallengeImageUrl`; `club_private` disk; the signed media route; `routes/api_clubs.php`, `routes/admin_clubs.php`, `routes/club_portal.php` (included from `api.php`/`web.php`); phase-0 hardening G2–G5/G7 + `PackAccessGate` wiring; `tests/Concerns/CreatesClubs.php`; `tests/Feature/Clubs/Foundation/*` |
| B1 | **Member API** | main tree | `app/Http/Controllers/Api/Clubs/*` (except competitions); `app/Http/Resources/Clubs/*` (except competitions); `app/Http/Requests/Clubs/Api/*`; `ClubPackService`, `ClubRewardService`, `MyClubsQuery`, `ClubLeaderboardService`; `PackPlayService` / `PackPlayController` changes (reward claim, reservation hook); `AccountDeletionService` + `DataExportController` + `docs/privacy-data-inventory.md`; the member section of `routes/api_clubs.php`; `tests/Feature/Clubs/Api/*` |
| B2 | **Central Admin** | worktree → merged | `app/Http/Controllers/Admin/Clubs/*`; `resources/views/admin/clubs/**`; the `$navGroups` Clubs entry in `admin/layout.blade.php`; `app/Http/Requests/Clubs/Admin/*`; `AdminClubIndexQuery`, `ClubStatsService`; club scope in the existing admin `ChallengeController`/`ChallengePackController` + views (G6); `MediaExportService` default scope; `lang/*/clubs_admin.php` if needed; `routes/admin_clubs.php` (non-competition, non-photo parts); `tests/Feature/Clubs/Admin/*` |
| B3 | **Mobile** | main tree, `mobile/` only | everything Clubs-related under `mobile/src`; `MainTabs.tsx`, `TabIcon.tsx`, `AppNavigator.tsx` additions; i18n `clubs` namespace ×5; play-session adapter on the pack screens |
| C1 | **Competitions** | main tree | competition models' behaviour; `ClubCompetition*` services, `ReplayPolicy` implementations, `ClubCompetitionRulesGuard`, `ClubStandingsService`, `ClubCompetitionCloseService`; competition API controllers/resources; the admin competition editor (`resources/views/admin/clubs/competitions/**`, `Admin/Clubs/ClubCompetitionController`); the console commands `ballspot:clubs:*` + scheduler entries in `routes/console.php`; `tests/Feature/Clubs/Competitions/*` |
| C2 | **Mobile competitions** | main tree, `mobile/` only | competition screens and API |
| D1 | **Photos** | main tree | `ClubPhotoReviewService`, the admin photo queue/prepare/convert (`Admin/Clubs/ClubPhotoController`, `views/admin/clubs/photos/**`); `tests/Feature/Clubs/Photos/*` |
| D2 | **Club portal** | main tree | `app/Http/Controllers/ClubPortal/*`, `resources/views/club-portal/**`, `ClubPortalAuthController` + 2FA, the `club.portal` middleware, `routes/club_portal.php`; `tests/Feature/Clubs/Portal/*` |
| E | **Integration/QA** | orchestrator + QA agent | cross-cutting fixes, MySQL concurrency tests, docs, report |

Rules:
- Touch a file outside your ownership only when unavoidable. Keep it minimal and additive, and list it in your report.
- Never revert another agent's change. **Never commit**: the orchestrator commits at integration boundaries.
- Never stage `.DS_Store`. Never print `.env` values.
- Backend tests: `cd backend && php artisan test` (SQLite in-memory). Run your own filter often and the **full suite** before you report.
  Mobile: `cd mobile && npx jest && npx tsc --noEmit`. Mobile also reads `mobile/AGENTS.md`: the Expo v56 docs rule applies.

---

## 3. Shared names

### 3.1 Namespaces & files
- Models: `App\Models\{Club, ClubMember, ClubInviteCode, ClubCompetition, ClubCompetitionPack, ClubCompetitionAttempt,
  ClubCompetitionAttemptGuess, ClubCompetitionPackResult, ClubCompetitionEntry, ClubCompetitionFinish, ClubPackRewardClaim,
  ClubPhotoSubmission, ClubAuditEvent}`.
- Enums (string-backed) in `App\Enums`: `ClubRole` (owner, admin, member), `ClubMemberStatus` (active, left, removed, banned),
  `ClubStatus` (active, suspended, archived), `ClubType` (sports_club, school, company, community, other),
  `CompetitionState` (draft, published, cancelled, finalized, voided), `CompetitionPhase` (draft, upcoming, active, ended, finalized,
  cancelled, voided), `ReplayMode` (no_replay, single_attempt, multiple_attempts), `ScoreRule` (first, best),
  `NormalPlayPolicy` (blocked, allowed), `CompetitionAttemptStatus` (active, completed, expired, voided),
  `PhotoSubmissionStatus` (pending, in_review, in_preparation, converted, rejected).
- Services in `App\Services\Clubs\…`. Contracts in `App\Contracts\…`. Exceptions in `App\Exceptions\ClubDomainException`.
- Routes:
  - `routes/api_clubs.php`: loaded inside the `api` prefix, wrapped in `auth:sanctum, active, verified, feature:clubs`.
  - `routes/admin_clubs.php`: loaded inside the existing `admin` prefix/middleware.
  - `routes/club_portal.php`: web group, prefix `/club`.
  - The signed media route `GET /media/clubs/{path}` is named `clubs.media` and is registered in `web.php` by Foundation.
- Route names: API `api.clubs.*`; admin `admin.clubs.*`, `admin.club-competitions.*`, `admin.club-photos.*`, `admin.club-audit.*`;
  portal `club-portal.*`.
- Lang: `lang/{en,nl,fr,de,es}/clubs.php`. Keys: `errors.<code>`, plus `audit.<action>` labels; admin strings may stay English like the
  existing admin. Every API-facing message exists in all 5 languages.
- Tests: `tests/Feature/Clubs/<Area>/*Test.php`. The shared helpers trait `Tests\Concerns\CreatesClubs` offers `makeClub(array $attrs = [],
  ?User $owner = null)`, `addMember(Club, User, ClubRole = member)`, `makeClubPack(Club, int $challenges = 3, array $attrs = [])`,
  `makeClubChallenge(Club)`, `makePublicPack(int $challenges = 3)`, `makeCompetition(Club, array $packs, array $attrs = [])` and
  `clubToken(User)`. Factories exist for every club model.

### 3.2 Config (`config/ballspot.php` → `clubs`)
```php
'clubs' => [
  'enabled'  => env('BALLSPOT_CLUBS_ENABLED', false),
  'limits'   => ['max_members' => 500, 'max_clubs_per_user' => 50, 'max_active_codes' => 20,
                 'max_packs' => 100, 'max_competitions_active' => 10],
  'codes'    => ['length' => 8, 'alphabet' => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'],
  'join'     => ['per_user_per_minute' => 10, 'per_ip_per_hour' => 300, 'failures_per_hour' => 15, 'failures_per_ip_per_hour' => 60],
  'media'    => ['disk' => 'club_private', 'max_kb' => 15360, 'max_edge' => 2560, 'signed_url_ttl_minutes' => 60,
                 'logo_max_kb' => 2048],
  'competition' => ['max_attempts_cap' => 10, 'default_replay_mode' => 'single_attempt',
                    'default_normal_play_policy' => 'blocked', 'max_duration_days' => 120],
  'pagination' => ['default' => 20, 'max' => 50],
  'portal'   => ['two_factor_required' => true],
],
```
`GET /api/config` gains `features: { clubs: bool }`. The middleware alias `feature` → `EnsureFeatureEnabled` (`feature:clubs` returns
404 `feature_disabled` when off).

### 3.3 Error contract
`ClubDomainException(string $code, int $status, array $extra = [])`. The message comes from `__('clubs.errors.'.$code)`.
- API rendering: `{ "message": "...", "code": "<code>", ...extra }`.
- Admin/portal rendering: redirect back with `error` flash + input.
- Validation stays Laravel 422 `{message, errors}`.

| Code | HTTP |
|---|---|
| feature_disabled | 404 |
| club_not_found | 404 (also every outsider/non-member access) |
| club_forbidden | 403 (member without the ability, e.g. member list hidden) |
| club_code_invalid | 422 |
| club_join_locked | 429 |
| club_full | 422 |
| club_membership_limit | 422 |
| club_unavailable | 423 (suspended) |
| club_owner_must_transfer | 422 |
| club_last_owner | 422 |
| club_has_history | 422 |
| club_code_limit | 422 |
| club_status_invalid | 422 (invalid status transition, e.g. suspend an archived club — added by Foundation) |
| club_member_not_found | 404 (admin/portal: the target membership does not exist or is not active — added by Foundation) |
| club_member_banned | 422 (admin add of a banned user: unban first — added by Foundation) |
| stale_version | 409 |
| pack_reserved_for_competition | 409 |
| pack_in_competition | 422 (admin: club pack linked to a published, not finished competition cannot be archived, drafted or hidden) |
| competition_not_started | 422 |
| competition_ended | 422 |
| competition_attempt_limit | 422 |
| competition_pack_already_played | 422 |
| competition_attempt_in_progress | 409 |
| competition_rules_locked | 422 |
| competition_invalid_pack | 422 |
| competition_not_editable | 422 |
| unexpected_challenge | 422 |
| attempt_not_active | 409 |
| photo_invalid | 422 |
| photo_duplicate | 422 |
| photo_status_invalid | 422 (illegal photo workflow step, e.g. reject a converted photo or withdraw one BallPicker picked up — added by D1) |
| photo_rights_required | 422 (club upload without the rights/consent confirmation — added by D1) |
| photo_pending_limit | 422 (club already has `clubs.photos.max_open_per_club` open submissions — added by D1) |
| photo_pack_invalid | 422 (convert → pack of another club, a BallPicker pack, archived or unknown — added by D1) |
| portal_two_factor_required | 403 |

### 3.4 Audit
`ClubAuditLogger::log(Club $club, string $action, ?User $actor, ?Model $subject = null, array $metadata = [], string $actorKind = 'platform_admin'|'club_admin'|'member'|'system')`.
- Actions are allow-listed in `ClubAuditEvent::ACTIONS` (plan §6.6). Metadata keys are allow-listed, never emails or codes in full (codes are masked `ABCD-••••`).
- Called **inside** the same DB transaction as the change. It also writes `AppLog::event('club.'.$action, …)`.

### 3.5 Authorization
- `Gate::before`: an `is_admin` user → `true` **only** for abilities prefixed `admin.` or when the request is in the admin area. Member
  API abilities are decided by membership, even for admins (an admin who is not a member gets 404 on the member API).
- `ClubPolicy` abilities: `view`, `viewMembers`, `leave`, `play`, `manageCodes`, `manageMembers`, `manageCompetitions`, `uploadPhotos`,
  `manageSettings`, `transferOwnership`, plus `admin.manage` (platform only) and `portal.access` (owner/admin of an active club).
- `ClubMembershipService::membership(Club, User): ?ClubMember` is memoised per request (scoped container binding `ClubAccessContext`).
- Member routes resolve `{club}` by `public_id` and pass the club through `ClubAccess::requireMember($club, $user)`, which throws
  `club_not_found` for non-members. Nested `{pack}` resolves by slug **within** `$club->packs()`, and `{competition}` by public_id within
  `$club->competitions()`.

### 3.6 Media
- Disk `club_private` = local root `storage_path('app/private/clubs')`, never `public`.
- Paths: `{club_id}/photos/{random40}.jpg` (submissions), `{club_id}/challenges/hidden/{random40}.jpg`,
  `{club_id}/challenges/original/{random40}.jpg`.
- `ClubMediaService`:
  - `storeImage(UploadedFile, Club, string $kind): array{path,width,height,bytes,sha256,mime}` re-encodes with GD (JPEG q85, EXIF stripped,
    longest edge ≤ max_edge).
  - `storeLogo(UploadedFile, Club): string` stores on the public disk under `clubs/logos/{random40}.webp|jpg`.
  - `signedUrl(string $path): string` returns a temporary signed `clubs.media` route.
  - `delete(path)`.
- `ChallengeImageUrl::hidden(Challenge)`, `::original(Challenge)`: public disk → `asset('storage/…')` (unchanged output); `club_id` set →
  signed URL. Every existing `asset('storage/'.$challenge->…)` call site uses it.
- The `clubs.media` route: `signed` middleware, streams the file, `Cache-Control: private, max-age=3600`, 404 for a missing file, and
  rejects paths with `..`.

### 3.7 Gameplay hooks
- `App\Contracts\PackAccessGate::assertCanPlay(User, ChallengePack): void`.
  Plus `assertCanContinue(User, ChallengePack): void` for an attempt that already exists (each guess): same access rules
  without the pack-status check (added by Foundation). Club packs KEEP the reservation check on every guess (security
  review 2026-10): an in-progress normal attempt on a reserved pack is frozen, because its reveal (ball_x/y) would be the
  answer key for the competition attempt.
  - `PublicPackAccess` = active + public + `club_id` null, else 404 (identical to today).
  - `ClubPackAccess` = active member (not banned) of an active club + pack active, else `club_not_found`/`club_unavailable`.
  - Also throws `pack_reserved_for_competition` (start AND guess) when a linked competition with `normal_play_policy=blocked` is
    published and not finalized/cancelled/voided, or while the player has an active competition attempt on the pack in a
    competition whose window is open (any policy).
  - Club packs answer 404 `feature_disabled` while `ballspot.clubs.enabled` is off — also on the generic
    `POST /api/pack-attempts/{id}/guess`, which is outside the `feature:clubs` group.
  - The resolver picks by `pack.club_id`.
- `ClubRewardService::claim(User, ChallengePack, string $sourceType, int $sourceId): bool` (true = this run is rewarded; a unique insert
  settles races) and `isRewarded(User, ChallengePack, string $sourceType, int $sourceId): bool`.
  - Public packs never call it.
  - For club packs, `PackPlayService` awards `pack_guess`/`pack_completion` XP and evaluates badges **only** when the attempt is the
    rewarded run.
- Pack badge counting (`pack_master`, `pack_explorer`) counts public completed attempts + club reward claims whose run completed.
- Per-pack trophy badges for club packs get `badges.club_id = pack.club_id` and never appear in global catalogues.

---

## 4. Service signatures (Foundation)

```php
ClubService::create(array $data, User $actor): Club                 // data validated by FormRequest; optional owner_user_id
ClubService::update(Club $club, array $data, User $actor, int $expectedVersion): Club   // stale_version
ClubService::suspend(Club, User $actor, string $reason): Club
ClubService::archive(Club, User $actor, string $reason): Club
ClubService::reactivate(Club, User $actor): Club
ClubService::deleteIfEmpty(Club, User $actor): void                 // club_has_history
ClubService::updateSettings(Club, array $settings, User $actor, string $actorKind): Club

ClubMembershipService::joinWithCode(User, string $rawCode, ?string $ip): array{club: Club, member: ClubMember, joined: bool}
ClubMembershipService::leave(Club, User): void
ClubMembershipService::add(Club, User $target, ClubRole, User $actor, string $actorKind): ClubMember
ClubMembershipService::changeRole(ClubMember, ClubRole, User $actor, string $actorKind): ClubMember   // club_last_owner
ClubMembershipService::remove(ClubMember, User $actor, string $actorKind, ?string $reason): void
ClubMembershipService::ban(ClubMember, User $actor, string $actorKind, ?string $reason): void
ClubMembershipService::unban(ClubMember, User $actor, string $actorKind): void
ClubMembershipService::transferOwnership(Club, User $newOwner, User $actor, string $actorKind): void
ClubMembershipService::membership(Club, User): ?ClubMember          // memoised
ClubMembershipService::refreshCounters(Club): void

ClubInviteCodeService::normalise(string $raw): string               // upper, strip spaces/dashes
ClubInviteCodeService::format(string $code): string                 // ABCD-EFGH
ClubInviteCodeService::create(Club, array $opts, User $actor, string $actorKind): ClubInviteCode   // label, expires_at, max_uses, grants_role
ClubInviteCodeService::revoke(ClubInviteCode, User $actor, string $actorKind): void
ClubInviteCodeService::regenerate(ClubInviteCode, User $actor, string $actorKind): ClubInviteCode  // revoke + new with same options
ClubInviteCodeService::resolveUsable(string $normalised): ?ClubInviteCode
```
Join semantics:
- Inside a transaction with the user row locked, then the code row locked.
- Invalid, revoked, expired, exhausted, club not active, `join_enabled` false, or user banned → `club_code_invalid`, and the failure counter
  increments. Lock threshold → `club_join_locked`.
- Already active member → `joined: false`, no `uses_count` change.
- left/removed → reactivated.
- Caps → `club_full` / `club_membership_limit`.

---

## 5. Mobile API contract (`/api`)

Shared shapes:
```jsonc
// ClubCard
{ "id": "01JB…ULID", "name": "KFC Example", "slug": "kfc-example", "logo_url": "https://…/storage/clubs/logos/x.jpg" | null,
  "city": "Gent", "country_code": "BE", "club_type": "sports_club",
  "sport": { "slug": "football", "name": "Football", "emoji": "⚽" } | null,
  "role": "owner" | "admin" | "member", "status": "active" | "suspended",
  "packs_count": 6, "competitions": { "active": 1, "upcoming": 2 },
  "joined_at": "2026-10-20T10:00:00Z" }

// ClubPackCard (same fields as an item of GET /api/packs, plus)
{ "id": 12, "slug": "kfc-autumn-1-x7k2", "name": "…", "description": "…", "sport": {…}|null, "cover_image_url": "…"|null,
  "difficulty": "mixed", "challenge_count": 12, "is_featured": false,
  "progress": { "status": "active"|"completed", "completed_count": 8, "total_challenges": 12, "total_score": 640 } | null,
  "best_score": 640 | null,
  "reserved_for_competition": false,
  "competitions": [ { "id": "01J…", "name": "Autumn Cup", "phase": "active" } ] }

// CompetitionCard
{ "id": "01J…", "name": "Autumn Cup", "description": "…"|null, "phase": "upcoming"|"active"|"ended"|"finalized"|"cancelled"|"voided",
  "starts_at": "ISO", "ends_at": "ISO", "timezone": "Europe/Brussels",
  "rules": { "replay_mode": "no_replay"|"single_attempt"|"multiple_attempts", "max_attempts": 1, "score_rule": "first"|"best",
             "normal_play_policy": "blocked"|"allowed" },
  "packs_count": 2, "participants_count": 61,
  "me": { "rank": 4, "total_score": 2140, "packs_completed": 1 } | null }

// ClubLeaderboardEntry (shared ranks: 1,2,2,4)
{ "rank": 2, "user_id": 33, "username": "jules", "avatar_url": "…"|null, "total_score": 2700,
  "packs_completed": 2, "attempts_used": 3, "is_current_user": false }

// Leaderboard meta (club + competition boards)
{ "total_players": 61, "current_user_rank": 4|null, "current_user_score": 2140|null,
  "better_than_percentage": 93|null, "top_users": [ClubLeaderboardEntry…3], "nearby_users": [ClubLeaderboardEntry…] }

// Cursor page meta
{ "next_cursor": "opaque"|null, "per_page": 20 }
```

| Method & path | Response |
|---|---|
| `GET /api/config` | adds `"features": {"clubs": true}` |
| `GET /api/clubs?q=&sort=recent\|name&cursor=&limit=` | `{ data: ClubCard[], meta: { next_cursor, per_page, total_joined, query } }`. Only the caller's active/suspended memberships. `q` (trimmed, ≤ 60 chars) matches club name/city with LIKE. `recent` = `last_seen_at`/`joined_at` desc. |
| `POST /api/clubs/join` `{code}` | 200 `{ data: ClubCard, joined: bool }` |
| `GET /api/clubs/{club}` | `{ data: ClubCard & { description, region, members_count, abilities: { view_members, leave, manage }, packs_preview: ClubPackCard[≤3], competitions_preview: { active: CompetitionCard[], upcoming: CompetitionCard[≤3], completed_count }, leaderboard_preview: { top: ClubLeaderboardEntry[≤3], me: ClubLeaderboardEntry\|null } } }` |
| `POST /api/clubs/{club}/leave` | `{ left: true }` |
| `GET /api/clubs/{club}/members?q=&cursor=` | `{ data: [{ user_id, username, avatar_url, role, joined_at }], meta: { next_cursor, per_page, total } }`; 403 `club_forbidden` when hidden |
| `GET /api/clubs/{club}/packs?cursor=` | `{ data: ClubPackCard[], meta }` |
| `GET /api/clubs/{club}/packs/{slug}` | same `data` as `GET /api/packs/{slug}` + `reserved_for_competition`, `competitions` |
| `POST /api/clubs/{club}/packs/{slug}/start` | same body as `POST /api/packs/{slug}/start` (incl. 409 replay contract); 409 `pack_reserved_for_competition` |
| `GET /api/clubs/{club}/packs/{slug}/attempt` | same as `GET /api/packs/{slug}/attempt` |
| `POST /api/pack-attempts/{attempt}/guess` | unchanged contract; club packs re-check access; non-rewarded club runs return `rank_progress.xp_gained: 0`, `completion_xp: 0`, `new_badges: []` |
| `GET /api/clubs/{club}/leaderboard?cursor=&limit=` | `{ data: ClubLeaderboardEntry[], meta: LeaderboardMeta & { next_cursor, per_page } }` |
| `GET /api/clubs/{club}/competitions?phase=active\|upcoming\|completed&cursor=` | `{ data: CompetitionCard[], meta }` (completed = ended + finalized) |
| `GET /api/clubs/{club}/competitions/{competition}` | `{ data: CompetitionCard & { packs: [ ClubPackCard-lite { id, slug, name, cover_image_url, challenge_count } & { state: { attempts_used, attempts_remaining, next_attempt_number\|null, can_start, blocked_reason: null\|"not_started"\|"ended"\|"limit_reached"\|"already_played"\|"attempt_in_progress"\|"club_unavailable", active_attempt_id\|null, counted_score\|null, best_score\|null, first_score\|null } } ], my_entry: { rank, total_score, packs_completed, attempts_used, score_reached_at }\|null, leaderboard_preview: ClubLeaderboardEntry[≤5] } }` |
| `POST /api/clubs/{club}/competitions/{competition}/packs/{slug}/attempts` `{attempt_number}` | 200/201 `{ attempt: CompetitionAttempt, challenge: Challenge\|null }` |
| `GET /api/club-competition-attempts/{attempt}` | `{ attempt: CompetitionAttempt, challenge: Challenge\|null }` (owner only, else 404) |
| `POST /api/club-competition-attempts/{attempt}/guess` `{challenge_id, guessed_x, guessed_y}` | pack guess shape (`result`, `progress`, `next_challenge`, `rank_progress`, `new_badges`, `pack_completed`, `final_score`, `completion_xp`) + `"replayed": bool` + `"competition": { counted_score, total_score, rank, attempts_remaining }` |
| `GET /api/clubs/{club}/competitions/{competition}/leaderboard?cursor=&limit=` | `{ data: ClubLeaderboardEntry[], meta: LeaderboardMeta & { next_cursor, per_page, rules, phase } }` |
| `GET /api/clubs/{club}/competitions/{competition}/me/attempts` | `{ data: CompetitionAttempt[] }` |
| `GET /api/me/club-finishes` | `{ data: [{ competition: {id,name}, club: {id,name,logo_url}, placement, total_score, total_players, finalized_at }] }` |

```jsonc
// CompetitionAttempt
{ "id": 91, "competition_id": "01J…", "pack_slug": "…", "attempt_number": 2, "status": "active"|"completed"|"expired"|"voided",
  "current_index": 3, "completed_count": 3, "total_challenges": 8, "total_score": 240, "is_rewarded_run": false,
  "started_at": "ISO", "completed_at": "ISO"|null }
// Challenge = existing PackPlayService::challengePayload() shape (hidden_image_url is a signed URL for club challenges)
```

Cursor pagination uses Laravel `cursorPaginate()` with a deterministic order (sort key + id). `limit` is clamped to `clubs.pagination.max`.

Member API notes (B1, as built — see `docs/api-contract.md` "Clubs (member API)"):
- Cursors are opaque keyset cursors from `App\Services\Clubs\ClubCursor` (same semantics as `cursorPaginate()`, which cannot
  page on the SQL expressions My Clubs/members sort by). Leaderboards are ranked in memory and use an opaque offset cursor.
- Unknown `{club}` / out-of-club `{pack}` bindings answer the same 404 `club_not_found` as a non-member (route `missing`).
- Members see club packs that are `active` **and** public-visibility; anything else is 404 `club_not_found`.
- Joining a suspended club answers the generic `club_code_invalid` (join semantics of §4), not `club_unavailable`.
- `GET …/packs/{slug}` returns `challenges: []` while the pack is reserved for a competition (no photo preview, plan §5.2).
- `abilities.manage` = `manageMembers` (owner/admin of an active club).
- Reward-claim hook for the competition track: `ClubRewardService::claim()` at attempt start,
  `awardCompetitionGuessXp(User, ClubCompetitionAttempt, int $guessId, int $score): int` per guess and
  `awardCompetitionCompletion(User, ClubCompetitionAttempt): array{completion_xp, new_badges}` on completion
  (XpEvent sources `club_competition_guess` / `club_competition_completion`).

Contract integration notes (Wave E, as built — mobile ↔ backend):
- Permanent guard: `backend/tests/Feature/Clubs/Api/MobileContractShapeTest.php` calls every member endpoint the app calls
  (with the params/bodies `mobile/src/api/clubApi.ts` sends) and asserts each key of the TS types in
  `mobile/src/types/club.ts` / `types/pack.ts` with its JSON type and nullability; it also walks every cursor list the way
  `usePaginatedSearch` does (no duplicates, chain ends, `limit` clamped, broken cursor = 422 `cursor`) and checks that every
  member-facing error code has app copy (`CLUB_ERROR_CODES`). Change a payload → change the TS type, that schema and §5 together.
- All payload shapes matched §5 / the as-built notes; no backend payload changed in Wave E.
- App side: `competition_invalid_pack` (422 on start, pack without snapshot challenges) is now mapped to copy, and a start with a
  stale `attempt_number` (plain 422 validation error on `attempt_number`, no `code`) shows translated copy
  (`clubs.errors.competition_attempt_stale`) and reloads the pack states instead of showing the English server sentence. The
  competition screen also reloads on `competition_pack_already_played` / `competition_invalid_pack`.
- `429 club_join_rate_limited` (request limiter) deliberately has no club copy: the client keeps the code but shows its generic
  "try again in N seconds" from `Retry-After`. `429 club_join_locked` (failed-code lock) has its own copy.
- A replayed competition guess (`replayed: true`) reads `result` back from the stored row, so `distance` is the column-rounded
  value (e.g. 0.08 vs 0.0799…); every other `result` field is identical.

---

## 6. Admin & portal conventions
- Admin views extend `admin.layout`, use Bootstrap 5.3 like the existing pages, flash `success|warning|error`, and pinned-string tests.
- Clubs nav group key `clubs`, label `Clubs`, pages: Clubs (`admin.clubs.index`), Competitions (`admin.club-competitions.index`), Photo
  review (`admin.club-photos.index`), Audit log (`admin.club-audit.index`). B2 adds the group. Pages not built yet link to a "coming
  in this sprint" placeholder route until C1/D1 replace it.
- Admin controllers call `$this->authorize('admin.manage', $club)` (or `Gate::authorize('admin.manage')` for the index).
- QR rendering in admin/portal: client-side `qrcode` from a CDN (cdnjs, pinned version) on the print/code pages, consistent with the
  CDN-loaded Bootstrap. No new composer/npm packages.
- Portal (`/club`):
  - `web` guard; login with email + password.
  - Then a **mandatory emailed 6-digit code** on every new portal session (reuse the `LoginVerificationService` code pattern or a
    dedicated table if the service is token-bound; record which in the report).
  - Session key `club_portal.verified_at`. Middleware `club.portal` requires auth + verified 2FA + `portal.access` on the selected club.
  - Club switcher for users who admin several clubs. Own login throttle `club-portal-login`.

Central admin notes (B2, as built):
- Routes `admin.clubs.*` bind `{club}` by **id** (`whereNumber`), `{member}` / `{inviteCode}` with `scopeBindings()`. Tabs are
  `GET admin/clubs/{club}?tab=overview|members|codes|packs|competitions|photos|settings|audit`; `GET …/members` and `…/codes`
  redirect to their tab. Controllers extend `Admin\Clubs\AdminClubController` (AuthorizesRequests + `logAdmin()`).
- Placeholders: `Admin\Clubs\ClubCompetitionController@index` + `views/admin/clubs/competitions/index.blade.php` (C1 replaces) and
  `Admin\Clubs\ClubPhotoController@index` + `views/admin/clubs/photos/index.blade.php` (D1 replaces). The club detail's
  Competitions/Photos tabs (`views/admin/clubs/tabs/{competitions,photos}.blade.php`) are read-only lists; link the editor/queue there.
  The "Photo review" nav badge counts `ClubPhotoSubmission::open()` (only rendered on club pages).
- Foundation additions (additive): `ClubService::updateSettings(…, ?int $expectedVersion = null)` (stale_version; a change bumps
  `version`), `ClubService::replaceLogo(Club, ?UploadedFile, User)`, club setting key `default_normal_play_policy` (null → config).
- Content scope: `App\Services\Clubs\ClubContentScope` — query param `club` = '' (BallPicker, default) | `all` | `<id>` on
  `/admin/challenges`, `/admin/packs`, `/admin/media-export`; `eligibleChallenges(pack)` / `assertPackChallenges(pack, ids)` enforce
  §4.8 (validation error on `challenges`). Challenge/pack owner is chosen at creation and never changes (`club_id` prohibited on
  pack update, ignored on challenge update). Club challenges: `usage_pool = club`, images via `ClubMediaService::storeImage`.
- Admin list helpers: `AdminClubIndexQuery`, `AdminClubAuditQuery`, `ClubStatsService::overview(Club)`.

Photos notes (D1, as built):
- `App\Services\Clubs\ClubPhotoReviewService` (admin + portal):
  `submit(Club, UploadedFile, User $uploader, string $source, ?string $clubNote, bool $rightsConfirmed, string $actorKind = 'platform_admin'): ClubPhotoSubmission`,
  `assign(sub, User $admin, ?User $assignee = null)`, `markInReview(sub, User)`, `startPreparation(sub, User)`,
  `reject(sub, User, string $reason)` (3–500 chars, visible to club admins),
  `convert(sub, User, array $challengeData, UploadedFile $hiddenImage, ?int $packId = null): array{submission, challenge, pack}`,
  `withdraw(sub, User $actor, string $actorKind = 'club_admin'): void` (pending, unassigned club uploads only; row + file deleted, audit kept),
  queries `queue(array $filters, User $viewer, int $perPage = 25)` (status `open`|`awaiting`|`all`|enum value; `club`; `assignee` ''|me|unassigned|id; oldest first),
  `forClub(Club, ?string $status = null, int $perPage = 25)` (newest first), `statusCounts(?Club)`, `awaitingReviewCount(?Club)`,
  `eligiblePacks(Club)`, `allowedNext(sub)`, `canTransition(sub, PhotoSubmissionStatus)`.
- Transitions: pending → in_review | in_preparation | rejected | converted; in_review → in_preparation | rejected | converted;
  in_preparation → rejected | converted; converted/rejected are final. A step assigns the actor when unassigned.
- Submit: JPEG/PNG/WebP, ≤ `media.max_kb`, shortest edge ≥ `clubs.photos.min_edge` (320) else `photo_invalid`; sha256 of the
  original bytes is unique per club across all statuses (`photo_duplicate`; withdrawn rows are gone, so re-submitting is possible).
  Club uploads need `rightsConfirmed` and an active club; the open limit (`clubs.photos.max_open_per_club`, 100) applies to club
  uploads only; archived clubs refuse every upload (`club_status_invalid`).
- Convert: challenge via `App\Services\ChallengeCreator` (shared with `Admin\ChallengeController::store`), original = copy of the
  submission photo (`ClubMediaService::copyImage`), sport = chosen → club sport → football. Optional pack: same club, not archived, not
  linked to a published competition whose rules are locked (`competition_rules_locked`); appended at the end.
- Audit actions added: `photo.in_review`, `photo.preparation_started`, `photo.withdrawn`, `challenge.created`, `challenge.updated`
  (the latter two are also written by the admin challenge form for club challenges).
- Admin routes: `admin.club-photos.{index,prepare,download,assign,in-review,start-preparation,reject,convert}` (`{submission}` by id)
  and `admin.clubs.photos.store` (`POST /admin/clubs/{club}/photos`, `photos[]` ≤ 20). The nav badge counts `awaitingReview()`
  (pending + in review).


Competitions notes (C1, as built — see `docs/api-contract.md` "Clubs — competitions (member API)"):
- Services (`App\Services\Clubs`): `ClubCompetitionService` (create / update(only present keys, `version`) / extend / publish /
  cancel / void / voidAttempt / rebuildStandings, `normaliseRules`, `assertPacks`, `assertPlayablePacks`, `inProgressNormalAttempts`),
  `ClubCompetitionRulesGuard` (`isEditable`, `assertCanChange`, idempotent `lock()` snapshot + `competition.rules_locked`),
  `CompetitionEligibility` + `App\Contracts\ReplayPolicy` (`ReplayPolicies\{NoReplay,SingleAttempt,MultipleAttempts}Policy`),
  `ClubCompetitionPlayService` (`start`, `guess`), `ClubStandingsService` (`recomputePlayer`, `computePlayer`, `rebuild` with diff,
  `ranked`, `rankOf` = 1 + COUNT(strictly better), `myEntry`, `cardStandings`, `page`), `ClubCompetitionCloseService`
  (`expireAttempts`, `finalize(…, refinalize)`), `ClubCompetitionQuery` (member reads). `ClubDetailService` competitions_preview
  now ranks through `ClubStandingsService::cardStandings()` (one ranking implementation).
- `attempts_used` in the per-pack `state` counts every started attempt incl. voided ones (a void never hands out an extra
  attempt); pack results / entries count non-voided attempts only. Entries exist only with ≥ 1 in-window guess.
- `best` tie inside one player's attempts: equal score → earlier `last_scored_at` (never scored = last) → lower attempt number.
  `score_reached_at = null` ranks after any non-null at the same total.
- Start returns **201** for a newly created attempt, 200 for an idempotent retry / resume (body identical). A next number
  other than `attempts_used + 1` is a 422 validation error on `attempt_number`.
- Guess: a stored guess for the challenge is answered (`replayed: true`) **before** the phase check, so a retry after a timeout
  at the window end still gets its result. A guess at/after `ends_at` expires the attempt (committed) and answers
  `competition_ended`. Response extras: `is_rewarded_run`, `completion: null`, `already_completed` (= replay of the last guess).
- Voiding a competition expires active attempts and deletes its finishes; voiding an attempt of a finalized competition
  re-finalizes (finishes rewritten, audit `competition.rebuilt`). Finishes: one row per ranked visible player, shared placements.
- Admin routes `admin.clubs.competitions.{index,create,store,show,update,publish,cancel,extend,void,rebuild,finalize,attempts.void}`
  (`/admin/clubs/{club}/competitions…`, ids, scoped bindings) + `admin.club-competitions.index`.
- Built in Wave E (orchestrator): the admin pack edit form and quick status action refuse to archive, draft or hide a club pack
  linked to a published, not finalized/cancelled/voided competition (`pack_in_competition`, plan §5.7;
  `AdminClubPackCompetitionGuardTest`).

Security fixes (Wave E, security review 2026-10, as built — tests in `backend/tests/Feature/Clubs/Security/`):
- Answer key across tracks (HIGH). `ClubPackAccess::assertCanContinue` applies the reservation (frozen normal attempts →
  409 `pack_reserved_for_competition`; they resume after finalize/cancel/void). `CompetitionEligibility::blockedReason` adds
  rule 5 *another run in progress* → `attempt_in_progress` (start: 409 `competition_attempt_in_progress`, state
  `active_attempt_id` null): an active normal attempt on the pack when the policy is `allowed`, or an active attempt on the
  pack in another live competition. With `blocked` an active normal attempt does NOT block the start (it is frozen).
  Sequential play (finish the normal pack, then compete) stays allowed by design in `single_attempt` / `multiple_attempts`;
  admin helper sentence: `lang/*/clubs.php` `competition_messages.normal_play_allowed_helper`.
- `no_replay` "already played" = any run with ≥ 1 guess: a normal attempt with `current_index > 0` or completed, or an
  attempt with `guesses_count > 0` (or completed) in ANY other competition, voided/expired included (`NoReplayPolicy::playedAnywhere`).
- One live competition per pack: `ClubCompetitionService::assertNoOverlappingCompetition` (create, `pack_ids` change, window
  change, publish — admin and portal) refuses a pack already linked to another **published** competition of the club whose
  window `[starts_at, ends_at)` overlaps → 422 `competition_invalid_pack` + `reason: pack_in_overlapping_competition`,
  `pack_id`, `competition_id` (public id) and a specific message (`competition_messages.pack_in_overlapping_competition`).
  Drafts never conflict with each other; the second publish does. `ClubDomainException` takes an optional 4th `$message`.
- Content after lock: `App\Services\Clubs\CompetitionContentLock` (shared with the photo review's `resolvePack`).
  `Admin\ChallengeController::update` / `updateStatus` refuse a new ball position, new images or a status other than
  `active` for a challenge in the (effective) snapshot of a published, locked competition (`competition_rules_locked`; before
  the lazy lock writes the snapshot the pack's current challenges count). `destroy` refuses while the challenge is in a
  published or voided competition's snapshot (or a published one's pack before the snapshot), played or not. Applies to
  BallPicker challenges used in a club pack too. `ClubCompetitionPlayService::guess` / `currentChallenge` answer 422
  `competition_invalid_pack` + `reason: challenge_unavailable` if a snapshotted challenge row is gone.
- Join limits: `club-join` per IP 300/h (was 30/h) + per-IP FAILED-code counter `clubs.join.failures_per_ip_per_hour` = 60
  next to the per-user 15 (`ClubJoinFailureCounter`, both → 429 `club_join_locked`).
- Portal code: `ClubPortalLoginCodeService::verify` reserves the attempt with an atomic
  `UPDATE … SET attempts = attempts + 1 WHERE id = ? AND attempts < max` before `Hash::check`; 0 rows → `locked`.
- Club pack covers go through `ClubMediaService::storePackCover` (GD re-encode, public disk `packs/covers/{random40}.webp|jpg`);
  BallPicker covers unchanged.
- `SportReadinessService::for` and the diagnostics content totals count BallPicker challenges only (`publicContent()`).

Club portal notes (D2, as built — see `docs/club-portal.md`):
- 2FA uses a **dedicated table** `club_portal_login_codes` + `App\Services\ClubPortal\ClubPortalLoginCodeService` (not
  `LoginVerificationService`: that one is token-bound — a verified id mints a Sanctum token — and its `start()` deletes the
  user's pending app codes). Hash only, expiry / attempt lock / resend cooldown / per-address hourly mail ceiling from
  `ballspot.clubs.portal.*` (`code_expiry_minutes`, `code_max_attempts`, `code_resend_cooldown_seconds`,
  `code_emails_per_hour`, `idle_timeout_minutes` — added next to `two_factor_required`).
- The password step does not sign in; `Auth::login()` happens after the code (session regenerated on both steps). Session
  keys `club_portal.{pending,verified_at,verified_user,last_seen}` (`ClubPortalSession`). An `/admin` web session is not
  a portal session.
- Limiters `club-portal-login`, `club-portal-code` (AppServiceProvider, both in `rate_limiter.fail_closed`) +
  `FailedLoginLimiter` scope `club-portal`. Middleware alias `club.portal` (`EnsureClubPortalAccess`, bootstrap/app.php).
- Routes `/club/{club:slug}/…` (`login|verify|logout` reserved), nested bindings scoped, `{competition:public_id}`.
  Outsider / platform admin without membership / archived → 404; member → 403; suspended → read-only. Unknown slugs
  answer like hidden clubs (guest → login), so slugs cannot be probed. Test-only routes under `/club/` must not look
  like a slug (the Foundation probe is now `/club/_test-probe`).
- Portal role rules beyond the policies: `ClubPortalMemberRules` (no self-actions; owners only touchable by owners;
  role change admin↔member and transfer owner-only). Admin-granting invite codes are owner-only (create, regenerate,
  reveal, print); revoking any code is allowed to admins.
- Competitions reuse `ClubCompetitionRequest` / `ExtendClubCompetitionRequest` / `ClubCompetitionActionRequest` via
  subclasses that authorize `manageCompetitions` (and restrict packs to the club's active packs). Stricter than the
  guard: after the lock only `extend` (no name/description edits). No void / rebuild / finalize routes.
- Strings: `lang/{en,nl,fr,de,es}/club_portal.php` (key parity is tested).

---

## 7. Definition of done per agent
1. Feature works through its real entry points (HTTP tests, not only unit tests).
2. New tests cover the plan's acceptance IDs for the area (§13).
3. `php artisan test` is fully green (the existing 1,074 tests unchanged). Mobile: jest + tsc green.
4. `git diff --check` is clean. No debug output, no secrets, no `.DS_Store`.
5. The report lists files changed, deviations from these contracts, and anything left undone.
