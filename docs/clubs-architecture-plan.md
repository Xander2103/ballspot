# BallPicker Clubs — Architecture audit & implementation plan

Status: **v2 — approved for implementation (sprint started 2026-10-19)**. Implementation contracts: `docs/clubs-implementation-contracts.md`.
Branch: `feature/ballpicker-clubs` (created 2026-10-18 from `master` = `origin/master` = `10e020b`).
Nothing committed, pushed or deployed; no EAS build; no production changes.

| Version | Date | Change |
|---|---|---|
| v1 | 2026-10-18 | Discovery audit, first design, decisions D1–D14 |
| v2 | 2026-10-19 | **D2 confirmed**: replay rules configurable per competition (3 modes, default *one competition-specific attempt*). **Confirmed central-admin requirements** (scalable Clubs index, full member/code/pack/competition/photo/settings/audit/stats management, no hard delete with history). **Confirmed mobile requirements** (5th tab, searchable paginated My Clubs, separate Join flow, club detail sections, no public directory). Schema, services, endpoints, wireframes, acceptance tests and roadmap updated. |

---

## Contents
1. Executive summary
2. Existing architecture findings
3. Confirmed requirements
4. Domain model & database design
5. Competition replay, scoring & standings design
6. Backend architecture
7. Endpoints
8. Central admin UX (with wireframes)
9. Mobile navigation & screens (with wireframes)
10. Future club self-service dashboard
11. Security & privacy
12. Backward compatibility
13. Acceptance tests
14. Phased roadmap
15. Risks
16. Decisions — confirmed and remaining

---

## 1. Executive summary

- The codebase has **no tenancy, no roles and no policies**. Admin access is the single `users.is_admin`
  boolean and every ownership check is inline in controllers. Clubs introduces Laravel Policies, a
  club-scoped role model and explicit authorization in every club action — platform admins included.
- **Public Pack gameplay stays untouched.** A club pack is a `challenge_packs` row with a nullable `club_id`;
  ordinary (non-competition) play of club packs reuses `PackPlayService` behind an access gate.
- **Competition play is modelled separately.** Competition attempts live in their own tables
  (`club_competition_attempts` + `…_attempt_guesses`) linked to competition, player and pack, so normal Pack
  progress, replay blocking, Trophy Room and badge counts keep their current meaning.
- **Replay rules are per competition**: *No replay* · *One competition attempt* (default) · *Multiple attempts*
  (max N, *first* or *best* score counts). Enforced server-side, **locked when the competition starts**.
- **Global rewards are granted once per player per club pack**, whichever run plays it first (a unique
  reward-claim row); every further run — replays, extra competition attempts — earns no global XP, completion
  reward or badge.
- **Standings are derived data**: per-pack results and per-player entries are recomputed from persisted attempts
  and guesses inside the guess transaction, and can be fully rebuilt by command.
- **Central admin** gets a Clubs navigation group: server-side searchable/filterable/sortable/paginated index,
  club detail with tabs (members, codes, packs, competitions, photos, settings, audit, stats), status actions
  (suspend / archive / reactivate) and no hard delete for clubs with history.
- **Mobile** gets a 5th tab: My Clubs (joined clubs only, server-side search, cursor pagination, full state
  handling), a separate Join flow (code or QR), and a club detail with Packs, Competitions and Leaderboard.
  There is **no public club directory**.
- **Isolation risks found in the current code** (closed in phase 0, before any club data exists): public image
  URLs, `scopeVisibleToUsers()` as the only pack filter, the global badge catalogue/public profile, and
  unfiltered pools/Trophy Room/media export (§2.11).
- Everything is **additive** and behind `ballspot.clubs.enabled` (exposed via `GET /api/config`), so old app
  versions and public gameplay are unaffected.

---

## 2. Existing architecture findings

### 2.1 Stack
| Area | Finding |
|---|---|
| Backend | Laravel 12; Sanctum 4.3 bearer tokens for the app (`createToken('mobile')`, 90-day expiry); admin uses the `web` session guard on the same `users` table. No spatie/permission, no image library (GD only), no S3 adapter. |
| DB | SQLite (dev; tests in-memory) / MySQL (prod). `lockForUpdate()` is used for caps and races but is a no-op on SQLite, so **unique constraints must be the real guard** and concurrency is not exercised by the current tests. Migrations add FK indexes explicitly and use `hasColumn/hasTable` guards. |
| Queue | `database` driver configured, nothing queued; mail and push are synchronous. |
| Mobile | Expo SDK 56, RN 0.85, React Navigation 7 (manual navigators, not expo-router). One flat root native stack + `MainTabs` (Play, Tournaments, Friends, Profile). No data-fetching library; screens fetch in `useEffect` and refresh on focus. Custom i18n (en source; nl/fr/de/es typed as `Translations`, so `tsc` fails on missing keys). |
| Tests | Backend: 117 feature files (~1,070 tests), `RefreshDatabase`, only `UserFactory`. Mobile: jest over `src/utils` + `src/i18n` only, plus source-scanning guards (`LAUNCH_CRITICAL` i18n list, `releaseAuditGuards`). |

### 2.2 Auth & permissions
- Middleware: `admin` (`EnsureIsAdmin` → `is_admin`), `verified` (config-aware), `active` (rejects anonymised accounts).
- API groups: public → `auth:sanctum`+`active` → `+verified` (all gameplay).
- No `app/Policies`, no `Gate::` usage; every FormRequest `authorize()` returns `true`.
- Errors: `AuthError` `{message, code, errors?, codes?}` for auth; domain errors via `abort()` + `__('messages.*')` (5 languages).
  429s get a stable `code` through `AuthAbuseGuard::throttleContext()`.
- 22 named rate limiters, all through `ResilientRateLimiter` (fail-open except the `fail_closed` list).
- No admin 2FA, no admin audit table (only `AppLog` events). Minimum player age is 16 (`ballspot.legal.minimum_age`).

### 2.3 Challenge Packs
- `challenge_packs`: `sport_id?`, `name`, globally unique `slug`, `cover_image_path`, `status` draft|active|archived,
  `visibility` public|hidden, `difficulty`, `sort_order`, `is_featured`, `completion_badge_id?`. Visible to users ⇔
  `scopeVisibleToUsers()` (active + public). No scheduling.
- Slug-based API: `GET /packs`, `GET /packs/{slug}`, `POST /packs/{slug}/start`, `GET /packs/{slug}/attempt`,
  `POST /pack-attempts/{attempt}/guess`.
- `PackPlayService` (`app/Services/PackPlayService.php`):
  - `startOrResume` (47) locks the user row, resumes the active attempt, **blocks replay of a completed pack with 409** (70-74),
    snapshots `readyChallenges()` ids into `metadata`.
  - `submitGuess` (148) expects the challenge at `current_index`, is idempotent on resubmission, awards `pack_guess` XP keyed on the guess id.
  - `awardCompletion` (258) awards `pack_completion` (+250) and badges after commit.
  - `challengePayload` (457) never exposes the ball position.
- `pack_attempts` has no unique "one active attempt" constraint (only an index).
- Admin pack form auto-creates a per-pack trophy badge `pack_{id}_completed` in the **global** `badges` table.

### 2.4 Challenges & media
- `challenges`: `hidden_image_path` (ball removed), `original_image_path` (reveal), `ball_x/y_ratio` 0..1 (3 dp), `difficulty`,
  `status`, `usage_pool` daily|tournament|pack|general; no `created_by`, no review workflow.
- Admin uploads: `image|mimes:jpeg,jpg,png,webp|max:5120` → `public` disk; no resize, **no EXIF stripping** (only avatars are GD re-encoded).
- URLs built with `asset('storage/'.$path)` in ~9 places.
- Pools that would include club photos unless filtered: `Challenge::scopeDailyPool`, `scopeTournamentEligible(Strict)`,
  `DailyChallengeScheduler::eligibleChallenges`, `LeagueService::eligibleTournamentChallenges`, admin lists, `MediaExportService`.
- Subcategory `type` already has a value `club` (content tag for real-world clubs) — naming collision in the admin UI.

### 2.5 Scoring, XP, badges
- `ScoreService`: `max(0, round(100 − distance × 250))`.
- `xp_events` = one global ledger; unique `(user_id, source_type, source_id)` is the anti-duplication guard; rank = `SUM(amount)`.
- Badges are global (one row per code). Pack badges: `first_pack_completed`, `perfect_pack`, `pack_master` (10 completions,
  counts `pack_attempts`), `pack_explorer` (3 distinct packs), per-pack trophy.

### 2.6 Competitions today
- No table. `CompetitionPeriodService` + `CompetitionStandingsService::forWindow()` load all daily guesses of the period into PHP,
  sort by score desc → last guess asc → user id asc; no caching. `CompetitionCloseService` writes `competition_finishes` (top 3) idempotently.

### 2.7 Leagues
- `leagues` (6-char `join_code`, `owner_user_id`, lobby|active|completed|cancelled) + `league_members` (no role) + rounds + guesses.
- Patterns to reuse: transaction + user-row lock + target-row lock, config caps, idempotent `firstOrCreate` join.
- Gaps not to copy: ambiguous code alphabet, case-sensitive lookup, no revoke/expiry, no failure limiter, no leave, no transfer,
  leaderboard without tie-break.

### 2.8 Admin panel
- Blade + Bootstrap 5.3 (CDN), two-level nav from `$navGroups` in `admin/layout.blade.php`. Inline validation, `AppLog::event('admin.*')`,
  flash `success|warning|error`. Lists use Laravel pagination with query-string filters.

