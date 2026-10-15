# BallPicker Sports — Final Release Audit (2026-10-14)

Scope: repository at `bcae3ce` (+ uncommitted audit fixes listed in §22), production probed
read-only and unauthenticated only. No deploy, commit, build, submit, migration or prod write
was performed.

## 1. Executive verdict: **READY AFTER ACTIONS**

The code is in release shape: backend 1004 passed / 1 skipped / 0 failed, mobile 256/256
jest, `tsc` clean, web export OK, lockfile consistent, the SceneGeometry dependency
issue is resolved. **It is not READY** because launch-critical facts can only be confirmed
on the server or in the stores, and nothing in this audit could verify them:

- whether production has `BALLPICKER_AUTO_ACTIVATE_DAILIES=true` **and** future Daily
  rows exist (without both, players get no Daily);
- whether the `app_events` migration and `BadgeSeeder` ran in production;
- whether a production backup + scratch restore has ever succeeded;
- whether iOS build 32 / the Android build compile on EAS (not run);
- both store pages return **404** today (apps not public yet), so Rate links only work
  after publication.

## 2. Critical blockers (P0)

| # | Finding | Component | Evidence | Action | Blocks launch |
|---|---|---|---|---|---|
| P0-1 | **Daily is never served unless activated.** The scheduler creates rows as `scheduled`; `/api/daily/today` serves only `active`; `BALLPICKER_AUTO_ACTIVATE_DAILIES` defaults to **false**. Production value unknown. | Daily | `config/ballspot.php:85`, `routes/console.php:39-43`, `DailyChallengeController.php:61`, `ScheduleDailyChallenges.php` (creates `status=scheduled`) | Set `BALLPICKER_AUTO_ACTIVATE_DAILIES=true` in prod `.env` (owner action), `config:cache`, then verify Admin → Daily shows the next 14 dates and today is **active** | **Yes**, until verified |
| P0-2 | **Future Daily rows not verified.** `BALLPICKER_AUTO_SCHEDULE_DAILIES` defaults true, but the scheduler exits without creating rows when the pool is empty / all used (`daily.schedule_skipped`). | Daily | `ScheduleDailyChallenges.php:83-100` | On prod: `php artisan ballspot:schedule-daily-challenges --dry-run` and Admin → Daily; ensure ≥14 future dates and pool depth | **Yes**, until verified |

(Classified as *missing verification* of a launch-critical configuration, not as code defects.)

## 3. High priority (P1)

| # | Finding | Class | Evidence | Action | Blocks |
|---|---|---|---|---|---|
| P1-1 | Manual **Rate BallPicker** relied on the native dialog (silently no-op on quota / TestFlight) and iOS had **no** store URL in any build profile | Confirmed defect — **FIXED** (§8) | `services/growth.ts`, `utils/storeLinks.ts`, `eas.json` (no `EXPO_PUBLIC_IOS_APP_STORE_URL`) | Ships with the next EAS build | Needs new build |
| P1-2 | Daily for a sport other than the player's preferred sport shows "no daily". The auto-scheduler draws from **all** active challenges incl. `coming_soon`/hidden sports. If prod has non-football ready challenges in the daily/general pool, football players lose that day. | Potential risk | `DailyChallengeController.php:72-76`, `ScheduleDailyChallenges.php:79-84`, `Challenge::isReady()` ignores sport status | Verify prod pool is football-only (dry-run shows sport per day). Code change (filter to active sports) needs your approval — changes scheduling rules | Only if pool is mixed |
| P1-3 | Tournaments can't be left; completion requires every member to play every round; no time sweep. An inactive or deleted member keeps a tournament active forever and occupies 1 of each player's 2 slots. | Confirmed defect (gameplay rule) | `routes/api.php:128-139` (no leave), `TournamentCompletionService.php:54-58`, `LeagueService.php:353-361` | Post-launch fix requiring rule approval (leave endpoint + expiry sweep) | No (degrades over time) |
| P1-4 | Production proxy/cache settings unverified: with empty `TRUSTED_PROXIES` behind Cloudflare/LB, all IP limits become global (5 registrations/hour for **everyone**). | Missing verification | `config/ballspot.php:416`, `bootstrap/app.php:34` | On prod: `php artisan tinker --execute="dump(config('ballspot.trusted_proxies'), config('cache.default'));"` — expect correct proxies (or none if no proxy) and `database`/`redis` | Yes if behind a proxy and unset |
| P1-5 | Android `versionCode` is `1`, no `appVersionSource`/`autoIncrement`. Second Play upload will be rejected. | Potential risk | `mobile/app.json:21`, `mobile/eas.json` | Bump manually per upload, or add remote versioning | Only for 2nd upload |

