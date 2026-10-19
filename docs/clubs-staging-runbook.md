# BallPicker Clubs — staging deploy, verification & rollback runbook

For the **isolated local staging stack** in `staging/`. Read `docs/clubs-staging-plan.md` first. **Every step below requires
explicit approval before it is executed for the first time.**

Hard rules:
- Never `migrate:fresh`, `migrate:reset`, `db:wipe` or `migrate:refresh` against an existing database. The only exception is
  §7 teardown, which deletes the staging Docker volume itself.
- Never copy production `.env`, database dumps or storage into staging.
- Never point `DB_*`, `MAIL_*` or the app's `EXPO_PUBLIC_*` at production.
- Every artisan command runs **inside the `app` container** (`docker compose … exec app php artisan …`), never on the host, so it
  can only reach the staging DB.

```bash
# Shorthand used below (run from the repository root)
export DC="docker compose -f staging/docker-compose.yml --env-file staging/.env"
```

## 1. One-time preparation
```bash
# 1a. Dedicated worktree pinned to the branch under test (keeps the main checkout untouched)
git worktree add ../ballspot-staging feature/ballpicker-clubs
git -C ../ballspot-staging log --oneline -1          # note the commit under test

# 1b. Compose secrets (gitignored)
cp staging/.env.compose.example staging/.env          # fill STAGING_DB_PASSWORD / STAGING_DB_ROOT_PASSWORD
                                                      # set STAGING_DB_IMAGE to the prod engine/version

# 1c. Backend .env for staging (never the production one)
cp staging/backend.env.staging.example ../ballspot-staging/backend/.env
#    fill DB_PASSWORD (= STAGING_DB_PASSWORD), BALLPICKER_BETA_CODE, BALLPICKER_STAGING_SEED_PASSWORD;
#    APP_URL / BALLSPOT_WEB_URL / FRONTEND_URL after the tunnel URL is known (§2c)
```

## 2. Start + deploy (first time)
```bash
# 2a. Build and start (no tunnel yet)
$DC up -d --build db mailpit app scheduler web
$DC ps

# 2b. Dependencies, key, permissions (inside the container)
$DC exec app composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
$DC exec app php artisan key:generate --force          # staging-only key
$DC exec app sh -c 'mkdir -p storage/app/public storage/app/private/clubs storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache && chown -R www-data:www-data storage bootstrap/cache'
$DC exec app php artisan storage:link

# 2c. Optional HTTPS tunnel for devices: read the https URL from the logs, then put it in the staging .env
$DC --profile tunnel up -d tunnel
$DC logs tunnel | grep -o 'https://[a-z0-9-]*\.trycloudflare\.com' | tail -1
#    edit ../ballspot-staging/backend/.env: APP_URL, BALLSPOT_WEB_URL, FRONTEND_URL = that URL
$DC restart app scheduler

# 2d. Safety check BEFORE migrating: the app must see the staging DB, nothing else
$DC exec app php artisan about --only=environment,drivers
$DC exec app php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $c=config("database.default"); echo config("app.env")," ",config("database.connections.$c.host"),"/",config("database.connections.$c.database"),"\n";'
#    expect: "staging db/ballpicker_staging"

# 2e. Migrations (empty staging DB → plain migrate)
$DC exec app php artisan migrate:status                 # all Pending on first run
$DC exec app php artisan migrate --pretend | tail -40   # review the SQL (optional)
$DC exec app php artisan migrate --force
$DC exec app php artisan migrate:status | grep -c Pending   # expect 0

# 2f. Content + QA data (see docs/clubs-staging-seed.md). The QA seeder runs the base content seeders itself;
#     it refuses unless APP_ENV=staging, the DB name contains "staging", SEED_CONFIRM=yes and every user is a QA account.
$DC exec -e BALLPICKER_STAGING_SEED_DRY_RUN=yes app php artisan db:seed --class=Database\\Seeders\\ClubsStagingSeeder --force
$DC exec app php artisan db:seed --class=Database\\Seeders\\ClubsStagingSeeder --force
```

## 3. Smoke verification (before any device test)
```bash
URL=https://<tunnel-or-staging-host>
curl -s $URL/api/health | head -c 300; echo
curl -s $URL/api/config | grep -o '"clubs":[a-z]*'               # "clubs":true
$DC exec app php artisan schedule:list | grep -i clubs            # competition-tick every minute, reconcile nightly
$DC logs --tail=20 scheduler                                      # tick output, no errors
open http://127.0.0.1:8025                                        # Mailpit: empty until the first mail
```
Then complete the manual smoke steps in `docs/clubs-staging-plan.md` §6: portal 2FA mail in Mailpit, a signed image loading,
an unsigned image refused, and the device request visible in `$DC logs web`.