### 2.9 Privacy plumbing
- `AccountDeletionService` anonymises the user in place (cascades never fire) → every new user-linked table must be handled there,
  in `DataExportController` and in `docs/privacy-data-inventory.md` (`AccountDeletionTest` is the guard).
- Public profile is an allow-list and includes earned badges. Friend suggestions use shared `league_members`.
- `ContentBackupService` copies only `storage/app/public`.

### 2.10 Mobile
- Tabs in `src/app/MainTabs.tsx`; SVG icons as switch cases in `TabIcon.tsx`; labels `nav.tabs.*`. Detail/game screens in the root stack.
- Pack screens: `PacksScreen`, `PackDetailScreen`, `PackGuessScreen`, `PackResultScreen`, `PackCompleteScreen`; slug-based `packApi`; `goPacks` reset.
- Reusable: `Screen`, `AppButton`, `AppInput`, `EmptyState`, `ConfirmModal`, `CollapsibleSection`, `LeaderboardList`, `YourPositionCard`, `Avatar`.
  Leaderboard components still use static `colors.ts` (ignore the active theme). No shared list/search/pagination component yet.
- Friends code card (QR via `react-native-qrcode-svg`, copy via `expo-clipboard`) + `ScanFriendCodeScreen` (`expo-camera`, `ballpicker:friend:CODE`)
  is the pattern for club codes. Deep links: only `reset-password`; no URL may land a signed-out user on an authenticated screen.
- `apiClient` uses its own 20 s `AbortController`; it does not accept a caller's abort signal yet (needed for search-as-you-type).

### 2.11 Isolation gaps to close before any club data exists
| # | Surface | Gap |
|---|---|---|
| G1 | Images | everything on the `public` disk at unauthenticated `/storage/...` URLs |
| G2 | `ChallengePack::scopeVisibleToUsers()` | only filter between packs and `/api/packs` |
| G3 | `GET /api/badges`, `/me/badges`, public profile | global catalogue; a club pack trophy badge would leak its name |
| G4 | `/me/pack-completions`, Trophy Room, `pack_master`/`pack_explorer` | read all `pack_attempts` |
| G5 | Daily/tournament/general pools, recently-seen cooldown | read all `challenges` |
| G6 | `MediaExportService`, admin content lists | no tenant filter |
| G7 | Friend suggestions | would use club co-membership if built like leagues |

---

## 3. Confirmed requirements

### 3.1 Competition replay (D2 — confirmed 2026-10-19)
1. Three modes, configurable per competition by BallPicker Admin: **No replay**, **One competition-specific attempt** (default),
   **Multiple attempts** (configurable maximum, *first-score* or *best-score* ranking).
2. Public Pack gameplay fully backward compatible.
3. Competition attempts modelled explicitly (competition × player × pack), separate from normal Pack progress.
4. No duplicate global XP, completion rewards or badges through competition replays.
5. Only scores inside the competition window count.
6. Idempotent, concurrency-safe submission.
7. Replay limits enforced by the backend.
8. Defined tie and best-score handling.
9. Rules locked once the competition starts.
10. Standings rebuildable from persisted attempts and guesses.

### 3.2 Central admin (confirmed)
Dedicated Clubs navigation group; scalable index with server-side search (name, slug, location, identifiers), filters
(status, sport, …), sorting, pagination; create; detail and edit; suspend / archive / reactivate; full member administration;
invite/access-code management; multiple club Packs and private competitions; configurable replay rules, attempt limits and score
selection; photo submission/review; club settings, audit trail and statistics. Every action: authorization check, validation,
predictable errors, audit event. **No hard deletion of clubs with gameplay history.**

### 3.3 Mobile (confirmed)
Clubs as the 5th main tab. My Clubs: only joined clubs; prominent search filtering the player's clubs; smooth with many clubs;
pagination/incremental loading; logos, names, Pack counts, competition counts; loading, empty, no-results and error states;
separate Join Club action (invite code or QR). Club detail: identity header; Packs with progress; Competitions with
active/upcoming/completed; club leaderboard; member/leave actions where authorized. **No public directory**; My Clubs search and
Join by Code are separate flows.

---

## 4. Domain model & database design

### 4.1 Concepts
```
Club ─┬─< ClubMember >── User                 role: owner | admin | member · status: active | left | removed | banned
      ├─< ClubInviteCode                       revocable · expiring · max uses
      ├─< ChallengePack (club_id)              private packs, normal play via PackPlayService
      ├─< Challenge (club_id)                  club photos, never in public pools
      ├─< ClubPhotoSubmission ──> Challenge     review + manual preparation
      ├─< ClubAuditEvent
      └─< ClubCompetition ─┬─< ClubCompetitionPack >── ChallengePack (same club, challenge snapshot)
                           ├─< ClubCompetitionAttempt ─< ClubCompetitionAttemptGuess      (source of truth)
                           ├─< ClubCompetitionPackResult · ClubCompetitionEntry           (derived, rebuildable)
                           └─< ClubCompetitionFinish                                      (final placements)
User ─< ClubPackRewardClaim >── ChallengePack   one global-reward run per player per club pack
```

### 4.2 `clubs`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | internal only |
| public_id | char(26) unique | ULID; the only club id in the mobile API |
| name | varchar(80) | |
| slug | varchar(100) unique | admin URLs, readable identifier |
| description | text null | |
| logo_path | varchar null | random 40-char name, GD re-encoded (avatar pipeline) |
| sport_id | FK sports null, nullOnDelete | default sport |
| club_type | varchar(20) | `sports_club` \| `school` \| `company` \| `community` \| `other` |
| city, region | varchar(80) null | location (search + filter) |
| country_code | char(2) null | ISO 3166-1 |
| external_ref | varchar(60) null | federation/registration number (search) |
| contact_name, contact_email | varchar null | **admin-only**, personal data (privacy inventory) |
| timezone | varchar(64) | default `config('ballspot.competition.timezone')`; competition windows are shown in it |
| status | varchar(20) | `active` \| `suspended` \| `archived` |
| join_enabled | bool default true | master switch for all invite codes |
| settings | json null | allow-listed keys: `member_list_visibility`, `leaderboard_identity`, `default_replay_mode`, … |
| max_members | uint null | null = config default |
| members_count, active_packs_count, live_competitions_count | uint default 0 | denormalised for admin sorting and My Clubs cards; maintained by services, reconciled nightly |
| last_activity_at | timestamp null | last join / play |
| suspended_at, suspension_reason | | |
| archived_at, archived_reason | | |
| created_by_user_id, updated_by_user_id | FK users null, nullOnDelete | |
| version | uint default 1 | optimistic locking for admin edits |
| timestamps | | |

Indexes: `(status, name)`, `(sport_id, status)`, `(country_code, city)`, `(status, last_activity_at)`, `(status, members_count)`,
`external_ref`; on MySQL only a FULLTEXT `(name, city)` added later if LIKE search becomes slow (driver-guarded migration).

**Status machine**: `active ⇄ suspended`, `active|suspended → archived`, `archived → active` (reactivate).
- *suspended*: listed in My Clubs as "temporarily unavailable"; no play, no join, no new attempts, read-only standings.
- *archived*: removed from My Clubs (D18), all history kept, admin-visible.
- **Hard delete** only when the club has no packs, no competitions, no submissions and no member ever joined
  (created by mistake); otherwise only archive. `restrictOnDelete` FKs from packs/challenges enforce it at DB level.

### 4.3 `club_members`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| club_id | FK clubs cascade | |
| user_id | FK users cascade | |
| role | varchar(20) | `owner` \| `admin` \| `member` |
| status | varchar(20) | `active` \| `left` \| `removed` \| `banned` (rows kept for history and re-join rules) |
| joined_at, left_at | timestamp | |
| invite_code_id | FK club_invite_codes null, nullOnDelete | which code was used |
| added_by_user_id, removed_by_user_id | FK users null | admin actions |
| removal_reason | varchar(200) null | admin-only |
| last_seen_at | timestamp null | sort "recent" in My Clubs |
| timestamps | | |

`unique(club_id, user_id)`, `index(user_id, status, last_seen_at)`, `index(club_id, status, role)`.
Banned members cannot re-join with any code; left/removed members can re-join with a valid code (row reactivated).

