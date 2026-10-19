# BallPicker Clubs — staging QA seed (`ClubsStagingSeeder`)

`backend/database/seeders/ClubsStagingSeeder.php` (+ `database/seeders/Staging/`) fills a **staging** database with QA data for
Clubs device and admin testing. **Never run it on production.** It refuses on its own unless every guard below holds, but the
guard is the last line of defence, not a substitute for running it in the right place (`docs/clubs-staging-runbook.md` §2f).

## 1. Guard: all conditions must hold, or the seeder refuses and writes nothing

| # | Condition | Why |
|---|---|---|
| 1 | `APP_ENV=staging` | not local, testing or production |
| 2 | `BALLPICKER_STAGING_SEED_CONFIRM=yes` | an explicit opt-in per run |
| 3 | The default DB connection's database name contains `staging`. For sqlite, the file path must contain it. | e.g. `ballpicker_staging` |
| 4 | `APP_URL` does not contain `ballpicker.vanmalderstudio.be`, unless the host is exactly `staging.ballpicker.vanmalderstudio.be` | the production host is refused |
| 5 | The `users` table exists, and every account in it has an email ending in `@staging.ballpicker.test` | a copy of real data is refused |
| 6 | `BALLPICKER_STAGING_SEED_PASSWORD` is set, with at least 12 characters | the password for every QA account; there is no default and it is never printed |

A refusal prints every failed condition and exits non-zero (`RuntimeException`). It writes nothing.

Condition 5 means: seed **before** anyone creates a personal account on staging. Platform admin access uses `qa_admin`. If
someone registered with a real address, the seeder refuses. That is by design: use a fresh staging DB (§5).

**Dry run:** `BALLPICKER_STAGING_SEED_DRY_RUN=yes` checks the guard and prints the plan (counts) only.

## 2. Run (inside the staging `app` container, after `migrate --force`)

```bash
export DC="docker compose -f staging/docker-compose.yml --env-file staging/.env"
# The staging backend .env sets APP_ENV=staging, DB_DATABASE=ballpicker_staging,
# BALLPICKER_STAGING_SEED_CONFIRM=yes and BALLPICKER_STAGING_SEED_PASSWORD (shared with testers out of band).
$DC exec -e BALLPICKER_STAGING_SEED_DRY_RUN=yes app php artisan db:seed --class=Database\\Seeders\\ClubsStagingSeeder --force
$DC exec app php artisan db:seed --class=Database\\Seeders\\ClubsStagingSeeder --force
```

`--force` is needed only because `db:seed` asks for confirmation outside local. It does not bypass the guard.

While the seeder runs:
- `ballspot.notifications.push_enabled` is forced to `false`.
- `Mail`, `Notification` and `Http` are faked, so no mail, push or outbound HTTP is sent.
- The Clubs flag is forced on in memory only, because club pack play needs it. The `.env` is not changed.
- Everything runs in **one DB transaction**. A failure leaves no partial rows. Image files written before the failure may stay
  behind as orphans in `storage/app/private/clubs`.
- The history is written on a moving clock (Carbon test-now), so timestamps look real. The clock is restored at the end.

Output: the counts, the QA usernames and the club names. Passwords and invite codes are **not** printed. Testers read the codes
in `/admin` (club → Codes) or in the club portal.

## 3. What it creates

Everything goes through the real services: `ClubService`, joins via `ClubMembershipService::joinWithCode`,
`ClubInviteCodeService`, `ChallengeCreator`/`ClubMediaService`, `ClubCompetitionService` / `PlayService` / `CloseService`,
`PackPlayService`, `ClubPhotoReviewService` and `ClubContentReportService`. So the audit trail, counters, standings and finishes are
real. Club packs have no service. They are created the way `Admin\ChallengePackController::store` does it: row, pivot and a
`pack.created` / `pack.archived` audit event.

**Base content:** Sport, ChallengeCategory, Challenge (demo images on the public disk) and Badge seeders. Each one runs only if its
data is missing.

**Accounts (24):** the email is `<username>@staging.ballpicker.test`. All accounts are verified and have accepted the terms.
Languages are spread over en/nl/fr/de/es.

| Username | Role in the data |
|---|---|
| `qa_admin` | platform admin (`is_admin`), creates clubs, reviews photos, resolves reports |
| `qa_owner_alpha` | owner of QA FC Alpha (portal 2FA mail goes to Mailpit) |
| `qa_admin_alpha` | club admin of Alpha (joined with the admin-granting code) |
| `qa_owner_beta` | owner of QA Beta Hockey, QA Gamma School and the 30 small clubs |
| `qa_player_01` … `qa_player_12` | Alpha members (01 is also in Beta and Gamma; 02 is also in Beta) |
| `qa_nl`, `qa_fr`, `qa_de`, `qa_es` | Alpha members in nl/fr/de/es (nl and fr used up the exhausted code) |
| `qa_outsider` | in no club: expect 404 on every club route |
| `qa_banned` | banned from Alpha: every code answers `club_code_invalid` |
| `qa_left` | left Alpha: can re-join with the member code |
| `qa_many_clubs` | member of 30 small clubs (admin in 5 of them) + suspended Beta: My Clubs search/pagination |