## 4. Redeploy a newer commit
```bash
# 4a. Backup first: DB dump + private/public storage, kept OUTSIDE the repo
mkdir -p ~/bp-staging-backups && STAMP=$(date +%Y%m%d-%H%M%S)
$DC exec db sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction ballpicker_staging' | gzip > ~/bp-staging-backups/db-$STAMP.sql.gz
$DC exec app tar -C storage/app -czf - public private > ~/bp-staging-backups/storage-$STAMP.tgz
gunzip -t ~/bp-staging-backups/db-$STAMP.sql.gz && echo dump-ok

# 4b. Update code
git -C ../ballspot-staging fetch --all 2>/dev/null; git -C ../ballspot-staging checkout <commit-or-branch>
$DC exec app composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

# 4c. Migrations with verification
$DC exec app php artisan migrate:status | grep Pending           # what will run
$DC exec app php artisan migrate --pretend
$DC exec app php artisan migrate --force
$DC exec app php artisan migrate:status | grep -c Pending        # 0
$DC exec app php artisan view:clear && $DC restart app scheduler
```
Then repeat §3.

## 5. Mobile: dev build against staging (local builds, not EAS)
```bash
cd mobile
export EXPO_PUBLIC_API_BASE_URL=https://<staging-host>/api
export EXPO_PUBLIC_API_URL=https://<staging-host>/api
export EXPO_PUBLIC_WEB_URL=https://<staging-host>
# iOS (device connected, Xcode signing team selected on first run)
npx expo run:ios --device
# Android (device in USB debugging mode)
export PATH="$HOME/Library/Android/sdk/platform-tools:$PATH"; adb devices
npx expo run:android --device
# Later sessions: just start Metro with the same exports
npx expo start --dev-client
```
`expo run:*` generates the gitignored `ios/` / `android/` folders (CNG).

Never start Metro without the three exports: an empty value falls back to **production**. Check the first request in
`$DC logs -f web` before testing.

Push notifications on iOS need an EAS development build with the EAS-managed APNs credentials. That is on hold until approved;
see the plan, blocker S5.

## 6. Rollback (staging)
Preferred, and safe for every Clubs migration:
```bash
# 6a. Code back to the previous commit
git -C ../ballspot-staging checkout <previous-commit>
# 6b. Schema: roll back only the batch that the redeploy added (verified on MariaDB + SQLite for the 18 Clubs migrations)
$DC exec app php artisan migrate:status | tail -25                # find the last batch number
$DC exec app php artisan migrate:rollback --force                 # one batch
$DC restart app scheduler
```
If a rollback fails or data must return to a known point, restore the backup from §4a. That replaces only the staging DB:
```bash
gunzip -c ~/bp-staging-backups/db-<STAMP>.sql.gz | $DC exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" ballpicker_staging'
$DC exec -T app tar -C storage/app -xzf - < ~/bp-staging-backups/storage-<STAMP>.tgz
```
Known limitation: the pre-sprint migration `2026_07_30_000001_add_missing_query_indexes::down()` fails on MySQL/MariaDB. Never roll
back further than the Clubs migrations.

## 7. Push QA session (only when approved)
```bash
# enable, test, disable — staging DB holds QA device tokens only
sed -i '' 's/^BALLPICKER_PUSH_ENABLED=.*/BALLPICKER_PUSH_ENABLED=true/' ../ballspot-staging/backend/.env && $DC restart app scheduler
#   … run checklist section N …
sed -i '' 's/^BALLPICKER_PUSH_ENABLED=.*/BALLPICKER_PUSH_ENABLED=false/' ../ballspot-staging/backend/.env && $DC restart app scheduler
$DC exec app php artisan tinker --execute='echo App\Models\PushToken::count();'   # read-only count of QA device tokens
```

## 8. Teardown (staging only)
```bash
$DC --profile tunnel down                  # stop everything
$DC down -v                                # ALSO deletes the staging DB + storage volumes (staging only!)
git worktree remove ../ballspot-staging
```

## 9. Production deploy (for later, after the P0 blockers are closed)
This happens on the production server, following `docs/ops-runbook.md` §5.2 and `docs/clubs-release-readiness.md` §10. It needs a
separate explicit go-ahead.
1. Back up the DB and content.
2. Deploy code.
3. `migrate --force` with the flag off.
4. Run `fix-permissions.sh`.
5. Check the scheduler.
6. Check mail and push.
7. Turn the flag on.
8. Smoke test.
9. Ship the new app build.