### 4.4 `club_invite_codes`
| Column | Type | Notes |
|---|---|---|
| id, club_id (cascade) | | |
| code | varchar(16) **unique** | 8 chars from `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (≈8.5×10¹¹), stored normalised upper-case, shown `ABCD-EFGH`; input strips spaces/dashes |
| label | varchar(60) null | "U15 parents", "Season 26/27" |
| grants_role | varchar(20) | default `member` |
| max_uses, uses_count | uint null, uint | |
| expires_at, revoked_at | timestamp null | |
| created_by_user_id, revoked_by_user_id | FK users null | |
| timestamps | | `index(club_id, revoked_at)` |

### 4.5 Content columns on existing tables (additive)
| Table | Change | Existing rows |
|---|---|---|
| `challenge_packs` | `club_id` FK null, **restrictOnDelete**, `index(club_id, status)` | null = public pack, unchanged behaviour |
| `challenges` | `club_id` FK null, restrictOnDelete, `index(club_id, status)`; `usage_pool` value `club` | null = BallPicker content |
| `badges` | `club_id` FK null | null = global catalogue; club badges never in `/api/badges` |
| `pack_attempts` | **no change**; add `index(challenge_pack_id, status)` | — |

### 4.6 Competition tables

**`club_competitions`**
| Column | Type | Notes |
|---|---|---|
| id, public_id (ULID unique) | | |
| club_id | FK clubs cascade | |
| name, description | | |
| state | varchar(20) | stored: `draft` \| `published` \| `cancelled` \| `finalized` \| `voided` |
| starts_at, ends_at | datetime (UTC) | window `[starts_at, ends_at)`; shown in `timezone` |
| timezone | varchar(64) | copied from club at creation |
| replay_mode | varchar(30) | `no_replay` \| `single_attempt` (default) \| `multiple_attempts` |
| max_attempts | tinyint | 1 for the first two modes; 2…`config clubs.competition.max_attempts_cap` (10) for multiple |
| score_rule | varchar(10) | `first` \| `best`; forced `first` when `max_attempts = 1` |
| tie_break | varchar(30) | v1 fixed `earliest_reached` (§5.6); column reserved for future rules |
| normal_play_policy | varchar(10) | `blocked` (default) \| `allowed` — whether linked packs can be played normally between publish and finalize (D15, configurable per competition, locked at start) |
| rules_locked_at | timestamp null | set once at start (§5.7) |
| published_at, finalized_at, cancelled_at, voided_at | timestamp null | |
| void_reason | varchar(200) null | |
| participants_count | uint default 0 | denormalised |
| created_by_user_id, updated_by_user_id | FK users null | |
| version | uint | optimistic locking |
| timestamps | | `index(club_id, state, starts_at)`, `index(state, ends_at)` (close job) |

Derived **phase** (never stored, so it cannot drift): `draft` → `upcoming` (published, now < starts_at) → `active`
(starts_at ≤ now < ends_at) → `ended` (now ≥ ends_at, not finalized) → `finalized`; plus `cancelled` / `voided`.

**`club_competition_packs`** — `(club_competition_id, challenge_pack_id)` PK, `sort_order`, `challenge_ids` json null
(**snapshot at rule lock**), `snapshotted_at`. `index(challenge_pack_id)`. Invariant: `pack.club_id = competition.club_id`.

**`club_competition_attempts`** (source of truth)
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | attempts are addressed by id + owner check, like `pack_attempts` |
| club_competition_id | FK cascade | |
| challenge_pack_id | FK restrict | |
| user_id | FK users cascade | |
| attempt_number | tinyint | 1…max_attempts |
| status | varchar(20) | `active` \| `completed` \| `expired` (window ended mid-attempt) \| `voided` |
| active_slot | tinyint null | `1` while active, `NULL` otherwise → DB-level "one active attempt" guard |
| challenge_ids | json | copy of the competition snapshot |
| current_index, guesses_count | uint | |
| total_score | int | cache of the sum of its in-window guesses |
| is_rewarded_run | bool | this run holds the player's global reward claim for the pack (§5.8) |
| started_at, completed_at, last_scored_at | timestamp | `last_scored_at` = time of the last scored guess |
| voided_at, voided_by_user_id, void_reason | | admin integrity action |
| timestamps | | |

Constraints: **`unique(club_competition_id, challenge_pack_id, user_id, attempt_number)`** (limit + idempotent start),
**`unique(club_competition_id, challenge_pack_id, user_id, active_slot)`** (NULLs are distinct on MySQL and SQLite → at most one
active), `index(club_competition_id, user_id)`.

**`club_competition_attempt_guesses`** (source of truth)
| Column | Notes |
|---|---|
| id, attempt_id (cascade), challenge_id (restrict) | |
| position | index into `challenge_ids` |
| guess_x, guess_y (decimal 8,6), distance (decimal 10,6), score (int) | `ScoreService` |
| submitted_at | **server** time |
| timestamps | **`unique(attempt_id, challenge_id)`**, `unique(attempt_id, position)` |

**`club_competition_pack_results`** (derived) — `(club_competition_id, user_id, challenge_pack_id)` unique; `attempts_used`,
`counted_attempt_id`, `counted_score`, `best_score`, `first_score`, `completed` (bool), `score_reached_at`.

**`club_competition_entries`** (derived) — `(club_competition_id, user_id)` unique; `total_score`, `packs_counted`, `packs_completed`,
`attempts_used`, `score_reached_at`; `index(club_competition_id, total_score, score_reached_at, attempts_used)`.

**`club_competition_finishes`** — like `competition_finishes`: competition, `user_id` (nullOnDelete), `placement` (shared on full ties),
`total_score`, `total_players`, `metadata`; `unique(club_competition_id, user_id)`. Club-only trophies.

**`club_pack_reward_claims`** — `user_id`, `challenge_pack_id`, `source_type` (`pack_attempt` \| `competition_attempt`), `source_id`,
`claimed_at`; **`unique(user_id, challenge_pack_id)`**. Only for club packs (§5.8).

### 4.7 Photos and audit
**`club_photo_submissions`** — `club_id`, `uploaded_by_user_id` (nullOnDelete), `source` (`admin_upload` \| `club_upload`), private `disk`/`path`,
`mime`, `width`, `height`, `bytes`, `sha256` (duplicate detection per club), `status` (`pending` → `in_review` → `in_preparation` → `converted`;
`rejected`), `club_note`, `admin_note`, `rejection_reason` (visible to club admins), `challenge_id` (nullOnDelete), `assigned_to_user_id`,
`reviewed_by_user_id`, `reviewed_at`, `rights_confirmed_at`, timestamps; `index(club_id, status)`, `index(status, created_at)`.

**`club_audit_events`** — append-only: `club_id`, `actor_user_id` (nullOnDelete), `actor_kind` (`platform_admin` \| `club_admin` \| `member` \| `system`),
`action` (allow-listed, §6.6), `subject_type`, `subject_id`, `metadata` (allow-listed keys, before/after for setting changes),
`ip_hash` (optional), `created_at`; `index(club_id, created_at)`, `index(action, created_at)`.

### 4.8 Invariants (services + tests)
1. Public packs contain only public challenges.
2. Club packs contain challenges of their own club (and public challenges if D4 = yes).
3. Competitions link only packs of their own club; linked packs must be active with ≥ 1 ready challenge at publish and lock.
4. Club challenges are never in daily/tournament/general pools, public packs or default media exports.
5. Every non-archived club has ≥ 1 active owner, or falls back to platform-admin ownership (D6).
6. After rule lock, replay rules, linked packs, snapshots and `starts_at` never change (§5.7).
7. At most one active attempt per (competition, pack, player); `attempts_used ≤ max_attempts`.
8. At most one rewarded run per (player, club pack).

---

## 5. Competition replay, scoring & standings design

### 5.1 Two separate play tracks
| | Normal club Pack play | Competition play |
|---|---|---|
| Tables | `pack_attempts` / `pack_attempt_guesses` (existing) | `club_competition_attempts` / `…_guesses` (new) |
| Engine | `PackPlayService` (unchanged) + `ClubPackAccess` gate | `ClubCompetitionPlayService` (new), reusing `ScoreService` and the challenge payload/reveal builder |
| Replay | existing rule: completed pack → 409 | per competition `replay_mode` |
| Shows in | Club Packs section progress, Trophy Room (club section) | Competition screen, competition leaderboard |
| Global rewards | only if it is the player's rewarded run for the pack | only if it is the player's rewarded run for the pack |

Rejected alternative: a nullable `club_competition_id` on `pack_attempts`. Every existing query on that table (replay block,
completions, `pack_master`, Trophy Room, recently-seen) would need a new filter, putting the public flow at risk.

### 5.2 Replay modes
| Mode | `max_attempts` | `score_rule` | Who may start an attempt on a linked pack |
|---|---|---|---|
| **No replay** (`no_replay`) | 1 | first | only players who have **not completed** that pack before — no completed normal attempt and no completed attempt in another competition. Otherwise blocked with `competition_pack_already_played`. |
| **One competition attempt** (`single_attempt`, **default**) | 1 | first | every active member, even if they already completed the normal pack. The attempt is tracked independently. |
| **Multiple attempts** (`multiple_attempts`) | 2–10 | first \| best | every active member, up to `max_attempts`. A new attempt starts only after the previous one is completed (no parallel attempts). |

The backend computes eligibility and returns it per pack (`can_start`, `next_attempt_number`, `attempts_remaining`, `blocked_reason`).
The app only renders it; every start re-checks it.

**Normal play of a linked pack** (D15, recommended): from *publish* until *finalize*, `POST …/packs/{pack}/start` (normal track) returns
409 `pack_reserved_for_competition`, so nobody can preview the photos outside the competition. Normal attempts already in progress may
finish; the admin sees a warning with their count when linking. After finalization the pack returns to normal play.

### 5.3 Attempt lifecycle
```
start (in window, eligible, under limit) ─► active ─► guesses (in order, in window) ─► completed
                                             │
                                             ├─ window ends ─► expired   (partial score kept, D16)
                                             └─ admin void  ─► voided    (ignored by standings, audited)
