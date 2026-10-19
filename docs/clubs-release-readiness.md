# BallPicker Clubs — implementation & release-readiness report

Branch `feature/ballpicker-clubs` · base `master` = `10e020b` · sprint 2026-10-19/20.
**Not pushed, not merged, not deployed. No EAS build. No production data or storage touched.**
Specs: `docs/clubs-architecture-plan.md` (what) and `docs/clubs-implementation-contracts.md` (names, contracts, as-built notes).

## 1. Verdict

Phases 0–7 of the plan are implemented, integrated and covered by automated tests on SQLite and MariaDB 11.4.
**Ready for staging / device QA, not yet for production.** What still blocks a production release:
- manual device testing of the app (§9);
- a production-like dry run of the deploy steps (§10);
- the legal/privacy items in §8.

## 2. Waves and agents

| Wave | Agent (logical role) | Delivered |
|---|---|---|
| 0 | Orchestrator | ownership map, binding API contracts, sprint decisions (D1–D24) |
| A | Backend Foundation (+ Security baseline) | all schema, models, enums, config/flag, errors ×5 languages, policies, membership/invite/audit/media services, private signed media, public-surface hardening |
| B1 | Member API | My Clubs, join/leave, detail, members, club packs (reuse of the pack engine), club leaderboard, reward dedup, privacy |
| B2 | Central Admin | Clubs nav group, index/search/filters, CRUD, status actions, members, codes + QR print, packs/challenges club scope, settings, audit, stats |
| B3 / C2 / F2 | Mobile | Clubs tab, My Clubs, join (code + QR), club detail, club pack play, competitions, leaderboards, club admin, reports, notifications |
| C1 | Competitions | replay modes, separate attempts, standings, rule lock + snapshots, tick/finalize/rebuild, member API, admin editor |
| D1 | Media / Photos | submission workflow, review queue, prepare → convert into private club challenges |
| D2 | Club Portal | `/club` self-service with mandatory email 2FA |
| E | Security reviewer, Security fixes, Integration QA, Contract integration | security review + fixes, end-to-end journey test, visual QA (78 screenshots), mobile↔backend contract guard |
| F1 / F2 | Phase-7 completion | club-admin API, content reports + moderation queue, member push notifications, CSV exports |
| E/F | Orchestrator | integration fixes, MariaDB concurrency harness, migration rollback fix, Wave F review fixes, this report |

Agents worked in one tree with strict file ownership. The orchestrator verified and committed every wave.

## 3. Commits (on top of `10e020b`)

`fc98ac5` docs · `26def1f` foundations · `07ff5be` mobile MVP · `0076048` mobile competitions · `1fca9a0` central admin ·
`1a8ca27` member API · `f18b01a` competitions + photos + portal · `9e1fa56` concurrency harness · `5942353` Wave E hardening ·
`7c36d50` phase-7 contract · `eb2c750` mobile phase 7 · `edc6955` backend phase 7 · `48bc959` review fixes + rollback fix ·
plus the final commit with this report.

The total diff is about 460 files and about 50,900 insertions; about 15,500 of those lines are tests.

## 4. Features — status

| Area | Status | Notes |
|---|---|---|
| Clubs CRUD, suspend/archive/reactivate, delete-if-empty | ✅ complete | restrict FKs, so a club with history can never be hard-deleted |
| Roles owner/admin/member, multi-club membership, permissions | ✅ complete | policies + one shared role-rule class (`ClubPortalMemberRules`) for portal and API |
| Invite codes (expiry, revoke, max uses, QR, failure lock) | ✅ complete | 8-char unambiguous codes; uniform invalid error |
| Audit trail | ✅ complete | every mutation, inside its transaction; staff are anonymous towards clubs |
| Central admin (index, tabs, members, codes, packs, competitions, photos, reports, settings, audit, stats, CSV) | ✅ complete | visual QA done on rendered pages |
| Private club packs (reuse of the pack engine) + club progress | ✅ complete | public pack behaviour unchanged |
| Rewards without duplication | ✅ complete | one rewarded run per player × club pack; competition placements give club trophies only |
| Competitions: 3 replay modes, first/best, windows, idempotency, lock + snapshots, ties, finalize, void, extend, rebuild | ✅ complete | incremental standings = rebuild (randomized test); MariaDB concurrency verified |
| Configurable normal play during competitions (`normal_play_policy`) | ✅ complete | default `blocked` |
| Club leaderboards (all-time + per competition, shared ranks) | ✅ complete | |
| Photo workflow (upload → review → manual ball removal → convert → pack) | ✅ complete | private disk, EXIF stripped, signed URLs |
| Club self-service portal with mandatory 2FA | ✅ complete | needs working outbound mail in production |
| Club-admin features in the app (codes, members, photos) | ✅ built, ⚠ device-untested | |
| Content reports + admin moderation queue | ✅ complete | reporter never visible to the club |
| Member push notifications (published / started / finalized) | ✅ built, ⚠ device-untested | Expo is faked in tests; per-club daily cap |
| Mobile Clubs tab (3rd), My Clubs search + pagination, join code/QR, club detail, competitions, leaderboards, trophies | ✅ built, ⚠ device-untested | jest + tsc + contract guard; never run on a device or against a live server |
| Five languages (en/nl/fr/de/es) | ✅ backend + mobile + portal | admin stays English like the rest of the admin |
| Invite deep links / universal links, analytics, AI ball detection | ❌ out of scope (phase 8) | |