## 4. Medium / low (P2/P3)

P2
- **Daily screen 422 handling**: any 422 (not only `already_played`, also `not_today` at the UTC day change = 02:00 Belgian time) redirects to a result screen that then 404s. `DailyChallengeScreen.tsx:105-108`. Fix needs a backend `code` field + mobile check — post-launch.
- **Daily result screen discarded the result if today/stats failed** (share button vanished). **FIXED** (§8).
- **UTC day boundary**: Daily/share "today" is UTC; Belgian players switch at 01:00/02:00 local; local-date share hint can be wrong for that window (server still decides XP). `config/app.php:68`, `utils/share.ts:102`. Product decision.
- **Pack double-submit race** returns 500 on the losing request (unique index prevents duplication, no double XP). `PackPlayService.php:186`.
- **Tournament photo can later become a Daily** (scheduler excludes only past dailies). `ScheduleDailyChallenges.php:92`.
- **SMTP timeout null**: a hung Brevo connection holds a PHP worker ~60 s. `config/mail.php:48`. Set ~10 s.
- **Global `api` limiter likely keyed by IP for token users** (120/min shared behind CGNAT). `AppServiceProvider.php:37-38`.
- **Restore runbook**: restoring into the existing DB leaves newer tables → follow-up `migrate` fails; pre-restore copy can be lost on exit 3; no off-site copy. `docs/db-backup-runbook.md:201-219`.
- **Deploy block** runs artisan as root (contradicts runbook), no pre-migrate backup, no php-fpm reload. `docs/ops-runbook.md:291-316`.
- **Full `db:seed` in prod would reset sports/insert demo/daily** — guarded only by docs. `SportSeeder.php:33`, `DailyChallengeSeeder.php:19`.
- **Clear-daily-history PIN** hardcoded 4 digits, route unthrottled (admin-only). `DailyHistoryClearService.php:26`.
- **Android merged manifest** contains READ/WRITE_EXTERNAL_STORAGE (+ SYSTEM_ALERT_WINDOW). Storage is used by `requestMediaLibraryPermissionsAsync` on Android < 13 — do **not** block blindly; declare in Data safety.
- **Hermes V1 memory regression** reported by expo-doctor for SDK 56; fix requires SDK 57 (major upgrade, out of scope). Monitor crash/memory reports.

P3
- Email limiter check-then-count races allow a small, bounded overshoot under parallel requests; no path sends unlimited mail.
- Raw emails in some cache keys (`FailedLoginLimiter.php:36`, `AppServiceProvider.php:69,82,198`).
- Account deletion does not re-ask for the password.
- Honeypot IP block can affect CGNAT neighbours for 1 h; forgot-password timing differs for known vs unknown emails.
- Host can cancel an already completed tournament (`LeagueController.php:150-162`).
- `/daily/stats` weekly rank uses no tie-break and includes anonymized users.
- Daily share bonus accepts an older daily guess (still once per day).
- Tournament submit button lacks `submitting` guard (`GuessScreen.tsx:236`; server dedupes).
- `StoreReadinessCheck` prints PASS text for failing checks outside production and crashes without a DB (`StoreReadinessCheck.php:110,203`).
- Admin top nav doesn't wrap < ~600 px; two wide tables lack `.table-responsive` (diagnostics, competition).
- `confirm('{{ $sport->name }}')` breaks on apostrophes (`sports/index.blade.php:84`).
- Categories index N+1 (`categories/index.blade.php:32`).
- `NSFaceIDUsageDescription` injected by expo-secure-store though unused; `aps-environment` shows `development` in introspected config (EAS signing normally sets production — verify push on TestFlight).
- `eas-cli` in devDependencies; `typescript`/`@types/jest` differ from SDK expectations (dev-only, `tsc` passes).
- iOS share sheet dismissal still counts as a share (bounded, by design).
- Reveal image URLs are public after a guess (random filenames; not guessable beforehand).
- A reviewer claimed `sanctum:prune-expired` is not scheduled — **incorrect**, it is (`routes/console.php:51`).

## 5. Subsystems checked

