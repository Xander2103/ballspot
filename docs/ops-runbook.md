# BallPicker — Production Ops Runbook (beta)

**Scope:** lightweight beta observability. This is a log file, an admin page
and a health endpoint — not Sentry/Datadog-style monitoring. Nothing alerts you
on its own; someone has to look. Companion docs: `docs/security-hardening.md`,
`docs/store-readiness.md`.

Server paths below assume the production layout `/var/www/ballpicker/backend`.
Never paste `.env` contents, `APP_KEY`, DB/mail passwords or the beta code into
tickets, chats or this file.

---

## 1. Where things live

| What | Where |
|---|---|
| Application errors + warnings | `storage/logs/laravel.log` (level = `LOG_LEVEL`, production: `warning`) |
| Operational events (gameplay, content, auth, push) | `storage/logs/ballpicker-events-YYYY-MM-DD.log` (rotated daily, kept `LOG_EVENTS_DAYS`, default 14) |
| Admin diagnostics page | `https://<admin host>/admin/diagnostics` (admin login required) |
| Public health check | `GET /api/health` (no auth) |
| Queue tables | `jobs`, `failed_jobs` (database queue; nothing is queued today) |
| Uploaded images | `storage/app/public/{challenges/hidden,challenges/original,avatars,packs/covers}` served via the `public/storage` symlink |
| Scheduler | system cron runs `php artisan schedule:run` every minute (see `routes/console.php`) |
| Content backups | `../backups/ballspot-content/<timestamp>/` next to the backend folder |

Warnings and errors go to **both** files; info-level events only go to the
events file. `LOG_EVENTS_LEVEL=info` and `LOG_EVENTS_DAYS=14` are the defaults.

### Event log format

One line per event: `[timestamp] env.LEVEL: <event.name> {json context}`.
Context holds IDs, counts, statuses and reason codes only — never passwords,
tokens, login/reset codes, beta codes, friend/join codes, push tokens or
emails (enforced by `ObservabilityLoggingTest`). Useful greps:

```bash
grep 'tournament\.' storage/logs/ballpicker-events-$(date +%F).log
grep 'daily\.'      storage/logs/ballpicker-events-$(date +%F).log
grep 'push\.'       storage/logs/ballpicker-events-$(date +%F).log
grep '"user_id":123' storage/logs/ballpicker-events-*.log
```

Event names: `auth.registered`, `auth.login_failed`, `auth.beta_code_rejected`,
`auth.verification_sent`, `auth.verification_send_failed`,
`auth.verification_failed`, `auth.verification_completed`,
`password.reset_requested`, `password.reset_failed`, `password.reset_completed`,
`account.deleted`, `account.delete_failed`, `account.anonymized` (legacy alias
of `account.deleted`), `pack.replay_blocked`, `pack.duplicate_submit`,
`pack.completion_reward_failed`, `daily_history_clear.denied`,
`daily_history_clear.completed`, `daily_history_clear.failed`,
`daily.scheduled`, `daily.schedule_run`,
`daily.schedule_skipped`, `daily.schedule_failed`, `daily.pool_exhausted`,
`daily.history_reset_dry_run`, `daily.history_reset`, `tournament.created`,
`tournament.joined`, `tournament.join_rejected`, `tournament.cap_rejected`,
`tournament.started`, `tournament.start_failed`, `tournament.cancelled`,
`tournament.completed`, `trophy.awarded`, `pack.started`, `pack.start_failed`,
`pack.completed`, `pack.trophy_awarded`, `pack.trophy_skipped`,
`admin.challenge_created`, `admin.challenge_updated`,
`admin.challenge_validation_failed`, `admin.sport_created`,
`admin.sport_updated`, `admin.pack_created`, `admin.pack_updated`,
`push.attempt`, `push.sent`, `push.failures`, `push.batch_failed`,
`push.announcement`, `push.announcement_skipped`,
`notifications.settings_updated`, `upload.avatar_sanitize_fallback`.

---

## 2. Daily commands