```
- **Start** is allowed only in phase `active`, for an active member of an active club. The challenge list is copied from the
  competition snapshot (not from the live pack).
- **Guess** is allowed only while the attempt is `active`, the competition phase is `active` (server clock: `now < ends_at`) and the
  membership and club are still active. Otherwise 422 `competition_ended` / `competition_not_started`, 403 → 404 for lost membership.
- No grace period after `ends_at`; client clocks are never trusted. The guess timestamp is the server receive time.

### 5.4 Score window
- Window is `[starts_at, ends_at)` in UTC. Server-side rejection of out-of-window starts and guesses is the primary guard.
- Derivations (live and rebuild) count only guesses with `starts_at ≤ submitted_at < ends_at` from non-voided attempts — a second guard
  that also covers clock edge cases and admin `ends_at` extensions.

### 5.5 Score selection
Per (player, pack):
- **attempt score** = sum of its in-window guess scores; partial (expired/in-progress) attempts count what they scored (D16).
- **first**: the earliest non-voided attempt counts (`attempt_number` ascending). Later attempts are practice and visible to the player only.
- **best**: the non-voided attempt with the highest score counts. **Equal scores → the attempt that reached it first** (earlier
  `last_scored_at`) counts, so a later equal attempt never improves the tie-break.
- `score_reached_at` (pack) = `last_scored_at` of the counted attempt.

Per player (entry): `total_score = Σ counted_score` over linked packs; `score_reached_at = max(score_reached_at)` over counted packs
(the moment the player's current total was reached); `packs_completed`; `attempts_used`.

### 5.6 Ranking and ties
Order:
1. `total_score` desc
2. `score_reached_at` asc (who got there first)
3. `attempts_used` asc (fewer attempts better; only matters in multiple mode)

Players equal on all three **share the rank** (standard competition ranking: 1, 2, 2, 4) and share the placement in
`club_competition_finishes`. Display order inside a shared rank is username ascending (deterministic, not a ranking factor).
Players with zero in-window guesses are not on the leaderboard. Removed/banned members are hidden from live standings (D22);
finishes keep their placement and are anonymised on account deletion.
Rank query: `1 + COUNT(entries strictly better)` on the composite index — no window functions needed (portable SQLite/MySQL).

### 5.7 Rule lock (fairness)
- **Lock moment** = `starts_at`. `isLocked()` is `rules_locked_at !== null || now ≥ starts_at`, so it never depends on the scheduler.
- **Lock action** (idempotent, competition row locked): snapshot `challenge_ids` for every linked pack, set `rules_locked_at`, write an
  audit event. Runs from the scheduler tick **and** lazily inside the first attempt start, whichever comes first.
- Editability:

| Field | draft | upcoming (published) | active | ended | finalized |
|---|---|---|---|---|---|
| name, description | ✓ | ✓ | ✓ | ✓ | ✓ |
| replay_mode, max_attempts, score_rule | ✓ | ✓ (audited) | 🔒 | 🔒 | 🔒 |
| linked packs (add/remove/reorder) | ✓ | ✓ (audited) | 🔒 | 🔒 | 🔒 |
| starts_at | ✓ | ✓ (still future) | 🔒 | 🔒 | 🔒 |
| ends_at | ✓ | ✓ | extend only (D20, audited) | 🔒 | 🔒 |
| cancel | ✓ | ✓ | — | — | — |
| void (platform admin, reason required) | — | — | ✓ | ✓ | ✓ |
| void single attempt (cheating, reason) | — | — | ✓ | ✓ | ✓ + re-finalize |

- Enforced by `ClubCompetitionRulesGuard` in the service layer (not by the form). Locked fields are rendered read-only in the admin UI,
  and a POST that changes one anyway is rejected with `competition_rules_locked`.
- Edits to the underlying pack after lock (adding/removing challenges) affect normal play only. Archiving a pack linked to a
  non-finalized competition is refused (`pack_in_competition`).
- Optimistic locking (`version`) prevents two admins overwriting each other.

### 5.8 Rewards without duplication
- **Global XP/completion reward/badges per club pack are granted at most once per player**: the first run (normal attempt *or*
  competition attempt) that starts on a pack inserts `club_pack_reward_claims(user_id, challenge_pack_id)`; the unique index decides the
  race. That run sets `is_rewarded_run`.
- Rewarded run → per-guess XP and completion XP use the existing amounts. Normal track: existing `pack_guess`/`pack_completion` sources
  (unchanged). Competition track: new source types `club_competition_guess` (keyed on competition guess id) and
  `club_competition_completion` (keyed on attempt id); the ledger's unique index stays as a second guard.
- Non-rewarded runs (replays, extra attempts, single attempt after a completed normal run) → **no** global XP, no completion reward,
  no badge evaluation. They still score for the competition.
- Pack badges (`first_pack_completed`, `perfect_pack`, `pack_master`, `pack_explorer`, per-pack trophy) are evaluated only on the
  rewarded run's completion. For club packs, counts use the claim table, not `pack_attempts`.
- Competition placements: club-only trophies (`club_competition_finishes`), **no global XP** (D3).
- Public packs: no change at all (their replay block already guarantees a single rewarded run).

### 5.9 Concurrency and idempotency
**Start attempt** — `POST …/competitions/{c}/packs/{pack}/attempts {attempt_number}`
1. Transaction; lock the user row (existing pattern). The unique constraints are the real guard on SQLite.
2. Same `attempt_number` already exists → return it (200, identical body). Double-taps and retries never create extra attempts.
3. An active attempt exists → return it (resume).
4. Check phase, membership, eligibility, `attempt_number = attempts_used + 1 ≤ max_attempts`, previous attempt completed/expired.
5. Insert with `active_slot = 1`; a unique violation → re-read and return the winner's attempt.
6. Claim the reward slot (insert-or-ignore) → set `is_rewarded_run`.

**Submit guess** — `POST /api/club-competition-attempts/{attempt}/guess {challenge_id, x, y}`
1. Transaction; lock the attempt row; owner, membership, club, phase and window checks.
2. A guess for `challenge_id` already exists → return the stored result (200, `replayed: true`). This is the same contract as packs.
3. `challenge_id` must equal `challenge_ids[current_index]`; otherwise replay the stored guess if any, else 422 `unexpected_challenge`.
4. Insert the guess (a unique violation → treat as step 2), update the attempt totals; on the last challenge → `completed`, `active_slot = NULL`.
5. **Recompute** that player's pack result and entry from source rows (no increments → idempotent and race-free), bump
   `participants_count` on the first entry.
6. After commit: XP for rewarded runs (deduped ledger keys); failures are logged and swallowed (existing pattern).

### 5.10 Rebuild
- `php artisan ballspot:clubs:rebuild-standings {competition?} [--dry-run]` recomputes attempt totals → pack results → entries from guesses
  (window and void filters applied) and prints a diff. A finalized competition requires `--refinalize`, which re-writes finishes and
  writes an audit event.
- Admin button "Rebuild standings" (dry-run preview first) on the competition page.
- Test invariant: incremental standings after any sequence of starts/guesses/voids/extensions **equal** a full rebuild.

### 5.11 Scheduler
`ballspot:clubs:competition-tick` every minute, `withoutOverlapping`, idempotent:
1. Lock rules for competitions whose `starts_at` passed.
2. Expire active attempts of ended competitions (`active_slot = NULL`).
3. Finalize ended competitions → finishes, club trophies, member push (later phase).
4. Reconcile denormalised counters nightly (`ballspot:clubs:reconcile-counters`).

---

## 6. Backend architecture

### 6.1 Layout (follows the existing flat `app/Services` style)
```
app/Models/            Club, ClubMember, ClubInviteCode, ClubCompetition, ClubCompetitionPack, ClubCompetitionAttempt,
                       ClubCompetitionAttemptGuess, ClubCompetitionPackResult, ClubCompetitionEntry, ClubCompetitionFinish,
                       ClubPackRewardClaim, ClubPhotoSubmission, ClubAuditEvent
app/Enums/             ClubRole, ClubMemberStatus, ClubStatus, CompetitionState, CompetitionPhase, ReplayMode, ScoreRule
app/Services/Clubs/
  ClubService                     create, update, suspend, archive, reactivate, deleteIfEmpty
  ClubDirectoryQuery              admin index search/filter/sort/paginate; My Clubs search + cursor
  ClubMembershipService           joinWithCode, leave, add, remove, ban, unban, changeRole, transferOwnership
  ClubInviteCodeService           generate, normalise, resolve (uniform failure), revoke, regenerate
  ClubPackService                 club packs + challenges with tenant invariants, progress per member
  ClubCompetitionService          create/update/publish/cancel/void, link packs, phase derivation
  ClubCompetitionRulesGuard       editability matrix (§5.7), rule lock + snapshot
  ClubCompetitionPlayService      attempts and guesses (§5.9)
  CompetitionEligibility          replay-mode rules (§5.2), one class per mode behind a ReplayPolicy interface
  ClubStandingsService            recompute player, rebuild, leaderboard reads, rank, club all-time board
  ClubCompetitionCloseService     finalize → finishes (idempotent)
  ClubRewardService               reward claims + XP/badge hand-off (§5.8)
  ClubMediaService                private storage, GD re-encode, signed URLs
  ClubPhotoReviewService          submission workflow → challenge conversion
  ClubStatsService                admin statistics
  ClubAuditLogger