## 5. Database migrations (18, all additive)

`2026_10_20_000001`–`000014`: clubs, club_invite_codes, club_members, `club_id` on challenge_packs/challenges/badges (+ index
`pack_attempts(challenge_pack_id,status)`), club_competitions, club_competition_packs, club_competition_attempts (one-active-attempt
unique trick), club_competition_attempt_guesses, club_competition_pack_results, club_competition_entries, club_competition_finishes,
club_pack_reward_claims, club_photo_submissions, club_audit_events · `2026_10_21_000001` club_portal_login_codes ·
`2026_10_22_000001` club_content_reports · `2026_10_22_000002` notification_settings.club_updates_enabled · `2026_10_22_000003`
club_competitions.notified_*_at (backfilled so the first tick after deploy sends no old news).

Verification:
- On MariaDB 11.4 and SQLite: fresh migrate, then rollback of all 18 with rows present, then re-migrate.
- One MySQL-only rollback bug (error 1553) was found and fixed.
- The pre-sprint migration `2026_07_30_000001` has the same MySQL `down()` flaw; it was not changed.

## 6. Endpoints and screens

**Member API (27 routes):** `/api/clubs` (My Clubs search/cursor), `join`, `{club}`, `leave`, `members` (+ role/remove/ban),
`invite-codes` (+ revoke), `photos`, `reports`, `packs` (+ detail/start/attempt), `leaderboard`, `competitions` (+ detail,
attempts start, leaderboard, me/attempts), `/api/club-competition-attempts/{attempt}` (+ guess), `/api/me/club-finishes`.

Additive changes to existing endpoints:
- `GET /api/config` gains `features.clubs`.
- `GET|PUT /api/me/notification-settings` gains `club_updates_enabled`.
- The pack guess endpoint is now access-gated for club packs.

**Admin:**
- Clubs index, create/edit, club detail tabs (overview, members, codes, packs, competitions, photos, settings, audit).
- Invite-code print sheet.
- Cross-club competitions list and competition editor/standings.
- Photo review queue and prepare page.
- Reports queue and global audit log.
- CSV exports.
- Club scope on the existing challenge, pack and media-export screens.

**Portal (`/club`):** login + emailed code, club switcher, dashboard, members, invite codes (+ print), photos, leaderboard (+ CSV),
competitions (list/create/edit/publish/cancel/extend, standings CSV), settings, audit.

**Mobile:**
- Clubs tab, Join, Scan club code, Club detail.
- Club packs (reusing the existing pack play screens).
- Competitions list, competition detail, competition complete view.
- Club/competition leaderboards, Members, Invites, Photos, Report.
- Club trophies in the Trophy Room, the Club updates toggle, and push-tap routing.

## 7. Verification

| Check | Result |
|---|---|
| Backend full suite (SQLite) | **1631 passed, 1 skipped** (baseline 1074 passed / 1 skipped; the skip is pre-existing) |
| Clubs suites on MariaDB 11.4 | **556 passed, 0 failed** |
| Full feature suite on MariaDB | all Clubs tests pass. 3 failures are pre-existing on unchanged master files: 2 tests using SQLite-only triggers, and `LeaderboardTest` — on MariaDB the league leaderboard serialises `total_score` as a string, a real pre-existing production quirk |
| Concurrency harness (parallel processes on MariaDB) | **7/7**: join caps, code max uses, duplicate joins, parallel competition starts, attempt limits, duplicate guesses + standings, first-run reward claim across both tracks |
| End-to-end journey test | admin creates club → code → player joins/finds/opens → plays pack → competition (blocked normal play, lock, play, shared ranks, finalize, trophy) → portal 2FA + photo upload → admin converts → challenge playable in a club pack; outsiders 404 everywhere |
| Mobile | jest **511 passed** (baseline 293), `tsc` clean, 5 locales type-checked, i18n guards |
| Mobile ↔ backend contract | `MobileContractShapeTest` + `ClubAdminContractShapeTest` (every endpoint, pagination chains, error codes) |
| `git diff --check` | clean |
| Security | 2 independent reviews. Findings fixed: 1 high (competition answer key via parallel tracks), 1 medium (edits to locked content), 1 medium (push spam), several low. No tenant-isolation or auth bypass found |
| Visual QA | 78 screenshots of admin + portal (desktop, 390 px); defects fixed |