```bash
cd /var/www/ballpicker/backend

# Recent errors / follow live
tail -n 100 storage/logs/laravel.log
tail -f storage/logs/laravel.log

# Today's operational events
tail -n 100 storage/logs/ballpicker-events-$(date +%F).log

# Queue
php artisan queue:failed

# Processes (php-fpm / queue worker if configured)
sudo supervisorctl status

# Scheduler: every task + next run time
php artisan schedule:list

# Content backup (DB + images + JSON exports) — run before any risky change
php artisan ballspot:backup-content

# Public health check
curl -s https://ballpicker.vanmalderstudio.be/api/health
```

`/api/health` returns `200 {"status":"ok", ...}` when the database answers
and `storage/app/public` is writable, otherwise `503 {"status":"degraded"}`.
It exposes nothing else on purpose.

---

## 3. The diagnostics page (`/admin/diagnostics`)

Read-only. It never runs commands and never changes data. Reload for live numbers.

| Card | Shows | Warns when |
|---|---|---|
| **Warnings** | every problem the page detected, most severe first | — |
| **App status** | env, `APP_DEBUG`, `APP_URL`, server time, PHP version, push flags, beta gate on/off (never the code) | `APP_DEBUG=true` in production |
| **Backend errors (log)** | log channel/level, file size + last write, count of ERROR/WARNING lines in the last 24h, last error time + a sanitized one-line summary | any error in the last 24h |
| **Database / queue** | queue connection, pending jobs, jobs older than 15 min, failed jobs, latest failure time | failed jobs > 0; stale jobs > 0 |
| **Scheduler / daily** | today's daily status (`active` / `scheduled` / `archived` / `none`), latest scheduled date, scheduled + active-upcoming counts, daily pool available | no daily today; today only `scheduled`; fewer than 3 days of runway; pool < 14 |
| **Content pool** | totals + per-sport: active ready, daily eligible (never used), tournament eligible, pack-only, used as daily | active sport with tournament eligible < 7 or daily eligible < 14 |
| **Tournaments** | lobby / active / completed / cancelled, active-but-past-end-date | expired active tournaments > 0 |
| **Packs** | active public, packs with 0 challenges (names), with / without trophy | a public active pack has 0 challenges |
| **Storage / uploads** | `storage/app/public` exists + writable, `public/storage` link present, per-directory file counts | root missing / not writable; link missing |
| **Recent activity** | 24h counts: registrations, deletions, guesses per mode, tournaments created/completed, pack completions, push devices | — |
| **Manual operations** | the commands from section 2 as copy-paste text | — |

Thresholds live in `App\Services\DiagnosticsService` (`DAILY_POOL_LOW = 14`,
`TOURNAMENT_POOL_LOW = 7`, `JOB_STALE_MINUTES = 15`, `DAILY_RUNWAY_DAYS = 3`).

### What each warning means

- **No daily challenge exists for today** — the cron did not run, ran with an
  empty pool, or the dates were never scheduled. Run
  `php artisan ballspot:schedule-daily-challenges --dry-run`, then without
  `--dry-run`. Check `schedule:list` and the system cron entry.
- **Today's daily is still "scheduled", not "active"** — the cron creates rows
  as `scheduled`; the API only serves `active`. Go to Admin → Daily and set
  today's row to Active. (Known gap: there is no automatic activation step.)
- **Fewer than 3 days of daily challenges scheduled** — run the scheduler for
  more days (`--days=14`) or upload more daily/general-pool photos.
- **Daily pool is low** — fewer than 14 never-used, ready, daily/general
  photos. A photo is a daily at most once, so this only goes up with uploads.
- **Sport: only N tournament-eligible photo(s)** — a 7-day tournament needs 7
  unique photos that are active, ready, in the tournament/general pool and
  never used as a daily. Upload tournament-pool photos for that sport.
- **N failed job(s)** — `php artisan queue:failed`, then `queue:retry <id>` or
  `queue:flush`. Nothing is queued today, so this normally means a future
  change started queueing work without a worker.
- **Jobs waiting longer than 15 minutes** — no worker is consuming the
  `jobs` table. `sudo supervisorctl status`.
- **Active tournaments past their end date** — completion is guess-triggered:
  a member who stopped playing keeps it open. The host can cancel it. Not a
  bug, but worth a nudge if the count grows.
- **Public active pack(s) with zero challenges** — players get "This pack has
  no ready challenges yet." Add challenges or set the pack to draft.