app/Contracts/         PackAccessGate (PublicPackAccess, ClubPackAccess), ReplayPolicy (NoReplay, SingleAttempt, MultipleAttempts)
app/Policies/          ClubPolicy, ClubCompetitionPolicy, ClubInviteCodePolicy, ClubPhotoSubmissionPolicy
app/Http/Controllers/Api/Clubs/*      thin, member-facing
app/Http/Controllers/Admin/Clubs/*    thin, platform-admin
app/Http/Requests/Clubs/*             FormRequests (shared later by the club dashboard)
app/Http/Resources/Clubs/*
app/Http/Middleware/EnsureFeatureEnabled  ('feature:clubs')
app/Exceptions/ClubDomainException        stable code + HTTP status + translated message
```

### 6.2 Authorization
- **Platform admins** (`is_admin`): `Gate::before` grants club abilities in admin context. Every admin controller action still calls
  `$this->authorize(...)`, so the same code paths can later serve restricted admin sub-roles (D21) and the club dashboard.
- **Club roles** (active membership only):

| Ability | member | admin | owner | platform admin |
|---|---|---|---|---|
| view club, packs, competitions, leaderboards; play | ✓ | ✓ | ✓ | ✓ (admin UI) |
| view member list | per `member_list_visibility` (D9) | ✓ | ✓ | ✓ (+ email) |
| leave | ✓ | ✓ | not as sole owner | — |
| manage invite codes, remove/ban members | | ✓ (phase 7) | ✓ (phase 7) | ✓ |
| photos, packs, competitions | | ✓ (phase 7, no rule changes after lock) | ✓ (phase 7) | ✓ |
| roles, ownership transfer, settings | | | ✓ (phase 7) | ✓ |
| suspend / archive / reactivate / void | | | | ✓ |

- Membership is resolved **once per request** (memoised resolver), and `abilities` flags are returned to the app.
- **Non-members get 404**, never 403, on every member route. Nested bindings use `scopeBindings()`, so another club's pack or
  competition is a 404 by construction.
- `PackAccessGate` is injected into `PackPlayService`; the existing guess endpoint re-checks club access on every guess.

### 6.3 Validation & error handling
- FormRequests for all club inputs. Admin: field errors in the form + old input. API: Laravel 422 `{message, errors}`.
- Domain failures throw `ClubDomainException(code)` → API `{message, code}` with fixed status; admin → flash `error`. Translated in
  `lang/*/messages.php` (5 languages) and mirrored in the mobile error mapping.

| Code | HTTP | Meaning |
|---|---|---|
| `club_code_invalid` | 422 | unknown / revoked / expired / exhausted / join disabled (uniform on purpose) |
| `club_join_locked` | 429 | too many failed codes |
| `club_full` · `club_membership_limit` | 422 | club cap · per-user cap |
| `club_banned` | 422 | banned from this club (shown generically as invalid code? — D23) |
| `club_unavailable` | 423 | suspended |
| `club_owner_must_transfer` | 422 | sole owner leaving |
| `competition_not_started` · `competition_ended` | 422 | window |
| `competition_attempt_limit` | 422 | `max_attempts` reached |
| `competition_pack_already_played` | 422 | no-replay mode |
| `competition_attempt_in_progress` | 409 | must finish the active attempt first |
| `competition_rules_locked` | 422 | admin edit after lock |
| `pack_reserved_for_competition` | 409 | normal play blocked while linked |
| `stale_version` | 409 | optimistic lock (admin) |
| `club_has_history` | 422 | hard delete refused |

### 6.4 Reuse of gameplay
- Normal club pack play goes through `PackPlayService` unchanged, apart from the access gate, the reward claim for club packs and the
  reservation check (D15). All existing pack tests must pass without edits.
- The competition engine reuses `ScoreService`, `challengePayload()` and the reveal builder (extracted as stateless helpers without
  changing their output — existing tests guard this). It does not reuse `PackPlayService`'s transaction code.

### 6.5 Public-surface hardening (phase 0)
| Gap | Change |
|---|---|
| G2 | `scopeVisibleToUsers` → `whereNull('club_id')`; new explicit `scopePublicCatalog` |
| G5 | `whereNull('club_id')` in `dailyPool`, `tournamentEligible*`, `DailyChallengeScheduler`, `LeagueService::eligibleTournamentChallenges`; admin "set as daily" refuses club challenges |
| G3 | catalogue, `/me/badges`, public profile → `badges.club_id IS NULL` |
| G4 | `/me/pack-completions`, Trophy Room → public packs only; club section member-only |
| G6 | media export default excludes club content; admin lists default scope "BallPicker" |
| G1 | club media on a private disk with signed URLs (§6.7) |
| G7 | friend suggestions never use club co-membership (D10) |

### 6.6 Audit events (every mutating admin/club action)
`club.created|updated|suspended|archived|reactivated|deleted`, `club.settings_changed` (before/after),
`member.added|joined|left|removed|banned|unbanned|role_changed|ownership_transferred`,
`code.created|revoked|regenerated`, `pack.created|updated|archived|linked|unlinked`,
`competition.created|updated|published|cancelled|rules_locked|ends_at_extended|voided|finalized|rebuilt`,
`attempt.voided`, `photo.uploaded|assigned|rejected|converted`. Also mirrored to `AppLog::event` with `admin_user_id`.
Written in the same transaction as the change.

### 6.7 Private media
- `club_private` disk (`storage/app/private/clubs/{club_id}/…`), random names, GD re-encode (strips EXIF/GPS), max edge 2560 px, size and
  dimension limits from config.
- `GET /media/clubs/{asset}` web route with **temporary signed URLs** (~60 min), `Cache-Control: private`. URLs are issued only to
  authorised members, and reveal URLs only after the guess. Works with RN `<Image>`. Later: nginx `X-Accel-Redirect`.
- One `ChallengeImageUrl` helper replaces the `asset('storage/…')` call sites and picks a public or signed URL.
- Club logos may stay on the public disk with random names (low sensitivity), or move to the private disk (D24).
- `ContentBackupService` + backup runbook include `storage/app/private/clubs`.

### 6.8 Scalability notes
- Every list endpoint is paginated (cursor for mobile, page for admin) with server-side caps (`limit ≤ 50`).
- My Clubs search runs inside the player's memberships (`club_members.user_id` index → bounded set), so it stays fast regardless of the
  total number of clubs.
- Leaderboards read derived tables with composite indexes, never raw guesses in PHP.
- Counters are denormalised for sorting; per-page `withCount` only where the set is bounded.
- Recompute-per-player keeps guess writes O(packs in competition).

---

## 7. Endpoints

### 7.1 Mobile API
All under `auth:sanctum`, `active`, `verified`, `feature:clubs`. Clubs are addressed by `public_id`, packs by slug inside the club scope,
and competitions by `public_id`.

| Method | Path | Notes |
|---|---|---|
| GET | `/api/config` | + `features.clubs` (existing endpoint, additive) |
| GET | `/api/clubs?q=&sort=recent\|name&cursor=&limit=20` | **My Clubs**: active (and suspended, flagged) memberships only. `q` matches club name/city **within memberships**. Items: `id, name, logo_url, city, role, status, packs_count, competitions {active, upcoming}, has_new`. `meta: {next_cursor, total_joined}` |
| POST | `/api/clubs/join` | `{code}`, `throttle:club-join`; idempotent if already a member; returns the club card |
| GET | `/api/clubs/{club}` | header (name, logo, city, type, members_count), my role, `abilities`, section summaries (counts + first items) |
| POST | `/api/clubs/{club}/leave` | |
| GET | `/api/clubs/{club}/members?q=&cursor=` | visibility per D9; usernames, avatars, roles |
| GET | `/api/clubs/{club}/packs?cursor=` | club packs + my normal progress, and a `reserved_for_competition` flag |
| GET | `/api/clubs/{club}/packs/{pack}` | same shape as `GET /api/packs/{slug}` (reuses `PackDetailScreen`) |
| POST | `/api/clubs/{club}/packs/{pack}/start` | normal track; `throttle:gameplay`; 409 `pack_reserved_for_competition` |
| GET | `/api/clubs/{club}/packs/{pack}/attempt` | resume normal attempt |
| POST | `/api/pack-attempts/{attempt}/guess` | **existing**, now access-gated for club packs |
| GET | `/api/clubs/{club}/competitions?phase=active\|upcoming\|completed&cursor=` | completed = ended + finalized |
| GET | `/api/clubs/{club}/competitions/{competition}` | window, timezone, **rules** (`replay_mode, max_attempts, score_rule`), linked packs with my per-pack state `{attempts_used, attempts_remaining, next_attempt_number, can_start, blocked_reason, active_attempt_id, counted_score, best_score}`, my entry + rank |
| POST | `/api/clubs/{club}/competitions/{competition}/packs/{pack}/attempts` | `{attempt_number}` → start or resume (idempotent, §5.9); `throttle:gameplay` |
| GET | `/api/club-competition-attempts/{attempt}` | attempt state (current challenge, progress) — owner only |
| POST | `/api/club-competition-attempts/{attempt}/guess` | `{challenge_id, guess_x_ratio, guess_y_ratio}` → score, reveal, attempt total, counted score, my rank |
| GET | `/api/clubs/{club}/competitions/{competition}/leaderboard?cursor=&limit=50` | `{data, meta: {me, top3, nearby, total_players, rules}}` |
| GET | `/api/clubs/{club}/competitions/{competition}/me/attempts` | my attempt history for the competition |
| GET | `/api/clubs/{club}/leaderboard?cursor=` | club all-time board (D19) |
| GET | `/api/me/club-finishes` | member-only trophies |
| GET | `/media/clubs/{asset}?expires=&signature=` | signed media (web route) |

There is deliberately **no** endpoint that lists or searches clubs the player has not joined.

Limiter `club-join`: 10/min per user, 30/hour per IP, plus a failure-only counter (15 failed codes/hour/user → `club_join_locked`).
It goes into `fail_closed` and `AuthAbuseGuard::throttleContext()`.

### 7.2 Admin web (`/admin/clubs…`, `admin` middleware + policies)
| Route | Purpose |
|---|---|
| `GET /admin/clubs` | index: `q`, `status[]`, `sport`, `type`, `country`, `has_active_competition`, `has_pending_photos`, `created_from/to`; `sort` name\|created\|members\|activity; `per_page` 25/50/100 |
| `GET/POST /admin/clubs/create` | create (identity, location, contact, settings, first owner by username/email optional) |
| `GET /admin/clubs/{club}` | detail, tab via `?tab=overview\|members\|codes\|packs\|competitions\|photos\|settings\|audit` |
| `GET/PUT /admin/clubs/{club}/edit` | edit identity/location/contact (optimistic version) |
| `PUT /admin/clubs/{club}/settings` | settings |
| `POST /admin/clubs/{club}/suspend\|archive\|reactivate` | reason required for suspend/archive |
| `DELETE /admin/clubs/{club}` | only if no history (`club_has_history` otherwise) |
| `GET /admin/clubs/{club}/members` | search username/email, filter role/status, paginate |
| `POST /admin/clubs/{club}/members` | add existing user by username/email |
| `PATCH /admin/clubs/{club}/members/{member}` | role change |
| `POST …/members/{member}/remove\|ban\|unban\|reactivate`, `POST …/transfer-ownership` | |
| `GET/POST /admin/clubs/{club}/codes`, `POST …/codes/{code}/revoke\|regenerate`, `GET …/codes/{code}/print` | QR print sheet |
| `/admin/packs?club=…` + `GET /admin/clubs/{club}/packs/create` | existing pack CRUD with a club preset; challenge picker filtered by invariant |
| `GET/POST /admin/clubs/{club}/competitions`, `GET/PUT …/{competition}` | editor with replay rules and lock-aware fields |
| `POST …/{competition}/publish\|cancel\|void\|extend\|rebuild` | rebuild supports dry-run |
| `POST …/{competition}/attempts/{attempt}/void` | reason required |
| `GET /admin/club-competitions` | cross-club list (phase, club, participants) |
| `GET /admin/club-photos` | global review queue (status, club, assignee, age) |
| `POST /admin/clubs/{club}/photos` | admin upload on behalf of a club |
| `POST /admin/club-photos/{s}/assign\|reject`, `GET …/{s}/prepare` | prepare = existing challenge form pre-filled (club, original photo); saving converts |
| `GET /admin/club-audit` | global audit log (filter club/action/actor/date) |

---

## 8. Central admin UX

### 8.1 Navigation
New `$navGroups` entry **Clubs** with second row: *Clubs* · *Competitions* · *Photo review* (pending-count badge) · *Audit log*.
The existing content screens get a **Scope: BallPicker / Club ▾** filter (default BallPicker) and a club badge column. The
subcategory type label `club` is renamed to "Team / club (content tag)" (value unchanged).

### 8.2 Clubs index
```
┌ Clubs ─────────────────────────────────────────────────────────────── [+ Create club] ┐
│ [🔍 Search name, slug, city, ref, ID, invite code, owner…        ] [Search]           │
│ Status [Active ▾] Sport [Any ▾] Type [Any ▾] Country [Any ▾]  ☐ Active competition    │
│ ☐ Pending photos   Created [from]–[to]          Sort [Last activity ▾]  Per page [25▾] │
├────────────────────────────────────────────────────────────────────────────────────────┤
│ Club                 Location        Sport  Status     Members  Packs  Comps  Activity  │
│ ◉ KFC Example        Gent, BE        ⚽     ● Active      214      6    1 live  2h ago ⋯│
│ ◉ VV Sample U15      Leuven, BE      ⚽     ◐ Suspended    48      2    —      12d    ⋯│
│ …                                                                                      │
├────────────────────────────────────────────────────────────────────────────────────────┤
│ Showing 1–25 of 312                                         ‹ 1 2 3 … 13 ›             │
└────────────────────────────────────────────────────────────────────────────────────────┘
⋯ = View · Edit · Suspend/Reactivate · Archive (confirm + reason)
```
Search semantics: name/city `LIKE %q%`; slug prefix; exact match on numeric id, `public_id`, `external_ref`, normalised invite code
and owner username/email. Empty results show "No clubs match" + clear-filters. Filters persist in the query string.

### 8.3 Club detail
```
┌ ◉ KFC Example  ● Active   Gent, BE · Sports club · ⚽ · created 2026-10-20 by admin  ┐
│ [Edit] [Suspend] [Archive]                                                           │
│ Overview | Members 214 | Invite codes 3 | Packs 6 | Competitions 4 | Photos 5 |      │
│ Settings | Audit                                                                     │
├──────────────────────────────────────────────────────────────────────────────────────┤
│ Overview: members (active 7d/30d, joins 30d, left 30d) · plays 7d · pack completions │
│ · live competition card (participants, ends in 3d) · photo queue · last audit events  │
└──────────────────────────────────────────────────────────────────────────────────────┘
Members tab:  [🔍 username/email] Role[▾] Status[▾]  [+ Add member]
              user · role ▾ · status · joined · via code · last seen · ⋯(remove/ban/unban/transfer)