Repository/deps, Expo SDK 56 alignment, expo-store-review native compat, app.json/eas.json,
auth lifecycle (register, verify, resend, login, logout, expiry, reset, deletion,
anonymization, 2FA launch flag), anti-bot/email abuse (all limits, honeypot, CAPTCHA
on/off, 429 shape, enumeration, Brevo failures), core guess flow (answer secrecy,
server scoring, coordinate scaling, duplicates), Daily (schedule, activate, timezone,
uniqueness, depletion, empty state), tournaments, competition periods + close,
packs, XP ledger + badges, share rewards, Rate/Review, social/profile permissions,
all 55 admin routes, all API routes + middleware, CORS, health, uploads, migrations,
seeders, scheduler, queue usage, backup script + runbooks, store config.

## 6. Automated results (run 2026-10-14, local)

| Command | Result |
|---|---|
| `php artisan test` | **1004 passed, 1 skipped, 0 failed** (7046 assertions) after the CorsTest fix. Before: 1003 passed, 1 failed (CorsTest), 1 skipped. The skip is a conditional random-code / MySQL-only skip. |
| `php artisan test --filter=CorsTest` with `CORS_ALLOWED_ORIGINS=` | 5 passed |
| `php artisan route:list` | 136 routes, OK |
| `php artisan migrate:status` | **Not runnable locally** (no `database.sqlite`); migrations are exercised by every RefreshDatabase test. Production status NOT VERIFIED. |
| `php -l` on all PHP files of `8a12a2e` | all OK |
| `npx tsc --noEmit` | OK |
| `npx jest` | **256/256**, 23 suites |
| `node node_modules/expo/bin/cli export --platform web` | OK (840 modules) |
| `expo install --check` | exit 1: `typescript 5.9.3` (expects ~6.0.3), `@types/jest 30` (expects 29.5.14) — dev-only |
| `expo-doctor` | 19/22: eas-cli local dep; Hermes V1 regression (SDK 57 fix); same 2 dev versions |
| `npm ci --dry-run`, `npm ls --all` | consistent, no errors |
| `expo-modules-core` | 56.0.27, single copy; `SceneGeometry.swift` present → iOS compile blocker resolved **in source** |
| `git diff --check` | clean |

### CorsTest explanation
Root cause: Laravel's immutable env repository reads `$_SERVER` before `$_ENV`/`putenv`;
booting copies the local `.env` value `CORS_ALLOWED_ORIGINS=*` into `$_SERVER`, and the
test only overrode `putenv`/`$_ENV`. It is a **test-isolation defect, local-only**; it
fails on any machine whose `.env` sets that key. `config/cors.php` is correct and the
three HTTP CORS tests pass. **No production risk**: native apps send no Origin, admin is
same-origin. Fixed by also setting/restoring `$_SERVER` in the test helper.

## 7. End-to-end matrix

Legend: A = automated test, M = manually verified in this audit, NV = not verified.

| Scenario | Preconditions | Steps | Expected | Observed | Result | Mode |
|---|---|---|---|---|---|---|
| New user | none | register → code → verify | account verified, token | as expected | Pass | A (FreshRegistrationVerification, EmailVerificationHardening) |
| Returning user | verified | login / logout | token, revoked on logout | as expected | Pass | A |
| Unverified user | not verified | login, resend | limited token, resend 60 s cooldown, 5/h 10/day | as expected | Pass | A |
| Deleted user | deleted | old token; login | 401; 403 `account_deleted`; email reusable | as expected | Pass | A (AccountDeletion*) |
| Previously 2FA user | 2FA on, opt-in flag off | login | code flow; can disable; cannot newly enable | as expected | Pass | A (LaunchTwoFactorPolicy) |
| Administrator | is_admin | admin login, every page, normal user 403 | as expected | as expected | Pass | A (AdminTest etc.); visual 1440/1280/1024 + narrow **NV on real data** |
| Daily player | active daily today | today → guess → result → share | score server-side, one guess | as expected | Pass | A; **prod daily availability NV** |
| Daily, nothing activated | flag off | open Daily | empty state | "no daily" | Pass (but P0-1) | A |
| Pack player | pack | play all → complete | completion XP once, trophy | as expected | Pass | A (PackPlayTest) |
| Tournament player | 2+ players | create/join/start/play/complete | standings, XP once | as expected | Pass | A (LeagueTournamentLifecycle) |
| User with XP/trophies | existing ledger | repeat claims | idempotent | as expected | Pass | A (XpLedgerTest) |
| User sharing | logged in | share app / daily ≥80 twice | 25 once; 10+15 once per day; badges once | as expected | Pass | A (GrowthShareReviewTest); native sheet **NV on device** |
| User reviewing | launch ≥2 | Rate button | store page opens, no XP | unit-tested; store pages 404 until live | Partial | A; device **NV** |
| Rate-limited registrant | same IP/email | >limit registers | 429 + stable `code` | as expected | Pass | A (AuthAbuseProtection) |
| iOS/Android native builds | EAS | build + install | compiles, runs | not run | — | **NV** |