- **public/storage link is missing** — `php artisan storage:link`.
- **storage/app/public not writable** — fix ownership (`www-data`) and mode.
- **APP_DEBUG is ON in production** — set `APP_DEBUG=false`, then
  `php artisan config:cache`.

---

## 4. Common beta issues — what to check

### "I can't log in"
1. `grep auth.login_failed storage/logs/ballpicker-events-$(date +%F).log` —
   `reason` is `wrong_password` (with `user_id`) or `unknown_account`.
2. 429s: the login limiter is 5/min per email+IP (see
   `docs/security-hardening.md`); the app shows the wait time.
3. Admins always get email 2FA: check the mail transport if the code never
   arrives (`MAIL_*` in `.env`, `tail laravel.log` for mail exceptions).
4. Unverified accounts are sent back to the verification screen; that is not
   a failure.

### "I can't register" / beta code rejected
`grep auth.beta_code_rejected …` — `missing_code` means the app build did not
send one; `invalid_code` means a typo or a rotated code. The code itself is
never logged; compare with `BALLPICKER_BETA_CODE` on the server only.

### No daily challenge
1. Diagnostics → Scheduler / daily card.
2. `grep 'daily\.' storage/logs/ballpicker-events-*.log` — look for
   `daily.schedule_failed` (`no_eligible_challenges`), `daily.pool_exhausted`
   or no run at all at 00:05.
3. If the row exists but is `scheduled`, activate it (Admin → Daily).
4. A challenge archived after scheduling also yields "no daily" — the
   diagnostics card flags this.

### Tournament cannot start
1. `grep tournament.start_failed …` — context has `sport_id`,
   `requested_count`, `eligible_count`.
2. Diagnostics → Content pool per-sport "Tournament eligible" must be ≥
   the tournament's duration (7 / 14 / 30). Daily-used photos never count.
3. Admin → Challenges shows the same low-pool warning with a filter for
   blocked photos.

### Pack has no challenges / cannot start
`grep pack.start_failed …` (`no_ready_challenges`). Diagnostics → Packs lists
the empty packs by name. A pack's challenges must be **active** with a hidden
image and ball position to count as ready.

### Push notifications not arriving
1. Diagnostics → App status: "Push enabled" must be yes. "Daily reminder push"
   is expected **off** until the app build that suppresses local reminders is
   live (double notifications otherwise).
2. `grep 'push\.' …` — `push.failures` lists reason categories from Expo
   (`DeviceNotRegistered` tokens are pruned automatically;
   `MessageRateExceeded` means slow down; `http_status` / `http_exception`
   means Expo was unreachable).
3. Diagnostics → Recent activity: "Push devices seen / total" tells you
   whether devices are registering at all.
4. Admin → Notifications shows per-announcement sent/failed counts.

### Images not loading
1. Diagnostics → Storage: link present, root writable, file counts > 0.
2. `php artisan storage:link` if the link is missing.
3. `APP_URL` must be the public HTTPS origin — image URLs are built from it.
4. Check nginx serves `/storage/...` from `public/storage`.

### Queue stuck
Nothing is queued today. If counts appear: `php artisan queue:failed`,
`sudo supervisorctl status`, and `php artisan queue:work --once` to probe.

### Uploads fail in admin
`grep admin.challenge_validation_failed …` shows which fields failed
(`upload_related: true` when it was the image). Limits: jpeg/png/webp, 5 MB
(`upload_max_filesize` / `post_max_size` in php.ini and `client_max_body_size`
in nginx must be ≥ 6M). Storage warnings on the diagnostics page cover the
disk side.

---

## 5. After a deploy

```bash
cd /var/www/ballpicker/backend
php artisan migrate --force          # never migrate:fresh
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan storage:link             # idempotent
curl -s https://ballpicker.vanmalderstudio.be/api/health
```

Then open `/admin/diagnostics` and clear every red warning before announcing
the build. Set `BALLPICKER_APP_VERSION` in `.env` so the admin header and the
diagnostics page show the release you actually deployed.

---

## 6. Accepted limitations

- No alerting, no aggregation, no external monitoring. Logs rotate after
  14 days (events) and grow unbounded (`laravel.log`, single driver) unless
  `LOG_STACK=daily` is used — recommended for production.
- The diagnostics "Backend errors" card scans only the last 512 KB of
  `laravel.log`; huge files under-count.
