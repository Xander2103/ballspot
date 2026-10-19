# BallPicker Clubs — staging & real-device QA plan

Status: **prepared, not provisioned.** Nothing has been started, migrated, seeded, built, pushed or deployed.
Branch `feature/ballpicker-clubs` @ `5222932`, 14 commits ahead of `master` = `origin/master` = `10e020b`, nothing pushed.
Companion docs:
- `docs/clubs-staging-runbook.md`: deploy, verify, rollback and teardown commands.
- `docs/clubs-device-qa-checklist.md`: iOS and Android test cases.
- `docs/clubs-staging-seed.md`: the guarded QA data seeder.

---

## 1. Local dev database: recovery findings

**Conclusion: no data was lost, so there is nothing to recover.** The "wiped" `backend/database/database.sqlite` did not exist
before the incident. Wave A's `migrate:fresh` *created* it.

Evidence:
| Source | Finding |
|---|---|
| Filesystem | `database.sqlite` birth time **2026-10-19 00:31:52 CEST**, modified 00:31:53 |
| Wave A agent log | `php artisan migrate:fresh --database=sqlite --env=testing --force` executed at **22:31:52Z** (= 00:31:52 CEST): the same second |
| Laravel code | on SQLite, `migrate:fresh` empties an existing file in place (`SQLiteBuilder::refreshDatabaseFile` → `file_put_contents($path, '')`), which keeps the original birth time. A birth time equal to the wipe therefore means the file was newly created |
| Session 2026-10-14 (admin UX sprint) | repeated `QueryException: Database file at path [.../database.sqlite] does not exist` |
| Session 2026-10-14 (final release audit) | "`php artisan migrate:status` — Not runnable locally (no `database.sqlite`)" |
| Session 2026-10-16 (Pack Start 500) | "`backend/database/database.sqlite` does NOT exist locally" |
| Backups | `backups/` empty; no APFS local snapshots; no Time Machine destination configured |

The file that exists now is an empty schema (62 migrations, batch 1) with no users and no content. It holds no data, so no action
is needed. You may delete it, or keep it if you want a local dev DB; that is your call. Correction to the earlier sprint report:
the incident was a breach of the no-`migrate:fresh` rule, not a data loss.

## 2. Staging options

| Option | Isolation | Production parity | Effort / cost | Verdict |
|---|---|---|---|---|
| **A. Local Docker stack on this Mac** (`staging/`: MariaDB, PHP 8.3-FPM, nginx, scheduler, Mailpit) + optional HTTPS tunnel for devices | Full: own DB volume, own storage volume, mail sink, own APP_KEY, nothing shares a host or credentials with production | Good: same PHP minor, same nginx layout, DB engine pinned to prod's version, real scheduler | Low; free; ready to start | **Recommended now** for device QA |
| **B. Separate staging VPS** (e.g. `staging.ballpicker.vanmalderstudio.be`, own server, own DB, own TLS) | Full, if it uses separate credentials and DNS | Best: same OS, users (`deploy`/`www-data`), deploy wrapper, cron, `fix-permissions.sh` | Medium; small monthly cost; DNS + TLS + server setup | **Recommended before the production release**, to rehearse the deploy procedure |
| C. Second vhost/DB on the production VPS | Weak: shared php-fpm user, cron, disk and deploy scripts that default to production paths | High | Low | **Not acceptable** |
| D. Expo Go + `php artisan serve` | Partial: Expo Go lacks the custom native config and push | Low | Lowest | Not sufficient (no push, no dev-client parity) |

Device connectivity for option A:
- **HTTPS tunnel** (`--profile tunnel`, Cloudflare quick tunnel) gives a random `https://….trycloudflare.com` URL that iOS
  accepts. Test traffic transits Cloudflare. The URL is public but unguessable. New registrations are gated by
  `BALLPICKER_BETA_CODE`, and the DB holds QA data only.
- Alternatively **LAN http** (`http://<mac-ip>:8088`) works on Android debug builds and possibly iOS debug builds. It is not
  representative of production (ATS/TLS), so use the tunnel for sign-off.

## 3. Isolation guarantees (what could reach production or real people, and how it is closed)

| Channel | Risk | Control in staging |
|---|---|---|
| Database | staging writing to prod DB | compose `db` service only; `DB_HOST=db`, DB name `ballpicker_staging`; prod credentials never copied; the seeder refuses non-`staging` DB names, prod `APP_URL`s and any non-QA user |
| Files | prod storage / backups | own `staging_storage` volume; code from a dedicated git worktree; no backup/monitor scripts installed (they default to `/var/www/ballpicker` and the prod health URL) |
| Mail | real recipients (verification, reset, **portal 2FA**, ops alerts) | `MAIL_MAILER=smtp` → Mailpit (`mailpit:1025`, UI 127.0.0.1:8025); `BALLPICKER_ALERT_EMAIL=ops@staging.ballpicker.test` (the code default is a **real** address: `info@vanmalderstudio.be`) |
| Push (Expo) | pushes to real users | staging DB holds only QA accounts, so only QA device tokens can exist; `BALLPICKER_PUSH_ENABLED=false` by default; switch on only for push sessions; daily reminder push off |
| Mobile app → backend | **a build without `EXPO_PUBLIC_API_BASE_URL` silently talks to PRODUCTION** (`src/utils/urls.ts` fallback); there is no `mobile/.env`, and the `preview` EAS profile points at production | always pass the staging URLs inline when starting Metro / building; verify the first request hits staging (§6 smoke step); recommended small change: an on-screen "STAGING" ribbon when the API host is not production (needs approval) |
| Same bundle id | a staging dev build replaces the store app on a phone; push tokens and the Expo project are shared | use **dedicated QA devices** (or uninstall the store app); a separate `.staging` bundle id would need `app.config.js` + new push credentials (decision) |
| Sessions / cookies | cookie scope | `SESSION_DOMAIN` empty (host-only), separate APP_KEY |
| Search engines | public tunnel indexed | nginx `X-Robots-Tag: noindex` |
| Scheduler | jobs acting on prod | runs inside the stack against the staging DB only |
| Monitoring | prod monitor polling staging, or the reverse | `monitor-health.sh` not installed on staging (its default target is the production health URL) |