**Competition scoring:** tests cover all three replay modes, first vs best, equal best scores (the earlier one counts), partial
attempts, the window edges, voided attempts, the tie-break order (total → reached first → fewer attempts → shared rank),
lock immutability, snapshots, idempotent finalize, and rebuild equality.

**Media & privacy:**
- Club images live only on the private disk, served through short-lived signed URLs; reveal images appear only after the guess.
- Every upload path is GD re-encoded (EXIF/GPS stripped).
- Club content is absent from every public surface (packs, badges, profile, pools, export).
- Account deletion and data export are extended; the privacy inventory is updated.
- Private club media is included in content backups.

## 8. Known limitations and open items
- **Local dev database (incident, corrected 2026-10-20):** a sub-agent ran `migrate:fresh` against the local
  `backend/database/database.sqlite` on 2026-10-19, which broke the no-`migrate:fresh` rule. Follow-up investigation showed **no data
  was lost**: the file did not exist before; its birth time equals the command, and earlier sessions report it missing. The
  command created a new, empty schema. See `docs/clubs-staging-plan.md` §1.
- The app has not been run on a device or against a live backend (§9).
- Club portal 2FA depends on production mail. The fallback `clubs.portal.two_factor_required=false` exists but weakens security.
- Signed media is streamed through PHP. Fine at launch scale; X-Accel-Redirect is a later optimisation.
- Small admin/portal UX differences noted by QA:
  - name/description stay editable after start in admin but are locked in the portal;
  - admin audit times are UTC without a label;
  - "Active" (admin) vs "Live" (portal) phase colours;
  - wide portal tables scroll sideways at 390 px.
- The invite-code list can skip or repeat a code once if it expires between two pages (display only).
- Legal: club terms / data-processing wording for club-supplied photos (D8); youth clubs vs the minimum age of 16 (D7, kept at 16);
  privacy policy and store age-rating review for user-generated content.
- Pre-existing, outside this sprint: the league leaderboard type on MySQL, and the `2026_07_30` migration `down()` on MySQL.

## 9. Manual device tests still required (dev build against a staging backend)
1. Clubs tab hidden with the flag off; shown third with the flag on; 5 tabs fit in all 5 languages on a small phone.
2. Join by typed code and by scanning a printed QR (admin print sheet and portal print sheet); error copy for invalid/locked codes.
3. My Clubs with many clubs: search, incremental loading, pull-to-refresh, empty/no-results/error states, suspended club.
4. Club pack play end to end; reveal images load through signed URLs; returning to the club afterwards.
5. Competition: rules card in the competition timezone (check the Hermes `timeZone` support), start attempt / continue,
   retry after network loss (idempotent), reserved-pack messages, leaderboard shared ranks, final standings, Trophy Room club trophies.
6. Club admin in the app: invite create/share/QR, member actions, photo upload (iOS multi-select incl. HEIC → JPEG, Android, cellular).
7. Push notifications: published/started/finalized pushes, tapping from cold start and while running; signed-out tap ignored.
8. Report flow and the "Club updates" toggle.
9. Portal on a real phone browser: login + emailed code, all pages, CSV downloads.

## 10. Deployment considerations (only on explicit go-ahead)
1. Take a production DB backup and a content backup first. The content backup now includes `storage/app/private/clubs`.
2. Deploy code with the existing deploy script, then run `php artisan migrate --force` (18 additive migrations). Keep
   `BALLSPOT_CLUBS_ENABLED=false` at first, so everything stays dark.
3. Run `scripts/fix-permissions.sh` (it now creates `storage/app/private/clubs`). The private disk must be writable by www-data and
   must not be web-served.
4. Confirm the scheduler (www-data cron) runs `ballspot:clubs:competition-tick` every minute and `ballspot:clubs:reconcile-counters`
   nightly. Both no-op while the flag is off.
5. Verify outbound mail (portal 2FA) and the Expo push config (`ballspot.notifications.push_enabled`).
6. Create a test club in admin, then switch `BALLSPOT_CLUBS_ENABLED=true` (config is read from `.env`; no `config:cache` per the runbook).
7. Ship a new EAS build. The new tab, screens, camera and photo-library purpose strings in `app.json` require store review;
   update store listing and privacy answers for user-generated photos.
8. Update the privacy policy (club data, photos, reports) before enabling clubs for real users.

## 11. Recommended next steps
1. (No dev-DB restore needed — see the corrected incident note.) Provision isolated staging per `docs/clubs-staging-plan.md`.
2. Review the branch; optionally run `/code-review` on it.
3. Run the staging dry run (§10) and the device QA (§9).
4. Fix the two pre-existing MySQL issues found during this sprint (league leaderboard string score, the `2026_07_30` rollback).
5. Settle the legal items (D7/D8, privacy policy), then merge and release.