- Warnings are computed on page load, not pushed.
- Tournament completion has no time-based sweep; expired active tournaments
  are reported, not closed.
- The daily `scheduled → active` step is still manual.

---

## 6. Launch-hardening flows (v1.9.5) — what to check

The `/admin/diagnostics` log card now has a **Failed flows (24h)** row that
counts ERROR/WARNING events from the events log by name. Anything listed there
is a user-visible failure of one of these flows.

### "The reset link doesn't work"
1. The link in the email is `{FRONTEND_URL}/reset-password?token=…&email=…`.
   In production `FRONTEND_URL` **must be the backend domain**
   (`https://ballpicker.vanmalderstudio.be`) — the backend serves the reset
   page itself. Confirm: `curl -sI https://ballpicker.vanmalderstudio.be/reset-password | head -1` → `200`.
2. `grep 'password\.' storage/logs/ballpicker-events-$(date +%F).log`:
   `reset_requested {outcome: send_failed}` = mail transport problem (check
   `MAIL_*`, `tail laravel.log`); `reset_failed {reason: invalid_token}` =
   expired/used link (tokens expire per `config/auth.php` `passwords.users.expire`,
   default 60 min) — the user must request a new one and open the **newest** email.
3. The app accepts the whole link pasted into "Reset link or code".

### "The verification code doesn't work"
1. `grep 'auth.verification' storage/logs/ballpicker-events-$(date +%F).log`.
   `verification_send_failed` = mail not leaving (fix mail, user taps Resend);
   `verification_failed {reason: expired}` = older than
   `BALLPICKER_EMAIL_CODE_EXPIRY_MINUTES` (60); `{reason: locked}` = 5 wrong
   tries — Resend issues a fresh code and resets the lock.
2. The last 3 codes sent are all valid, so a late email still works.
3. Emergency switch: `BALLPICKER_REQUIRE_EMAIL_VERIFICATION=false` +
   `php artisan config:cache` disables the code step entirely (new accounts
   verified at once, existing unverified accounts let in).

### "Delete account fails"
`grep account.delete_failed storage/logs/ballpicker-events-*.log` → the context
carries `user_id` + exception class; the full trace is in `laravel.log` at the
same timestamp. Deletion is one transaction: a failure leaves the account
fully intact (the user can retry). After success the original email and
username are free to register again.

### "Pack shows 'attempt is not active' on the last challenge"
Fixed in v1.9.5: re-submitting an accepted guess is idempotent and a completed
pack returns its overview. If `pack.completion_reward_failed` appears, the
completion itself succeeded but badges/XP did not — check `laravel.log` for
the exception and re-run `php artisan db:seed --class=BadgeSeeder` if a badge
row is missing.

### Beta gate
Private beta: set `BALLPICKER_BETA_CODE=<code>`. Public launch: leave it
**empty** — the app hides its beta-code field based on `GET /api/config`
(`beta_gate: false`) and the backend accepts registrations without a code.
Change requires `php artisan config:cache`; no app build.

### Pre-launch tool: Clear Daily History (`/admin/diagnostics` → Danger zone)

Web twin of `php artisan ballspot:reset-test-daily-history --force --confirm-prelaunch`.
Deletes **only** `daily_challenge_guesses` and `daily_challenge_guesses`' parent
`daily_challenges` rows — never challenges, images, `usage_pool`, tournaments,
users, badges or packs. Use it once before public launch to make test-scheduled
photos reusable; never casually afterwards (it erases players' daily scores).

Safety chain, in order: admin session → POST + CSRF only (GET is 405) →
acknowledgement checkbox → confirmation PIN (constant-time compare, never
logged, never rendered) → full content backup via the same service as
`ballspot:backup-content` (backup failure = nothing deleted) → one DB
transaction. Every attempt logs `daily_history_clear.denied {reason}`,
`.completed {counts, backup_folder}` or `.failed {stage}`; the PIN value is
never in any context.

After clearing: "Used as Daily" is 0, the daily pool grows again and
diagnostics warns that no daily exists for today — run
`php artisan ballspot:schedule-daily-challenges` or use Admin → Daily.
Backups land in `../backups/ballspot-content/<timestamp>/` (override with
`BALLPICKER_BACKUP_ROOT`).
