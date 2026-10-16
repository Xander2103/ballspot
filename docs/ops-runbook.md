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
| Content backups (images + JSON exports, SQLite file only) | `../backups/ballspot-content/<timestamp>/` next to the backend folder |
| **MySQL database backups** (nightly root cron) | `/var/backups/ballpicker/ballpicker-db-<stamp>.sql.gz`, log `/var/backups/ballpicker/backup.log` — see `docs/db-backup-runbook.md` |

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
`email_verification.sent`, `email_verification.send_failed`,
`email_verification.failed`, `email_verification.completed`, `email_verification.skipped`,
`password_reset.requested`, `password_reset.failed`, `password_reset.completed`,
`account.delete.completed`, `account.delete.failed`, `account.anonymized` (legacy alias
of `account.delete.completed`), `pack.replay_blocked`, `pack.duplicate_submit`,
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

# MySQL dump (verified, 14-day retention) — run before any risky change
sudo /var/www/ballpicker/backend/scripts/backup-mysql.sh && echo "backup ok"

# Content backup (images + JSON exports; copies the DB only when it is SQLite)
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

### Auth email abuse card (v1.9.13)

"Auth email abuse protection" counts the last-24 h refusals per event
(`register.rate_limited`, `verification.resend_rate_limited`,
`password_reset.rate_limited`, `login_2fa.rate_limited`, `honeypot.triggered`,
`register.captcha_failed`) from the events log. Numbers here mean the
protections are working — not that something is broken. A sudden spike in
`register.rate_limited` + `honeypot.triggered` is the signal to consider the
flagged-off CAPTCHA (docs/security-hardening.md → "If registration spam
starts"). Limits are tunable via the `BALLPICKER_REGISTER_*`,
`BALLPICKER_VERIFICATION_EMAILS_*`, `BALLPICKER_LOGIN_2FA_EMAILS_*` and
`BALLPICKER_PASSWORD_RESET_*` env vars (then `php artisan config:cache`).

### Competition dashboard (`/admin/competition`, v1.9.14)

Read-only: current period (players, guesses, average, highest guess, days
remaining, top-finisher eligibility), previous period + close status
(trophies, XP, closed at), a live top-10 preview (usernames only, deleted
accounts excluded like the app), accounts (active / deleted / new 7d / new
24h), the CLI close commands, the competition config with a config-cache
reminder, the growth summary (hidden until the `app_events` migration ran;
"Growth events are not tracked yet." while empty) and the same auth-abuse
counters as the diagnostics card plus registrations, verification emails
sent, reset requests and mail failures for the last 24 h. Nothing on the page
writes — closing stays `php artisan ballspot:close-competition`.

### What each warning means

- **No daily challenge exists for today** — the cron did not run, ran with an
  empty pool, or the dates were never scheduled. Run
  `php artisan ballspot:schedule-daily-challenges --dry-run`, then without
  `--dry-run`. Check `schedule:list` and the system cron entry.
- **Today's daily is still "scheduled", not "active"** — the cron creates rows
  as `scheduled`; the API only serves `active`. With
  `BALLPICKER_AUTO_ACTIVATE_DAILIES=true` the 00:10 scheduler entry
  (`ballspot:activate-daily`, idempotent, today's date only) flips it for you;
  check `schedule:list` and the events log for `daily.activated` /
  `daily.activate_failed`. Otherwise go to Admin → Daily and set today's row
  to Active, or run `php artisan ballspot:activate-daily --force`.
- **Fewer than 3 days of daily challenges scheduled** — run the scheduler for
  more days (`--days=14`) or upload more daily/general-pool photos.
- **Daily pool is low** — fewer than 14 never-used, ready, daily/general
  photos. A photo is a daily at most once, so this only goes up with uploads.
- **Automatic daily scheduling is OFF** — `BALLPICKER_AUTO_SCHEDULE_DAILIES=false`
  in `.env`. Expected during launch control: the 00:05 scheduler entry is
  listed by `schedule:list` but skipped at run time, so a deploy or the
  nightly cron never recreates `daily_challenges` rows after Clear Daily
  History. Dailies are then created only by hand
  (`php artisan ballspot:schedule-daily-challenges`, which ignores the flag, or
  Admin → Daily). Set the flag to `true` (or remove it) and
  `php artisan config:cache` to hand scheduling back to the cron.
- **Today's events log is not writable by the web user** — the day's
  `storage/logs/ballpicker-events-YYYY-MM-DD.log` was created by another user
  (typically the cron running `schedule:run` as root). Events are dropped and
  `laravel.log` shows `applog.write_failed` with the exception. Fix:
  `chown www-data:www-data storage/logs/ballpicker-events-*.log` and make the
  cron entry run as the web user (`sudo -u www-data` or the `www-data`
  crontab). Before 2026-09-07 this state made every request that logs an event
  fail with 500 (register, unverified login, failed login); AppLog now never
  throws, so it degrades to dropped events instead.
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
3. A user with two-factor login ON (Profile → Account & security, or
   `BALLPICKER_FORCE_LOGIN_2FA=true`) gets `requires_2fa`; `login.2fa_required`
   is logged. Check the mail transport if the code never arrives (`MAIL_*` in `.env`, `tail laravel.log` for mail exceptions).
4. Unverified accounts are sent back to the verification screen; that is not
   a failure.

### "Create Account says 'Something went wrong on our side' but the email arrived"
Seen on TestFlight 2026-09-07. The account and the code were created and the
mail went out; the 500 came from the event log write that follows it. Check
`grep applog.write_failed storage/logs/laravel.log` and the diagnostics
"Backend errors" card ("writable by web user"). Fix the ownership of
`storage/logs/ballpicker-events-*.log` (see After a deploy). The affected user
is NOT stuck: logging in again with the same email + password answers
`requires_email_verification` and mints a fresh code when none is usable, and
"Resend code" does the same. Expired codes are purged hourly, so a stranded
account having zero `email_verification_codes` rows is normal, not damage.

### "Start Pack" shows "Something went wrong on our side"
The app shows that copy only for a 5xx (or an unreadable 2xx body). Pack
list/detail use only the global `api` limiter; Start Pack and every guess
(pack, daily, tournament) also hit the per-user `gameplay` limiter and write
to the database.

**Incident 2026-10-15 (confirmed from the production log):**
`fopen(storage/framework/cache/data/14/e0/…): No such file or directory`,
stack `FileStore → RateLimiter → ThrottleRequests`, repeatedly for one user.
`CACHE_STORE=file` and the scheduler cron ran as root; every
`withoutOverlapping()` task takes a cache lock, so root had created
`data/xx/` directories the web user could not write into. For a player
whose per-user limiter key hashed into one of them, `makeDirectory()` failed
silently and `fopen()` hit a path that never existed → 500 on Start Pack and
on every guess, while the pack list (a different key) still loaded. Repairing
ownership fixed it.

Since then the limiter **fails open** (`App\Support\ResilientRateLimiter`):
a broken cache store no longer 500s any route; the request goes through
unthrottled, `rate_limiter.store_failed` is logged (error level, counted on
Diagnostics → "Failed flows") and the health check's `cache` flag turns
false so the monitor mails you. So if the symptom is back, it is NOT the
cache — collect, as the web user:
1. `grep -n "packs/.*/start\|SQLSTATE\|Permission denied" storage/logs/laravel.log | tail -n 20`
   — and check the newest timestamp: if it is days old, `laravel.log` itself
   is not writable (Diagnostics: "laravel.log writable by web user") and 500s
   are not being recorded. `sudo scripts/fix-permissions.sh`.
2. `/admin/diagnostics` → Health strip and "Cache / rate limiter" card.
3. `sudo -u www-data php artisan ballspot:health`
4. `grep "pack.start" storage/logs/ballpicker-events-$(date +%F).log | tail`
   — `pack.started` means the request reached the service.
5. `tail -n 50 /var/log/nginx/error.log` for 502/504 (php-fpm down/timeouts).

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

## 5. Production setup, deploy and monitoring

### 5.0 `/var/www/ballpicker/deploy.sh` — review (2026-10-15)

The original script (14 steps, run as `deploy`, `sudo` for chown/supervisor/
systemctl) was reviewed line by line. It is sound in structure; three things
in it create the permission state behind both incidents:

| step | original | finding | now |
|---|---|---|---|
| 2, 5–8, 11, 13, 14 | `php artisan …` **as `deploy`** | every file/dir Laravel creates in those steps is `deploy`-owned with umask 022 → group `www-data` cannot write it. Step 10's `chown/chmod` repairs what exists *at that moment*; step 11 (`queue:restart`) and 14 (`schedule:list`, `migrate:status`) run **after** it, and any artisan run by hand later is never repaired | every artisan call is `sudo -u www-data php artisan …` |
| 4 | `composer install` as `deploy` | its hooks run `package:discover`, writing `bootstrap/cache/{packages,services}.php` as `deploy` | `composer install --no-scripts`, then `package:discover` as `www-data` |
| 8 | `php artisan optimize:clear` | runs `cache:clear`: wipes every rate-limit counter and scheduler lock on each deploy (and, as `deploy`, cannot delete `www-data`-owned cache dirs — silently) | `view:clear` + `config:cache`, `route:cache`, `view:cache` |
| 9–10 | `mkdir -p …`, `chown -R deploy:www-data`, `chmod -R 775` | right idea, wrong moment and no setgid: directories created afterwards by `deploy` are 755 `deploy:deploy` | `scripts/fix-permissions.sh` **before** the first artisan call: `www-data:www-data`, dirs 2775, files 664, `.env` 640 |
| 3 | `git reset --hard origin/master` | fine (discards server-side edits — intended) | kept; `--ref=<commit>` for rollback |
| 2 / 13 | `artisan down` / `up` | fine; the monitor now gives maintenance mode 10 min grace | kept (`--no-maintenance` to skip) |
| 11 | `queue:restart` + `supervisorctl restart all` | fine **if** the supervisor program runs as `www-data` — check `user=` | kept; check in §5.2 |
| 12 | reload php-fpm + nginx | fine | kept |
| 14 | curl checks | fine | kept, plus `fix-permissions.sh --check` and `ballspot:health` |

Who created `storage/framework/cache/data/14/`? User 32's gameplay-limiter
**timer** key hashes to exactly `data/14/e0` (verified), so the key is
certain. The directory `14/` itself was created by a non-`www-data` process
earlier: not by step 11 (its key hashes to `ee/2f`), so by the scheduler cron
if it runs as `deploy`/`root`, or by an artisan command typed by hand. §5.2
step 2 finds out which; the fix below covers every variant.

`backend/scripts/deploy.sh` is the corrected script with the same 14 steps
and the same operator (`deploy` + `sudo`). `/var/www/ballpicker/deploy.sh`
becomes a two-line wrapper (§5.3) so the habit `./deploy.sh` keeps working.

### 5.1 Ownership model (the rule behind both outages)

| What | Owner | Mode | Who writes it |
|---|---|---|---|
| application code, `vendor/` | `deploy` | 755 / 644 (world-readable) | `git`, `composer --no-scripts` — as `deploy`, during deploy |
| `storage/`, `bootstrap/cache/` | `www-data:www-data` | dirs **2775**, files **0664** | php-fpm, artisan, the scheduler cron — **all as `www-data`** |
| `.env` | `deploy:www-data` | **640** | you |

`deploy` is a member of group `www-data` (so `git reset --hard` can still
touch the tracked `.gitignore` files inside `storage/`), but **`deploy` never
runs PHP**: every `php artisan …` on the server is
`sudo -u www-data php artisan …`, and the scheduler cron lives in
**www-data's** crontab. Never world-writable (`777`), never `chown -R root`.
`scripts/fix-permissions.sh` makes the table above true and `--check`
verifies it without changing anything.

What went wrong before: 2026-09-07 the root cron created the day's events
log (dropped events, 500s on register); 2026-10-15 the root cron's
`withoutOverlapping()` locks created `storage/framework/cache/data/xx/`
directories (Start Pack / guesses 500 for some players). Same mistake, two
symptoms; see §4 "Start Pack" for the incident detail.

### 5.2 One-time installation (run once, then only on a new server)

The order matters. The scheduler cron moves to www-data **before** the first
deploy with the new script, so that deploy already runs next to a correct
cron and the only thing it can report is the heartbeat that does not exist
yet (tolerated, §5.3). Nothing below changes the running app until the deploy
itself (step 9): steps 0–8 only read, back up, fetch the scripts, repair
ownership (php-fpm gains write access, loses nothing) and edit files that are
not read until the deploy re-caches the config.

As `deploy`, one command at a time; stop at the first surprise.

About step 0's `git status --short`: apart from `?? deploy.sh` it should be
empty. A ` M` line is a local edit of a tracked file; step 2 overwrites such
edits inside `backend/scripts/` (today only `backup-mysql.sh` lives there)
and the deploy's `git reset --hard` discards them everywhere else, exactly as
the old script did on every run. Commit anything that must survive before
step 2. Untracked files (`deploy.sh`, `backend/.env`, `storage/`) are never
touched by either command, and the files step 2 stages are byte-identical to
what the reset installs, so the index ends up clean (verified in a throwaway
clone, 2026-10-16).

```bash
# 0. Facts first (read-only). The current commit is the rollback target — write it down.
cd /var/www/ballpicker && git rev-parse --short HEAD && git status --short
sudo crontab -l 2>/dev/null | grep -n schedule:run ; crontab -l 2>/dev/null | grep -n schedule:run ; sudo crontab -u www-data -l 2>/dev/null
grep -n 'user=\|command=' /etc/supervisor/conf.d/*.conf                 # user=www-data wanted
ps -o user= -C php-fpm8.3 | sort -u                                      # www-data
sudo -u www-data php /var/www/ballpicker/backend/artisan migrate:status | grep create_cache_table   # Ran = the `cache` table exists
sudo -u www-data php /var/www/ballpicker/backend/artisan tinker --execute="foreach (DB::select('SHOW GRANTS FOR CURRENT_USER()') as \$r) echo implode(' ', (array) \$r), PHP_EOL;"

# 1. Back up the old entry point, outside the checkout. Never run it again: it ran artisan as deploy.
sudo mkdir -p /var/backups/ballpicker && sudo cp -a /var/www/ballpicker/deploy.sh /var/backups/ballpicker/deploy.sh.$(date +%F) && ls -l /var/backups/ballpicker/

# 2. Fetch ONLY the scripts. The rest of the code stays as deployed; step 9 does the real reset.
cd /var/www/ballpicker && git fetch origin && git checkout origin/master -- backend/scripts && ls -l backend/scripts/
bash -n backend/scripts/deploy.sh && bash -n backend/scripts/fix-permissions.sh && bash -n backend/scripts/deploy-wrapper.sh && echo syntax-ok

# 3. deploy joins group www-data (new shells only — log out, log in, then check)
sudo usermod -aG www-data deploy
id -nG | tr ' ' '\n' | grep -x www-data

# 4. Ownership and modes of the writable dirs (idempotent, touches no data)
cd /var/www/ballpicker/backend && sudo bash scripts/fix-permissions.sh && sudo bash scripts/fix-permissions.sh --check

# 5. Scheduler cron — in WWW-DATA's crontab only; keep the php path the old line used
sudo crontab -u www-data -e
#   * * * * * cd /var/www/ballpicker/backend && php artisan schedule:run >> /dev/null 2>&1
#   then remove the schedule:run line where step 0 found it:   sudo crontab -e   and/or   crontab -e
sudo journalctl -u cron --since "-3min" --no-pager | grep -c '(www-data) CMD'    # ≥1 after a minute; 0 = not active yet

# 6. Supervisor queue program must run as www-data (skip when step 0 showed user=www-data)
#    edit /etc/supervisor/conf.d/<program>.conf → user=www-data, then:
sudo supervisorctl reread && sudo supervisorctl update

# 7. .env — add these lines. Nothing reads them until the deploy runs config:cache.
#    CACHE_STORE=database                     # rate-limit counters and scheduler locks in MySQL — no
#                                             # per-directory ownership to get wrong (`cache` table, step 0)
#    BALLPICKER_WEB_USER=www-data
#    BALLPICKER_ALERT_EMAIL=info@vanmalderstudio.be
#    (defaults, only if you want them explicit: BALLPICKER_RATE_LIMIT_FALLBACK_STORE=database, BALLPICKER_RATE_LIMIT_FAIL_OPEN=true)
nano /var/www/ballpicker/backend/.env

# 8. Entry point: install the wrapper from the repo (root-owned, untracked) and prove the hand-over without deploying
sudo install -m 0755 /var/www/ballpicker/backend/scripts/deploy-wrapper.sh /var/www/ballpicker/deploy.sh
/var/www/ballpicker/deploy.sh --help

# 9. First deploy with the new script (1–2 min maintenance). Expected: "Deploy complete", exit 0, and in the
#    health table only "scheduler  FAIL" followed by "Only the scheduler heartbeat is missing or stale".
cd /var/www/ballpicker && ./deploy.sh; echo "exit $?"

# 10. ≤5 min later the www-data cron has written the heartbeat
cat /var/www/ballpicker/backend/storage/framework/scheduler-heartbeat.json       # "user": "www-data", recent "at"
sudo -u www-data php /var/www/ballpicker/backend/artisan ballspot:health          # Status: ok
curl -s https://ballpicker.vanmalderstudio.be/api/health                           # "status":"ok"

# 11. Independent monitor + backup — in ROOT's crontab, only now (earlier it would have alerted on the expected 503s)
sudo mkdir -p /var/lib/ballpicker-monitor && sudo touch /var/log/ballpicker-monitor.log
sudo crontab -e
#   */5 * * * * /var/www/ballpicker/backend/scripts/monitor-health.sh
#   15 3 * * *  /var/www/ballpicker/backend/scripts/backup-mysql.sh      # see docs/db-backup-runbook.md
sudo /var/www/ballpicker/backend/scripts/monitor-health.sh; echo "exit $?"        # 0
sudo -u www-data php /var/www/ballpicker/backend/artisan ballspot:send-alert --subject="[BallPicker] test alert" --body="monitoring installed on $(hostname)"

# 12. Smoke test in the app (Start Pack, a pack guess, a daily guess); /admin/diagnostics without red warnings
```

**If step 9 ends with exit 1.** The script has already lifted maintenance
mode; the code may be half-deployed. Read the `DEPLOY FAILED:` line, then
`cd /var/www/ballpicker && ./deploy.sh --ref=<commit from step 0>`. That
completes even though the old commit has no `backend/scripts/` (the script
copies `fix-permissions.sh` aside before the reset). Afterwards the wrapper
refuses to start until `git checkout origin/master -- backend/scripts` has
been run again (it prints that command). The `.env` lines from step 7 can
stay: older code ignores the `BALLPICKER_*` keys and `CACHE_STORE=database`
works with it (the table exists). No migration was added, so the database
needs nothing. Restoring `/var/backups/ballpicker/deploy.sh.<date>` is the
last resort only; after running it, run `sudo bash scripts/fix-permissions.sh`
because it leaves deploy-owned files behind.

**If step 9 ends with exit 2.** The app is up and on the new code; one of the
post-deploy checks failed and the output names it (`PROBLEM —`). Fix it, do
not roll back.

Switching `CACHE_STORE` from `file` to `database` resets in-memory
rate-limit windows once (counters start fresh in the table); nothing else is
affected. Do not delete `storage/framework/cache/data` — it is simply no
longer used.

### 5.3 Every deploy

```bash
cd /var/www/ballpicker && ./deploy.sh                      # as deploy; sudo asks once
./deploy.sh --skip-composer                                # app-code-only hotfix
./deploy.sh --ref=<commit>                                 # pinned deploy / rollback
./deploy.sh --no-maintenance                               # skip artisan down/up
./deploy.sh --help
```

`/var/www/ballpicker/deploy.sh` is the installed copy of
`backend/scripts/deploy-wrapper.sh` (§5.2 step 8): root-owned, not tracked,
so `git reset --hard` never touches it. It refuses to start when
`backend/scripts/deploy.sh` or `fix-permissions.sh` is missing or not
executable, or when the deploy script does not parse, and otherwise hands
over to `backend/scripts/deploy.sh`, which keeps the original 14 steps:
maintenance on → `git reset --hard origin/master` (or `--ref`) →
`composer install --no-dev --no-scripts` (as deploy) → `fix-permissions.sh`
→ `package:discover`, `migrate --force`, `BadgeSeeder`, sprint-badge
backfill, `view:clear` + `config/route/view:cache`, `storage:link` (all as
www-data) → `queue:restart` + `supervisorctl restart all` → reload php-fpm +
nginx → maintenance off → checks (`fix-permissions.sh --check`,
`ballspot:health`, HTTP health, schedule, supervisor, CORS, privacy page).
It never runs `migrate:fresh`, the full `db:seed`, `cache:clear` or
`optimize:clear`.

**Exit codes.**

| exit | meaning | what to do |
|---|---|---|
| 0 | deployed, every check passed. A scheduler heartbeat that is missing or stale right after the deploy is tolerated and announced as such (the www-data cron writes it within 5 min) | open `/admin/diagnostics`, clear red warnings, re-run `ballspot:health` in 5 min |
| 1 | a deploy step failed; the app has been brought back up; the code may be half-deployed | read `DEPLOY FAILED:`, then `./deploy.sh --ref=<previous commit>` |
| 2 | deployed and up, but a post-deploy check failed (permissions, or a health check other than the heartbeat); the output has a `PROBLEM —` line with the repair | fix it; do not roll back |

**After exit 1 — forward or back?** Exit 1 is never a rollback: the script
reverts nothing, it only lifts maintenance mode, so the app is live on
whatever the failed step left behind. The last lines of the output say
exactly what that is (`STATE: …`), followed by the two ways out. Decide
within minutes:

| `STATE:` says | cause | do |
|---|---|---|
| `nothing changed — the code is still <old>` | fetch, sudo, `.env`, a preflight check | fix the cause, run `./deploy.sh` again; nothing to roll back |
| anything else, cause outside the code (network during `composer`/`fetch`, sudo timed out, a reload failed, disk full) | transient | fix it, run `./deploy.sh` again (every step is idempotent) |
| anything else, cause in the new code or data (`migrate` failed, `package:discover`/`config:cache`/`BadgeSeeder` threw, or the deploy completed and the app is broken) | the release | `./deploy.sh --ref=<previous commit>` (printed in step 1), investigate locally, deploy forward later |

`--ref` is the only correct rollback: it re-runs composer, permissions,
caches and the service reloads for the old commit. A hand-typed `git reset`
leaves `bootstrap/cache/config.php` and `routes-v7.php` from the new code
behind. Never roll back on exit 2 or on a `scheduler FAIL` alone.

**Maintenance mode is always lifted.** The script turns it off in step 13;
on a failed step, on Ctrl-C or a dropped terminal, and on any unexpected
error the EXIT trap runs `artisan up`, and if that fails too it removes
`storage/framework/down` and `maintenance.php` directly (that is all
maintenance mode is). Only `kill -9` can leave the app down — then run
`sudo -u www-data php artisan up`, or `sudo rm -f
storage/framework/down storage/framework/maintenance.php`. Run a deploy over
a stable connection; `tests/Feature/DeployScriptTest.php` exercises every
one of these exit paths against shims.

Then open `/admin/diagnostics` and clear every red warning before announcing
the build. Set `BALLPICKER_APP_VERSION` in `.env` so the admin header and the
diagnostics page show the release you actually deployed.

**Rollback.** Step 1 prints `rollback later with: /var/www/ballpicker/deploy.sh
--ref=<commit>`. `--ref` does `git reset --hard <commit>` and re-runs
composer, permissions, caches and the checks; the next plain run returns to
`origin/master`. The whole script is parsed before it runs and
`fix-permissions.sh` is copied aside first, so a rollback to a commit that
predates `backend/scripts/` completes too; afterwards the wrapper refuses
the next run until `git checkout origin/master -- backend/scripts` (it
prints that). Migrations are never rolled back automatically: every
migration in this project is additive, and the monitoring release adds
**no** migration at all, so code rollback is always safe. The `.env` keys
added for monitoring are ignored by older code; `CACHE_STORE=database` can
stay.

**Downtime.** The script keeps the original maintenance window
(`artisan down --retry=30` … `up`): the app answers 503 for the length of
the deploy (typically 1–2 minutes; the mobile app shows "Something went
wrong" and retries). The monitor recognises maintenance mode (503 without the
health JSON) and only alerts if it lasts longer than
`BALLPICKER_MAINTENANCE_GRACE_MINUTES` (10). Database and sessions are
untouched. `--no-maintenance` skips the window; then a request during
`composer install` or the cache rewrite can fail for a second instead.

**Queue worker.** Nothing is queued today (mail and push run inline), but if
a supervisor program exists it must run as `user=www-data`
(`grep -n user= /etc/supervisor/conf.d/*.conf`) or it recreates the
root-owned-file problem. The deploy script calls `queue:restart` (graceful)
when it finds one.

**Repeated runs** are safe: ownership repair, `migrate`, `storage:link`,
`BadgeSeeder` and the caches are all idempotent; rate-limit counters and
scheduler locks are never touched.

**Nothing in this repo schedules dailies during a deploy.** The only
automatic writer of `daily_challenges` is the 00:05 scheduler entry
(`ballspot:schedule-daily-challenges`, default 14 days). To keep a cleared
daily calendar empty until you schedule by hand, set
`BALLPICKER_AUTO_SCHEDULE_DAILIES=false` in `.env` and run
`php artisan config:cache`. `php artisan db:seed` (the full seeder) would also
add today's daily via `DailyChallengeSeeder` — run only `--class=BadgeSeeder`
on production.

### 5.4 What is monitored

`GET /api/health` (public, booleans only) = `php artisan ballspot:health`
(with reasons, exit 1 when degraded) = the Health strip on
`/admin/diagnostics`. All three use `App\Services\HealthCheckService`:

| check | true when | typical cause when false |
|---|---|---|
| `database` | PDO connects and `select 1` works | MySQL down / wrong credentials |
| `storage` | `storage/app/public` writable by the web user | ownership |
| `cache` | the rate-limiter store passes a write/read/delete probe **and** (file driver) no entry under `cache/data` is unwritable | root-created dirs, full disk, `CACHE_STORE` pointing at a dead service |
| `queue` | `jobs`/`failed_jobs` exist, nothing waiting > 15 min, no failure in the last 24 h | worker not running, a job that threw |
| `scheduler` | `ballspot:heartbeat` ran ≤ 15 min ago **as the web user** | cron missing, or cron in root's crontab (the 2026-10-15 cause) |

`ballspot:heartbeat` is scheduled every five minutes without
`withoutOverlapping()`, so it does not depend on the cache it helps monitor.

### 5.5 Alerting

`scripts/monitor-health.sh` (root cron, every 5 min, bash + curl only) fetches
`/api/health`, retries once after 20 s, and mails `BALLPICKER_ALERT_EMAIL`:

- **DOWN** — first failure (HTTP ≠ 200 or `"status" ≠ "ok"`); records the incident start
- **STILL DOWN** — while it keeps failing, at most once per `BALLPICKER_ALERT_COOLDOWN_MINUTES` (default 60)
- **RECOVERED** — first success afterwards, with the outage duration

Delivery goes through the app's SMTP (`sudo -u www-data php artisan
ballspot:send-alert`); if PHP itself is broken it falls back to
`sendmail`/`mail(1)` when present, otherwise it writes `ALERT NOT DELIVERED`
to `/var/log/ballpicker-monitor.log`. State lives in
`/var/lib/ballpicker-monitor/{incident,last_alert,last_ok}`. Mail bodies
carry the URL, HTTP code, status, failing check names, host and time — never
a secret or a stack trace.

Overrides (environment of the cron line): `BALLPICKER_HEALTH_URL`,
`BALLPICKER_ALERT_EMAIL`, `BALLPICKER_ALERT_COOLDOWN_MINUTES`,
`BALLPICKER_MONITOR_STATE_DIR`, `BALLPICKER_MONITOR_LOG`.

### 5.6 The rate limiter when its cache store breaks

`App\Support\ResilientRateLimiter` (bound for the throttle middleware, the
`RateLimiter` facade, `AuthAbuseGuard` and `FailedLoginLimiter`):

| stage | what happens | who is affected |
|---|---|---|
| store throws | request switches to `BALLPICKER_RATE_LIMIT_FALLBACK_STORE` (default `database`) and retries — **every limit stays enforced**, counters restart once | nobody; `rate_limiter.store_failed` (policy `fallback:database`) is logged, health `cache` turns red |
| fallback unavailable too, limiter in `fail_closed` (`register`, `email-verify`, `email-resend`, `forgot-password`, `reset-password`, `login`, `login-verify`, `login-resend`, `admin-login`, `account-delete`, `export`) | **503** "temporarily unavailable", nothing sent, nothing created | mail-producing / credential / irreversible flows — they need the database anyway |
| fallback unavailable too, any other limiter (`api`, `gameplay`, `tournaments`, `share`, `friends`, `uploads`, …) | request allowed unthrottled (`BALLPICKER_RATE_LIMIT_FAIL_OPEN=true`); set it to `false` to 500 instead | gameplay stays playable; XP dedupe and business caps are database-backed |

So email abuse is never unlimited: either the database counter holds, or
the mail-producing route is closed.

### 5.7 Switching `CACHE_STORE` to `database`

What it needs and what it changes — verify, do not assume:

```bash
cd /var/www/ballpicker/backend
sudo -u www-data php artisan migrate:status | grep create_cache_table   # must say Ran (0001_01_01_000001)
sudo -u www-data php artisan tinker --execute="echo DB::table('cache')->count(), ' / ', DB::table('cache_locks')->count();"
sudo -u www-data php artisan tinker --execute="foreach (DB::select('SHOW GRANTS FOR CURRENT_USER()') as \$r) echo implode(' ', (array) \$r), PHP_EOL;"
```

- The migration creates only `cache` and `cache_locks` (`key`, `value`/`owner`,
  `expiration`); it does not reference any player table. If `migrate:status`
  shows it pending, `migrate --force` adds the two tables and nothing else.
- Needed privileges: SELECT, INSERT, UPDATE, DELETE on those two tables — the
  same CRUD the app already has on `sessions` (`SESSION_DRIVER=database`).
- Concurrency: `DatabaseStore::increment` runs inside a transaction with
  `lockForUpdate` (row lock on MariaDB), `add` relies on the primary key, so
  two simultaneous guesses count as two. `withoutOverlapping()` locks use
  `cache_locks` (`tests/Feature/DatabaseCacheStoreTest`).
- The health probe writes/reads/deletes one row and skips the file scan.
- Growth: one row per distinct limiter key; expired rows are pruned hourly by
  `ballspot:cleanup-login-codes` (only rows whose expiration passed).
- Switching resets in-flight rate-limit windows once. `storage/framework/
  cache/data` stays as it is — unused, not deleted.

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
- The daily `scheduled → active` step is manual unless
  `BALLPICKER_AUTO_ACTIVATE_DAILIES=true` (then `ballspot:activate-daily` runs
  at 00:10 and only ever touches today's row).
- `ballspot:backup-content` never dumps MySQL; the database backup is the
  separate root cron in `docs/db-backup-runbook.md`.

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
   `verification_failed {reason: session_mismatch}` = the phone still held a
   previous account's token (the app now detects this and offers "Log in
   again"). The context also shows `live_codes`, `latest_code_age_seconds` and
   `attempts`, which is enough to tell "typed the old email" from "no code was
   ever sent" without ever logging the code.
3. Emergency switch: `BALLPICKER_REQUIRE_EMAIL_VERIFICATION=false` +
   `php artisan config:cache` disables the code step entirely (new accounts
   verified at once, existing unverified accounts let in).

### "Delete account fails"
`grep account.delete.failed storage/logs/ballpicker-events-*.log` → the context
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