## 8. Defects found and fixed (uncommitted)

1. **Rate BallPicker opened nothing reliable** (P1). Cause: the button requested the
   native dialog first (OS-quota-limited, never shown in TestFlight, `requestReview()`
   resolves even when nothing shows), and iOS had no store URL in any EAS profile.
   Fix: bundled permanent URLs and store-page-first behaviour, native request only as a
   fallback when the page cannot open. Automatic prompt unchanged (native only).
   `reviewPromptCompleted` still set on every tap; still 0 XP.
   Files: `mobile/src/utils/storeLinks.ts`, `mobile/src/utils/reviewPrompt.ts`,
   `mobile/src/services/growth.ts`, tests `storeLinks.test.ts`, `reviewPrompt.test.ts`,
   doc `docs/store-readiness.md`. Regression impact: only the Rate button path.
2. **Daily result hidden when a secondary request failed** (P2). Cause: `Promise.all`
   over result + today + stats. Fix: `Promise.allSettled` via pure helper
   `utils/dailyResultLoad.ts` (+ test). Files: `DailyResultScreen.tsx`, `dailyResultLoad.ts`,
   `dailyResultLoad.test.ts`.
3. **CorsTest isolation** (test-only). File: `backend/tests/Feature/CorsTest.php`.

## 9. Unverified functionality
Production: env flags, daily calendar content, migration/seeder state, cache/proxy config,
cron + scheduler running, backups, Brevo deliverability, push delivery. Devices: native
share sheet, StoreKit/Play review, QR camera, avatar upload, layout on small/large
phones, offline behaviour. EAS iOS + Android builds. Admin visual QA on real data.

## 10. Remaining manual tests
See the checklist at the end; additionally on a TestFlight / internal-testing build:
register with a real mailbox, verify, forgot password, delete account then try the old
session; play the Daily, share result (check XP once), Share BallPicker, tap Rate
(expect the store page — 404/"not available" until the app is live), scan a friend QR,
change avatar, join + finish a 2-player tournament, complete a pack.

## 11. Production migrations and seeders
- `php artisan migrate --force` → only new: `2026_10_14_000001_create_app_events_table` (additive).
  Earlier additive: `2026_09_08_…two_factor_and_language`, `2026_09_20_…app_notices`.
- `php artisan db:seed --class=BadgeSeeder --force` (idempotent, `updateOrCreate`; adds
  first_share / daily_bragger / social_striker → 42 badges). Without it share trophies
  silently don't award.
- **Never** run plain `db:seed` or other seeders in production.

## 12. Production env settings required
```
APP_ENV=production
APP_DEBUG=false
CACHE_STORE=database            # (or redis) — never array/file across multiple hosts
QUEUE_CONNECTION=database       # nothing is queued today; sync also works
TRUSTED_PROXIES=<proxy ranges>  # only if behind Cloudflare/LB; else leave empty
CORS_ALLOWED_ORIGINS=https://ballpicker.vanmalderstudio.be
BALLPICKER_AUTO_SCHEDULE_DAILIES=true
BALLPICKER_AUTO_ACTIVATE_DAILIES=true     # REQUIRED
BALLPICKER_REQUIRE_EMAIL_VERIFICATION=true  # prod /api/config already reports true
BALLPICKER_LOGIN_2FA_OPT_IN=false           # prod reports false ✓
BALLPICKER_FORCE_LOGIN_2FA=false
BALLPICKER_CAPTCHA_ENABLED=false            # prod reports captcha_required=false ✓ — keep OFF (no widget in app)
MAIL_MAILER=smtp + Brevo host/port/user/pass, MAIL_FROM_ADDRESS on a verified domain
```
Then `php artisan config:cache`.

## 13. Backup and restore: **NOT VERIFIED**
Script `backend/scripts/backup-mysql.sh` is sound (strict mode, `.my.cnf`, verification,
retention). No evidence a production run or restore happened. Runbook restore steps
need fixing first (restore into an empty/scratch DB). Backups are on the same VPS only.