## 4. Test data (staging only)
`database/seeders/ClubsStagingSeeder.php` (guarded, idempotent, never deletes) creates QA accounts (`qa_*`,
`@staging.ballpicker.test` = undeliverable `.test` TLD), the base content, five kinds of clubs (active, suspended, archived,
empty/needs-owner, 30 small clubs for search/pagination), invite codes in every state, private club challenges generated with GD,
packs, competitions in every phase (active multiple-attempts with a tie, upcoming, draft, finalized with trophies, cancelled), photo
submissions in every status and content reports.

- **Run only after approval.** Usage: `docs/clubs-staging-seed.md`.
- Passwords come from `BALLPICKER_STAGING_SEED_PASSWORD` (shared out of band).
- Invite codes are read in admin/portal, not printed by the seeder.

## 5. Implementation defects / gaps blocking device testing

No code defect was found that blocks device testing. All suites are green. These setup gaps must be closed first:

| # | Blocker | Fix | Needs approval |
|---|---|---|---|
| S1 | No staging backend exists | start the local stack (runbook §2) | yes: provisioning + migrations |
| S2 | Devices need HTTPS | tunnel profile (pulls `cloudflare/cloudflared`) or a staging VPS with TLS | yes |
| S3 | App defaults to PRODUCTION when no staging URL is set | inline `EXPO_PUBLIC_*` (runbook §5) + smoke check; optional STAGING ribbon | ribbon = small code change |
| S4 | No dev build on devices yet (no `ios/`/`android/`; CNG) | **local** dev builds with `npx expo run:ios --device` / `run:android` (Xcode 26.6 and the Android SDK are installed; `adb` is not on PATH) | yes: local build (not EAS) |
| S5 | **iOS push** needs APNs credentials for the build; a local Xcode build with a personal team cannot receive Expo pushes | push QA needs an **EAS development build** (existing EAS-managed credentials) | yes: EAS build is explicitly on hold |
| S6 | Production DB engine/version unknown here | read-only check on the server (`mysql --version`), then pin `STAGING_DB_IMAGE` | you (server access) |
| S7 | Hermes `Intl` time-zone support for competition times | verify on device (checklist C-07) | — |

Non-blocking tooling notes from `expo-doctor`, all pre-existing and not Clubs:
- Hermes V1 memory regression on SDK 56; the fix is in SDK 57.
- `typescript` and `@types/jest` major mismatches (dev-only).
- `eas-cli` is a project dependency.

## 6. Smoke checks right after staging starts (before any device test)
1. `curl -s https://<staging>/api/health` returns ok (the scheduler heartbeat may need ~5 min).
2. `curl -s https://<staging>/api/config` contains `"clubs":true`.
3. Mailpit UI shows the portal 2FA email after a portal login attempt with a QA owner.
4. A signed club image URL from `GET /api/clubs/{id}/packs/{slug}` starts with `https://<staging>/media/clubs/` and loads. The
   same URL without its signature is refused with 403.
5. On the device, the first app request appears in the staging nginx log, not in production.

## 7. Release blockers (prioritised)

| Priority | Item | Owner |
|---|---|---|
| **P0** | Real-device QA pass (checklist) on iOS + Android against staging, incl. push and photo upload | QA |
| **P0** | Deploy rehearsal on a production-like environment (option B): backup → migrate 18 migrations → `fix-permissions.sh` → scheduler → flag on → smoke → rollback rehearsal | ops |
| **P0** | Privacy policy + store privacy answers updated (club membership, club photos, content reports, club push notifications); club terms / photo consent wording (D8) | product/legal |
| **P0** | Production outbound mail verified (portal 2FA depends on it) and Expo push config verified | ops |
| P1 | Youth-club question vs minimum age 16 (D7) — confirm policy before onboarding clubs | product |
| P1 | Store review: new tab, camera/photo-library purpose strings, user-generated photos + report flow (Apple 1.2) | product |
| P1 | Decide the staging bundle id strategy (dedicated devices vs `.staging` variant) and the STAGING ribbon | product/dev |
| P1 | Production DB engine/version confirmed; MariaDB/MySQL-specific: league leaderboard `total_score` returned as a string (pre-existing) | dev |
| P2 | Pre-existing `2026_07_30_000001` migration `down()` fails on MySQL (rollback path only) | dev |
| P2 | Hermes V1 memory regression on SDK 56 (pre-existing; SDK 57 upgrade is its own project) | dev |
| P2 | Admin/portal UX polish noted by QA (timezone labels, phase colours, mobile tables, name-lock consistency) | dev |
| P3 | Invite-code list cursor can skip/repeat an expiring code once (display only) | dev |

## 8. Decisions requested
1. Approve **option A** (local Docker staging) now, and option B before release?
2. Approve the **Cloudflare quick tunnel** for device HTTPS, or provide a staging hostname/VPS instead?
3. Approve **local dev builds** (`expo run:ios/android`, not EAS) for the non-push device QA?
4. Approve one **EAS development build** later, for push QA only?
5. Dedicated QA devices, or a separate `.staging` bundle id?
6. Approve the optional **STAGING ribbon** (small mobile change)?
7. Production DB engine/version, so the staging image can be pinned.