Invite codes: [+ New code] label · ABCD-EFGH [copy] · uses 41/100 · expires 2026-12-31 · ⋯(print QR/revoke/regenerate)
Packs:        [+ New club pack] name · status · challenges · completions · linked to competitions · [edit]
Settings:     join enabled · max members · member list visibility · leaderboard identity · default replay mode · timezone
Audit:        time · actor · action · subject · details (filters: action, actor, date)
```

### 8.4 Competition editor
```
┌ Competition: Autumn Cup  ● Upcoming (starts 2026-11-01 10:00 Europe/Brussels) ┐
│ Name [Autumn Cup            ]  Description [...]                              │
│ Window  Starts [2026-11-01 10:00]  Ends [2026-11-30 22:00]  (club timezone)    │
│ Packs   [✓] Autumn Pack 1 (12)  [✓] Derby Pack (8)  [ ] Practice Pack (5)      │
│         ⚠ "Derby Pack" has 3 normal attempts in progress                       │
│ Replay rules                                                                  │
│   ( ) No replay — players who already completed a pack cannot play it         │
│   (•) One competition attempt per pack (recommended)                           │
│   ( ) Multiple attempts   Max [3 ▾]   Score counts: (•) Best ( ) First         │
│ Tie-break: total score → reached first → fewer attempts (shared rank if equal) │
│ 🔒 Rules lock automatically at the start time.                                  │
│ [Save draft] [Publish]                         [Cancel competition]           │
└────────────────────────────────────────────────────────────────────────────────┘
After start: rules shown read-only with 🔒; actions [Extend end] [Rebuild standings] [Void…];
Standings tab: rank · player · total · packs · attempts · reached at · [attempts ▸ void]
```

### 8.5 Photo review
```
Photo review   Status [Pending ▾] Club [Any ▾] Assignee [Me ▾]       oldest first
┌ thumb ┐ KFC Example · uploaded by club admin · 2026-10-21 · 3.1 MB 4032×3024 · rights ✓
└───────┘ Note: "derby goal min 89"     [Assign to me] [Prepare challenge →] [Reject…]
Prepare → existing challenge form, pre-filled (club, original photo); upload ball-removed image,
mark the ball → saves Challenge(club_id, usage_pool=club) → submission "converted" → [Add to pack ▾]
```

---

## 9. Mobile navigation & screens

### 9.1 Navigation
- 5 tabs: Play · Tournaments · **Clubs** · Friends · Profile (position: D13). The tab is shown only when `features.clubs` is true.
- Changes: `MainTabParamList.Clubs`, `'clubs'` icon in `TabIcon.tsx`, `nav.tabs.clubs` (EN Clubs · NL Clubs · FR Clubs · DE Vereine ·
  ES Clubes), and an update to the `AppNavigator.tsx` tab comment.
- Root-stack routes: `JoinClub`, `ScanClubCode`, `ClubDetail {clubId}`, `ClubPacks {clubId}`, `ClubCompetitions {clubId, phase}`,
  `ClubCompetition {clubId, competitionId}`, `ClubLeaderboard {clubId, competitionId?}`, `ClubMembers {clubId}`.
- Gameplay reuse: `PackGuessScreen`, `PackResultScreen` and `PackCompleteScreen` receive a play-session adapter
  `{kind: 'pack' | 'competition', attemptId, clubId?, competitionId?}` that selects the API calls. A competition complete view adds the
  counted score, attempts remaining and rank. `goPacks` becomes "return to origin".

### 9.2 My Clubs (tab)
```
┌ Clubs                                   [+ Join] ┐
│ [🔍 Search my clubs                         ✕]   │
│ ┌──────────────────────────────────────────────┐ │
│ │ ◉ KFC Example                 Owner ›        │ │
│ │   Gent · 6 packs · 1 live · 2 upcoming       │ │
│ └──────────────────────────────────────────────┘ │
│ ┌──────────────────────────────────────────────┐ │
│ │ ◉ VV Sample U15        Temporarily unavailable│ │
│ └──────────────────────────────────────────────┘ │
│   … loading more (spinner on end reached)        │
└──────────────────────────────────────────────────┘
```
- Data: `GET /api/clubs` with server-side `q`, cursor pagination (20 per page), `FlatList` `onEndReached`, pull-to-refresh, refresh on focus.
- Search: 300 ms debounce, stale responses discarded (request sequence id; `apiClient` gets optional `signal` support), current results
  kept while loading (no flicker), clear button. The search hint is "Search your clubs". It never queries clubs outside memberships.
- States:
  - initial **loading** skeleton cards
  - **empty** (no clubs): `EmptyState` "You haven't joined a club yet" + [Join with code] [Scan QR]
  - **no results**: "No joined clubs match '…'" + [Clear search] (no directory suggestion)
  - **error**: message from `getApiErrorMessage` + [Retry]; a pagination error shows an inline retry row, keeping loaded items
  - suspended club: dimmed card, opens a read-only detail
- `[+ Join]` (header) and the empty state open **JoinClubScreen**, a separate flow from search.

### 9.3 Join Club
```
┌ Join a club ─────────────────────────┐
│ Enter the invite code from your club  │
│ [ ABCD-EFGH ]  (auto-format, caps)    │
│ [Join club]                           │
│ ── or ──                              │
│ [📷 Scan QR code]                      │
└───────────────────────────────────────┘
```
`normaliseClubCode` / `parseClubCode` (accepts `ballpicker:club:CODE` or a bare code). Success → `replace('ClubDetail')`; already a
member → the same navigation. Errors map the codes in §6.3 to friendly text.

### 9.4 Club detail
```
┌ ‹  KFC Example                            ⋯ ┐   ⋯ = Members (if allowed) · Leave club
│ ◉  Gent, BE · Sports club · 214 members     │
│    You: Member                              │
├ Competitions ── [Active] [Upcoming] [Completed]
│ 🏆 Autumn Cup · ends in 3d · you #4 of 61 › │
├ Packs ──────────────────────────── See all ›│
│ ▣ Autumn Pack 1 · 8/12 · 640 pts            │
│ ▣ Derby Pack · 🔒 Competition only          │
├ Leaderboard ─────────────────────── See all ›│
│ 1 anna · 4,210  2 jules · 3,980  … you #12  │
└─────────────────────────────────────────────┘
```
Section lists are paginated ("See all" → full screens with cursor loading). The Leave action uses a `ConfirmModal`; a sole owner sees an
explanation instead.

### 9.5 Competition
```
┌ ‹  Autumn Cup                                ┐
│ Nov 1 10:00 – Nov 30 22:00 (Brussels) · ends in 3d │
│ Rules: Best of 3 attempts per pack counts    │
│ You: #4 of 61 · 2,140 pts                     │
├ Packs ────────────────────────────────────── │
│ Autumn Pack 1   counted 840 · best 840       │
│   Attempts 2/3  [Start attempt 3]            │
│ Derby Pack      In progress 4/8  [Continue]  │
├ Leaderboard ──────────────────── See all ›   │
│ 1 anna 2,960 · 2 jules 2,700 · 2 tom 2,700 … │
└──────────────────────────────────────────────┘
```
Button states come from `can_start` / `blocked_reason` / `active_attempt_id`. Attempt numbers are sent with the start request
(idempotent). An upcoming competition shows a countdown and the packs without play buttons. A completed one shows final placements.

### 9.6 Shared work
- `src/api/clubApi.ts`, `src/types/club.ts`, an `en/clubs.ts` namespace plus nl/fr/de/es. All new screens are added to the
  `LAUNCH_CRITICAL` i18n guard.
- A reusable `usePaginatedSearch` hook (debounce, cursor, stale-guard), with pure logic in `src/utils` so jest can test it.
- `LeaderboardList` / `YourPositionCard` are migrated to theme tokens and support shared ranks.
- Photo upload by club admins (phase 7) needs the iOS photo permission string updated and its guard test adjusted.

---

## 10. Future club self-service dashboard
- Web area `/club/{club:slug}` (separate from `/admin`), `web` session guard, `ClubPolicy` (owner/admin), **mandatory email 2FA**,
  its own login throttle.
- Uses the same services and FormRequests as the central admin: codes, members, photo submissions with status, packs from prepared
  challenges, competitions (rule changes only until lock), standings, CSV export (usernames only).
- Ball removal and positioning stay a BallPicker task until there is a moderation story. Platform admins can see every club's
  dashboard actions in the audit log.

---

## 11. Security & privacy
1. **Tenant isolation**: scoped bindings, 404 for non-members, policies in every controller, access gate in `PackPlayService`, service
   invariants, and a `ClubIsolationTest` matrix (2 clubs × roles × every endpoint).
2. **No directory / enumeration**: ULIDs, no search outside memberships, uniform invalid-code errors, failure-based join limiter,
   revocable/expiring codes, codes visible only to club admins and platform admins.
3. **Private images**: private disk, signed short-lived URLs, EXIF/GPS stripped, reveal only after the guess.
4. **Competition integrity**: server-only windows and limits, rule lock, snapshots, idempotent writes, void with reason + audit,
   rebuildable standings.
5. **Minors & consent**: minimum age 16 vs youth clubs (D7); a rights/consent confirmation for every upload; privacy policy and store age
   rating review.
6. **GDPR roles**: club terms/DPA for club-supplied photos and member lists (D8); contact fields are admin-only.
7. **Account deletion**: memberships → `removed`; codes created by the user stay valid with the creator nulled; uploader ids nulled;
   attempts/guesses kept against the anonymised user (like other gameplay); finishes anonymised; sole-owner fallback (D6). Extend
   `AccountDeletionTest`, `DataExportController` (`clubs`: memberships, roles, competition attempts, own submissions) and
   `privacy-data-inventory.md`.
8. **Visibility**: usernames + avatars only (D12); no club data in the public profile, the global badge catalogue or friend suggestions.
9. **UGC / store review**: names, descriptions and photos become user-generated only with self-service (phase 7), which then needs
   report/moderation tooling. Until then platform admins create and prepare everything.
10. **Audit**: `club_audit_events` for every mutation; admin actions also in `AppLog`.
11. **Backups**: include the private club media directory (the MySQL backup gap from the pre-release audit remains open).

---

## 12. Backward compatibility
- Additive migrations only; existing rows get `club_id = NULL`. No `migrate:fresh`, no data rewrites.
- Public pack, daily, tournament, XP, badge and leaderboard behaviour unchanged; response shapes unchanged; old app versions unaffected.
- `PackPlayService` public flow unchanged; existing tests pass without edits (phase-0 exit criterion).
- `features.clubs` off by default → dark deploy possible; admins can prepare clubs before the app update ships.

---

## 13. Acceptance tests
Backend feature tests (SQLite) unless noted. IDs are referenced in commits.

**Isolation & backward compatibility**
| ID | Given / When / Then |
|---|---|
| AT-ISO-01 | club pack exists → `GET /api/packs` and `GET /api/packs/{slug}` never return it (404 by slug) |
| AT-ISO-02 | club challenges are excluded from daily scheduling, tournament selection and public pack pickers; admin "set as daily" refuses |
| AT-ISO-03 | club badges are absent from `/api/badges`, `/me/badges` and other users' public profiles |
| AT-ISO-04 | outsider / member of club B → every club-A member endpoint returns 404 (matrix over routes) |
| AT-ISO-05 | competition/pack of club B addressed under club A → 404 (scoped bindings) |
| AT-ISO-06 | club media URL without a valid signature or expired → 403; non-member never receives a signed URL |
| AT-ISO-07 | removed member with an active attempt → next guess rejected |
| AT-BC-01 | the full existing suite passes unchanged with `features.clubs` on and off |

**Membership & codes**
| ID | Given / When / Then |
|---|---|
| AT-JOIN-01 | valid code in any case/with dash → joins; repeat → 200 idempotent, `uses_count` +1 only once |
| AT-JOIN-02 | revoked / expired / exhausted / unknown / join disabled → identical `club_code_invalid` response |
| AT-JOIN-03 | 15 failed codes → `club_join_locked`; per-IP limit enforced |
| AT-JOIN-04 | club at `max_members` / user at clubs cap → `club_full` / `club_membership_limit` |
| AT-JOIN-05 | banned user + valid code → refused; left user + valid code → reactivated membership |
| AT-JOIN-06 | sole owner leave → `club_owner_must_transfer` |

**My Clubs (API)**
| ID | Given / When / Then |
|---|---|
| AT-MY-01 | lists only active/suspended memberships of the caller; archived clubs excluded |
| AT-MY-02 | `q` matching a club the caller has not joined → empty result |
| AT-MY-03 | 120 memberships → cursor pages of 20, stable order, no duplicates or gaps across pages |
| AT-MY-04 | counts reflect active packs and upcoming/active published competitions only |

**Competition replay & scoring**
| ID | Given / When / Then |
|---|---|
| AT-CMP-01 | `no_replay`: player with a completed normal attempt → `can_start=false`, start → `competition_pack_already_played` |
| AT-CMP-02 | `single_attempt` (default): completed normal pack → competition attempt allowed; second attempt → `competition_attempt_limit` |
| AT-CMP-03 | `multiple_attempts` max 3: 4th start refused; parallel start while one active → returns the active attempt |
| AT-CMP-04 | `best`: scores 600, 840, 840 → counted 840 from attempt 2, `score_reached_at` of attempt 2 |
| AT-CMP-05 | `first`: scores 500, 900 → counted 500 |
| AT-CMP-06 | start before `starts_at` / guess at or after `ends_at` → rejected; partial attempt keeps its in-window score and becomes `expired` |
| AT-CMP-07 | start retried with the same `attempt_number` → same attempt id; concurrent starts → one attempt (unique constraint) |
| AT-CMP-08 | duplicate guess / retry after a timeout → stored result, `replayed: true`, totals unchanged |
| AT-CMP-09 | out-of-order challenge id → 422 `unexpected_challenge`, nothing stored |
| AT-CMP-10 | ties: equal totals → earlier `score_reached_at` ranks higher; fully equal → shared rank (1,2,2,4) and shared placement |
| AT-CMP-11 | after `starts_at`: changing replay mode / max / rule / packs / start → `competition_rules_locked`; extending `ends_at` allowed and audited; shortening refused |
| AT-CMP-12 | pack edited after lock → competition attempts use the snapshot |
| AT-CMP-13 | normal start on a pack linked to a published competition → `pack_reserved_for_competition`; allowed again after finalize |
| AT-CMP-14 | rebuild after a random sequence of starts/guesses/voids/extensions equals the incremental standings; dry-run reports no diff |
| AT-CMP-15 | voided attempt → removed from standings; `first` falls back to the next non-voided attempt; audit event written |
| AT-CMP-16 | finalize twice → one set of finishes (idempotent); finishes carry no global XP |

**Rewards**
| ID | Given / When / Then |
|---|---|
| AT-RWD-01 | first-ever run of a club pack (normal or competition) → standard per-guess + completion XP once |
| AT-RWD-02 | normal completion, then a competition attempt → competition attempt earns no XP, no completion reward, no badge evaluation |
| AT-RWD-03 | multiple attempts → only the rewarded run earns XP; `pack_master` / `pack_explorer` count each club pack once |
| AT-RWD-04 | concurrent first starts on normal and competition tracks → exactly one reward claim |
| AT-RWD-05 | public pack XP and badges identical to current behaviour (existing tests) |

**Central admin**
| ID | Given / When / Then |
|---|---|
| AT-ADM-01 | non-admin user → 403/redirect on every `/admin/clubs*` route; each action passes its policy |
| AT-ADM-02 | index search by name fragment, slug, city, external ref, `public_id`, invite code and owner email; filters combine; sort + paginate preserve the query string |
| AT-ADM-03 | suspend → members cannot play or join, standings read-only; reactivate restores; archive hides from My Clubs; each writes an audit event with reason |
| AT-ADM-04 | delete a club with history → `club_has_history`; empty club → deleted |
| AT-ADM-05 | member add/role/remove/ban/unban/transfer-ownership validated (can't remove the last owner) and audited |
| AT-ADM-06 | stale `version` on edit → `stale_version`, no overwrite |
| AT-ADM-07 | linking a pack from another club or a public pack to a competition → validation error |
| AT-ADM-08 | photo submission → assign → prepare → challenge with `club_id` and `usage_pool=club` → submission `converted`; reject stores the reason |
| AT-ADM-09 | pinned admin strings + Playwright screenshots for index, detail tabs, competition editor (locked and unlocked), photo review |

**Privacy**
| ID | Given / When / Then |
|---|---|
| AT-PRV-01 | account deletion → memberships `removed`, uploader nulled, finishes anonymised, nothing club-related in the public profile |
| AT-PRV-02 | data export contains own memberships, competition attempts and submissions; never other members' emails or invite codes |

**Mobile (jest + guards)**
| ID | What |
|---|---|
| AT-MOB-01 | `normaliseClubCode` / `parseClubCode` cases |
| AT-MOB-02 | `usePaginatedSearch` logic: debounce, stale response ignored, cursor append, error-keeps-items |
| AT-MOB-03 | competition button-state mapping from `can_start` / `blocked_reason` / `active_attempt_id` |
| AT-MOB-04 | shared-rank rendering helper |
| AT-MOB-05 | i18n guard covers all Clubs screens; `tsc` passes for 5 locales |
| AT-MOB-06 | manual QA on a dev build: tab hidden when the flag is off; join → detail → normal pack → competition attempt → leaderboard |

---

## 14. Phased roadmap
| Phase | Scope | Exit criteria |
|---|---|---|
| **0 — Foundations & hardening** | config + flag, enums, base tables (`clubs`, members, codes, `club_id` columns, audit), policies + `Gate::before`, `ClubDomainException`, `PackAccessGate`, gaps G1–G7, `ChallengeImageUrl`, private disk | AT-ISO-*, AT-BC-01 green |
| **1 — Central admin: clubs & membership** | Clubs nav group, index (search/filter/sort/paginate), create/edit/settings, suspend/archive/reactivate/delete-if-empty, members, invite codes + QR print, audit log, overview stats, account deletion/export/inventory | AT-ADM-01…06, AT-JOIN-*, AT-PRV-* |
| **2 — Club packs & media** | club challenges/packs in admin (private disk), scope filters on content screens, normal club pack play API, reward claims for club packs | AT-RWD-01, 05; ISO for packs |
| **3 — Mobile MVP** | Clubs tab (flagged), My Clubs (search + cursor + states), Join (code + QR), club detail (header, packs, leaderboard, members/leave), normal club pack play, i18n ×5 | AT-MY-*, AT-MOB-01/02/05/06 |
| **4 — Competitions backend + admin** | competition tables, replay policies, play service, standings recompute, rule lock + snapshot, tick/finalize/rebuild commands, admin editor + standings + void/extend/rebuild | AT-CMP-*, AT-RWD-02…04, AT-ADM-07 |
| **5 — Mobile competitions** | competitions section and screens, competition attempt flow through the play-session adapter, competition leaderboard with shared ranks, club trophies | AT-MOB-03/04, manual QA |
| **6 — Photo workflow** | submissions, admin upload on behalf of clubs, review queue, prepare → convert, reject | AT-ADM-08 |
| **7 — Club self-service** | club-admin API + web dashboard, 2FA, codes/members/photos/competitions by club admins, UGC reporting, member notifications | role-matrix tests |
| **8 — Later** | invite deep links/universal links, push for competition start/end, analytics, multi-sport clubs | |

Each phase is a series of reviewable commits on `feature/ballpicker-clubs`. Deploy = `migrate` (+ seeders if badges are added); mobile
phases need an EAS build. Neither happens without an explicit go-ahead.

---

## 15. Risks
| Risk | Mitigation |
|---|---|
| Club content leaking into public surfaces | phase-0 hardening first, isolation matrix, restrict FKs, admin default scope |
| Duplicate XP through replays | single reward-claim row per player × pack (unique) + ledger unique index |
| Races on SQLite not exercised (`lockForUpdate` no-op) | unique constraints as the real guard, explicit race tests via constraint violation, optional MySQL CI job |
| Unfair rule changes | service-level lock at `starts_at`, snapshots, audited extensions only |
| Standings drift | recompute-from-source per guess + rebuild command + equality test |
| Private image serving cost | signed URLs with caching, X-Accel-Redirect later |
| Admin index performance at scale | denormalised counters + indexes; FULLTEXT on MySQL if needed |
| 5 tabs / long labels | short labels in 5 locales, screenshot QA |
| Scope creep into self-service before moderation | self-service gated to phase 7 |

---

## 16. Decisions

### Confirmed
- **Sprint decisions 2026-10-19** — all remaining decisions are resolved for the implementation sprint; see `docs/clubs-implementation-contracts.md` §1 (D15 is configurable per competition via `normal_play_policy`, default `blocked`).
- **D2** — replay rules configurable per competition: no replay / one competition attempt (default) / multiple attempts with max and
  first/best (§5).
- **D1 (launch scope)** — platform admins create and manage clubs centrally; self-service creation is not in scope (phase 7 covers
  self-service *management* only).
- **D13 (partial)** — Clubs is a 5th main tab; no public directory; My Clubs search and Join are separate.
- No hard deletion of clubs with gameplay history.

### Remaining — critical before phase 4 (competitions)
- **D3 Rewards** — the proposed rule: global XP once per player per club pack (first run in any track); competition replays/extra
  attempts earn nothing global; competition placements give **club-only trophies, no global XP**. Confirm, or should placements give XP?
- **D15 Normal play of linked packs** — block normal play from publish to finalize (recommended), or allow it (players could preview photos)?
- **D16 Partial attempts** — does an attempt still running at `ends_at` count its partial score (recommended), or only completed attempts?
- **D17 Tie-break order** — total → reached first → fewer attempts → shared rank (recommended). Alternative: fewer attempts before time.
- **D20 Extending `ends_at` after start** — allow extension only, audited (recommended), or fully locked?
- **D22 Removed/left members** — hide from live standings but keep their attempts for rebuild/finishes (recommended)?

### Remaining — before phases 1–3
- **D4** May club packs include public BallPicker challenges? (recommended yes; club photos never go public without permission)
- **D5** Roles owner / admin / member sufficient?
- **D6** Sole owner leaves/deletes account → platform-admin fallback (recommended).
- **D7** Minimum age 16 vs youth clubs — keep 16, or a separate consent model?
- **D8** Legal: club terms/DPA for photos and member lists.
- **D9** Member list visible to ordinary members? (setting per club; default: count only, list for admins)
- **D10** Friend suggestions from club co-membership: off (recommended).
- **D11** Limits: max members per club (500), **max clubs per player (raised to 50** given "many clubs"), max active codes per club (20) —
  all configurable.
- **D12** Leaderboard identity: usernames only (recommended), or club-configurable real names?
- **D13 (rest)** Tab position: 3rd (between Tournaments and Friends, recommended) or 5th (last)?
- **D14** Paid club plans out of scope — confirm.
- **D18** Archived clubs: hidden from My Clubs (recommended) or shown read-only under "Archived"?
- **D19** Club all-time leaderboard definition: sum of each member's best score per club pack across both tracks (recommended), or
  competition points only?
- **D21** Do all `is_admin` users get full club management, or do we need a narrower "club support" admin role now?
- **D23** Banned user entering a valid code: generic `club_code_invalid` (recommended, no signal) or an explicit `club_banned`?
- **D24** Club logos: public disk with random names (recommended) or private signed URLs like photos?