## 14. Scheduler & Daily readiness: **NOT VERIFIED — launch blocker until checked**
Cron line `* * * * * cd … && php artisan schedule:run` must exist (as www-data). 00:05 UTC
schedule, 00:10 UTC activate, hourly code cleanup, monthly close 1st 00:15, daily prune,
reminders every 15 min. All times UTC (02:05/02:10 Belgian summer time).

## 15. Admin readiness
All 55 admin routes guarded (web+CSRF+`EnsureIsAdmin`), 403 for non-admins, links
resolve, destructive actions guarded. No secrets/tokens/stack traces in views. Only
cosmetic issues on narrow widths. Visual QA on real production data NOT VERIFIED.

## 16. Share XP / trophy / review
Server-side only (`config/ballspot.php:213-219`): first share +25 (once), daily-result
share +10 (once/UTC day), 80+ daily share +15 (once/UTC day); badges First Share (common
100 XP), Daily Bragger (rare 250), Social Striker at 5 shares (rare 250). The "XP earned"
message shows the server's total gain incl. badge XP (accurate); the pre-share hint
shows only share XP (understates, never overstates). Client never sends amounts.
Review/rate: 0 XP, no badges, `POST /api/events` cannot award anything. Admin Growth
labels review numbers as attempts/requests, not confirmed reviews.

**Store review destinations**
| | URL now used (after fix) | Before fix | Reachable now |
|---|---|---|---|
| iOS | `https://apps.apple.com/app/id6796491992?action=write-review` | none (native dialog only) | **404** — not public yet |
| Android | `https://play.google.com/store/apps/details?id=be.vanmalderstudio.ballpicker&showAllReviews=true` | native dialog; store page only if native unavailable (no `showAllReviews`) | **404** — not public yet |

- Code change needed: yes (done). Backend deploy: no. New EAS build: **yes** (build 32 must include it).
- Store approval/publication is required before either page works; the native dialog
  likewise only shows for store-installed public builds.
- Backend `/api/config` URLs: deliberately **not** added. IDs are permanent, bundled
  links work offline and can't be redirected; a remote URL adds a redirect surface
  for no benefit. If a link ever had to change, it needs a new build (or a domain-
  allow-listed config field added later).
- If the app opens the page but it 404s (before launch), the browser/store shows its
  own error; the app does not crash (`Linking.openURL` is wrapped).

## 17. Email abuse / Brevo
Every email-sending endpoint is bounded per address and per IP (register 5/h+10/day per IP
on success, 5/h per email and username; verification 5/h+10/day per address + 60 s
cooldown + 3/min per user; reset 3/h per email + 10/h per IP; 2FA 3/h+10/day per address,
requires the password). Brevo failures: `code_sent:false`, 503 `two_factor_send_failed`,
generic 200 on reset — no 500s. Limits live in the cache store (atomic increments with
database/redis); small parallel overshoot possible (P3). CAPTCHA secret never exposed;
keep CAPTCHA off.

## 18. EAS build status
Not run in this audit. Source-level evidence: `expo@56.0.23`, `expo-modules-core@56.0.27`
defines `SceneGeometry` used by `expo-store-review@56.0.4` → the previous iOS failure
should be fixed. **Actual iOS and Android EAS builds: NOT VERIFIED.**

## 19. Store submission readiness
Bundle/package `be.vanmalderstudio.ballpicker`; version 1.0.0; iOS buildNumber 32.0.0
(uncommitted bump from 31.0.0, valid); Android versionCode 1 (see P1-5); production
env holds only public URLs; iOS usage strings only for camera (QR) and photos (avatar);
microphone blocked; `ITSAppUsesNonExemptEncryption=false`; no `eas submit` profile
(submit manually or add one). Privacy/Data safety: declare account data, app activity
counters (`app_events`), photos (avatar), push token.

## 20. Release / deployment checklist (ordered)
1. Back up prod DB (`backup-mysql.sh`) and confirm the file verifies.
2. Deploy backend at the release commit; `composer install --no-dev`.
3. `sudo -u www-data php artisan migrate --force` (app_events if not yet there).
4. `sudo -u www-data php artisan db:seed --class=BadgeSeeder --force`.
5. Set env per §12 (notably `BALLPICKER_AUTO_ACTIVATE_DAILIES=true`), `config:cache`, `route:cache`, `view:cache`; reload php-fpm.
6. `php artisan schedule:list`; confirm cron; `php artisan ballspot:schedule-daily-challenges --dry-run` → schedule for real if needed; `php artisan ballspot:activate-daily --force` for today if not active.
7. `php artisan ballspot:store-readiness-check` on the server; review WARNs.
8. Commit the audit fixes; EAS production build iOS (32) + Android (bump versionCode if one was uploaded before).
9. TestFlight / internal testing smoke test (§10).
10. Submit for review; publish; then verify both Rate links resolve.