**Clubs (34):**
- **QA FC Alpha:** active, Gent BE, football, member list visible to members, 18 active members.
- **QA Beta Hockey:** suspended. Shown flagged and read-only.
- **QA Gamma School:** archived. Hidden from My Clubs.
- **QA Delta Empty:** active, no owner (`needs_owner`), no packs or competitions, one usable code.
- **QA Club 01 … 30:** small clubs in 30 different BE/NL/FR/DE/ES cities. Try searching `Sevilla` → QA Club 28.

**Alpha invite codes (5):**
- usable member code;
- usable admin-granting code (max 5 uses);
- expired;
- revoked;
- exhausted (max 2 uses, used 2 times).

**Alpha content:**
- 15 GD-generated club challenges (`QA Pitch 01…15`). Each is a synthetic pitch with a white ball at a seeded position; the hidden
  image is the same picture without the ball. They are stored on the private `club_private` disk with exact ball ratios.
- One extra challenge was converted from a photo.
- Packs:
  - **QA Training Ground** (4, normal play);
  - **QA Derby Pack** (live competition);
  - **QA Cup Final** (upcoming competition);
  - **QA Archive Pack** (archived);
  - **QA Mixed Pack** (2 club challenges + 1 BallPicker challenge, D4; used by the finalized competition).

**Competitions on Alpha.** The windows are relative to the first run.

| Name | Phase | Rules | Data |
|---|---|---|---|
| QA Derby Days | active (day −2 … +12) | multiple attempts, max 3, best; normal play blocked | standings 1 · **2 · 2 (tie: qa_player_02/03)** · 4 …; `qa_player_05` at the attempt limit; `qa_player_04` has an attempt in progress |
| QA Cup Final | upcoming (starts in 2 days) | single attempt, blocked | published |
| QA Winter League (draft) | draft | single attempt | Training Ground + Cup Final |
| QA Autumn Classic | finalized (played 15 days ago) | single attempt, blocked | finishes / club trophies with a **shared 1st place** (qa_player_07/08) |
| QA Friendly (cancelled) | cancelled | single attempt, normal play allowed | published, then cancelled |

**Normal play:** 14 players completed QA Training Ground, so the club all-time leaderboard is filled. `qa_player_11` stopped half
way and can resume. `qa_banned` and `qa_left` played too; they are hidden from the live boards (D22).

**Photos (Alpha):** there is one submission in each status, all uploaded by the owner. The converted one is linked to its challenge.
The rejected one has a reason.

**Reports:** one open report (`qa_player_09` on QA Derby Days) and one dismissed report (`qa_player_10` on a member).

**Audit:** about 185 club audit events come from the services.

**Side effects on non-club data:** QA accounts earn XP and global badges from their rewarded club runs. These show on staging's
global boards. Public challenges and packs are not changed beyond the base content seeders.

## 4. Re-running

The seeder is idempotent and never deletes or updates existing rows. Each part is keyed by one of: username, slug, code label,
challenge title, competition name or photo note. A part that already exists is skipped. A second run therefore adds nothing, which
has these consequences:
- **Passwords are not reset.** A changed `BALLPICKER_STAGING_SEED_PASSWORD` only affects accounts that do not exist yet.
- **Time-relative data is not refreshed.** After 12 days the "active" competition ends; the scheduler finalizes it. After 2 days
  "upcoming" becomes active.

For a fresh timeline, recreate the staging DB (§5) and seed again.

## 5. Removing the data

Drop the **staging** database and the staging storage, for example `docs/clubs-staging-runbook.md` §7 (delete the staging Docker
volume). Then migrate and seed again. The seeder never deletes anything, and there is no "unseed".
**Never do this on production.**

## 6. Tests

`backend/tests/Feature/Clubs/Staging/ClubsStagingSeederTest.php` uses SQLite in memory, with `Storage::fake` on public and
club_private and Mail/Notification/Http faked. It covers:
- every guard refusal writes nothing;
- the dry run;
- a full seed with the expected counts, the tie, the shared placement and My Clubs pagination for `qa_many_clubs`;
- club images only on `club_private`, and no mail, push or HTTP;
- a second run that adds nothing.

The in-memory database has no name, so the test overrides the seeder's protected `databaseName()` seam. There is deliberately no
env or config switch for condition 3.