## 21. Post-launch monitoring
Daily: Admin → Daily (today active, ≥7 future days), Diagnostics warnings, auth-abuse card
(429s, honeypot, mail failures), `storage/logs` errors, Brevo bounce/quota dashboard,
backup log + file present. Weekly: daily pool depth, stuck active tournaments, Growth
page, crash reports (Hermes memory regression), disk usage of `/var/backups`.

## 22. Files changed during this audit (uncommitted)
- `mobile/src/utils/storeLinks.ts`, `mobile/src/utils/__tests__/storeLinks.test.ts`
- `mobile/src/utils/reviewPrompt.ts`, `mobile/src/utils/__tests__/reviewPrompt.test.ts`
- `mobile/src/services/growth.ts`
- `mobile/src/screens/DailyResultScreen.tsx`
- `mobile/src/utils/dailyResultLoad.ts` (new), `mobile/src/utils/__tests__/dailyResultLoad.test.ts` (new)
- `backend/tests/Feature/CorsTest.php`
- `docs/store-readiness.md`, `docs/release-audit-2026-10-14.md` (this file)
- Pre-existing, not by this audit: `mobile/app.json` (buildNumber 31.0.0 → 32.0.0)

## 23. Final git status
```
 M backend/tests/Feature/CorsTest.php
 M docs/store-readiness.md
 M mobile/app.json
 M mobile/src/screens/DailyResultScreen.tsx
 M mobile/src/services/growth.ts
 M mobile/src/utils/__tests__/reviewPrompt.test.ts
 M mobile/src/utils/__tests__/storeLinks.test.ts
 M mobile/src/utils/reviewPrompt.ts
 M mobile/src/utils/storeLinks.ts
?? docs/release-audit-2026-10-14.md
?? mobile/src/utils/__tests__/dailyResultLoad.test.ts
?? mobile/src/utils/dailyResultLoad.ts
```

## 24. Remaining risks explained
- **No Daily for players** if auto-activate is off or the calendar is empty — highest-impact, config-only.
- **Mixed-sport daily pool** would blank the Daily for most players on some days.
- **Stuck tournaments** accumulate as players go inactive or delete accounts; slots fill up.
- **Proxy misconfiguration** would turn per-IP limits into global ones (registration outage).
- **No verified backup/restore** — a data loss event before this is verified is unrecoverable.
- **Native builds unverified** — compile and runtime issues only show on EAS/devices.
- **UTC day boundary** — minor confusion around 01:00–02:00 Belgian time.
- **Hermes V1 memory regression** in SDK 56 — monitor; fix requires SDK 57.

---

## MANUAL ACTIONS REQUIRED BEFORE PUBLIC RELEASE

- [ ] Prod `.env`: `BALLPICKER_AUTO_ACTIVATE_DAILIES=true` (and confirm `BALLPICKER_AUTO_SCHEDULE_DAILIES=true`), then `php artisan config:cache`
- [ ] Admin → Daily: today is **active** and ≥14 future dates are scheduled; check the dry-run shows only football (active-sport) challenges
- [ ] Confirm the cron `schedule:run` entry exists and `php artisan schedule:list` shows the 6 tasks
- [ ] Confirm `app_events` exists (`migrate:status`) and run `db:seed --class=BadgeSeeder --force`
- [ ] Check `config('ballspot.trusted_proxies')` and `config('cache.default')` on the server
- [ ] Set `CORS_ALLOWED_ORIGINS` to the real web origin (not `*`)
- [ ] Run one production backup, then restore it into a **scratch** database and check row counts; plan an off-site copy
- [ ] Send a real verification + password-reset email through Brevo; check deliverability and sender domain
- [ ] Keep `BALLPICKER_CAPTCHA_ENABLED=false`
- [ ] Commit the audit fixes, then run EAS production builds (iOS 32, Android) and confirm both succeed
- [ ] Bump Android `versionCode` if any Play build was uploaded before
- [ ] TestFlight + Play internal-testing smoke test (registration, Daily, share, Rate, QR, avatar, tournament, pack, account deletion, push)
- [ ] Complete App Store privacy labels and Play Data safety (account, app-activity counters, photos, push token, storage permission on Android < 13)
- [ ] After publication, open both review URLs and tap Rate on each platform
