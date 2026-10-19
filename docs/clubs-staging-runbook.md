# BallPicker Clubs — staging deploy, verification & rollback runbook

For the **isolated local staging stack** in `staging/`. Read `docs/clubs-staging-plan.md` first.

Hard rules:
- Never `migrate:fresh`, `migrate:reset`, `db:wipe` or `migrate:refresh` against an existing database. The only exception is
  §8 teardown, which deletes the staging Docker volumes themselves.
- Never copy production `.env`, dumps or storage into staging.
- Never point `DB_*`, `MAIL_*` or the app's `EXPO_PUBLIC_*` at production.
- Artisan runs **only inside the `app` container** (`staging/dc exec app php artisan …`), never on the host.

`staging/dc` = `docker compose -f staging/docker-compose.yml --env-file staging/.env` (works in zsh and bash).

## Design (why there are no bind mounts)
Docker Desktop's file sharing of `~/Documents` wedged the daemon on 2026-10-19: the `lock-directory` call timed out and no
container could start afterwards. The stack therefore uses **no bind mounts**:
- Backend code and `public/` are **baked into images** built from the dedicated git worktree, with `vendor` installed `--no-dev`
  at build time.
- The backend environment comes from `<worktree>/backend/.env` via `env_file`. The compose CLI reads it on the host; no `.env` file
  is inside any image.
- Storage and DB are named Docker volumes.
- nginx config is baked in. The `/club` basic-auth line is passed as `STAGING_PORTAL_HTPASSWD`.

A code change therefore means **rebuilding images** (§4).

## 1. One-time preparation (done 2026-10-19)
```bash
git worktree add --detach ../ballspot-staging <commit>     # the branch itself is checked out in the main repo
umask 077; mkdir -p staging/secrets
#   staging/.env:            STAGING_DB_PASSWORD, STAGING_DB_ROOT_PASSWORD, STAGING_WORKTREE, STAGING_DIR,
#                            STAGING_PORTAL_HTPASSWD='qa:<apr1 hash>' (single-quoted: contains $)
#   staging/secrets/:        qa_password, basic_password, beta_code, db_password (all 0600, gitignored)
#   staging/.qa-credentials: human-readable summary for testers (0600, gitignored)
#   <worktree>/backend/.env: from staging/backend.env.staging.example, APP_KEY generated on the host
```

## 2. Build + start + migrate + seed
```bash
staging/dc build                                         # app (php 8.3-fpm + code) and web (nginx + public)
staging/dc up -d db mailpit app scheduler web
staging/dc ps

# Safety check BEFORE migrating — must print: staging db/ballpicker_staging mariadb
staging/dc exec app php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $c=config("database.default"); echo config("app.env")," ",config("database.connections.$c.host"),"/",config("database.connections.$c.database")," ",config("database.connections.$c.driver"),"\n";'

staging/dc exec app php artisan migrate:status           # first run: all Pending
staging/dc exec app php artisan migrate --force          # plain, additive
staging/dc exec app php artisan migrate:status | grep -c Pending   # 0

# QA data — the seeder verifies the target itself (APP_ENV=staging, DB name contains "staging", only QA users, …)
staging/dc exec -e BALLPICKER_STAGING_SEED_CONFIRM=yes -e BALLPICKER_STAGING_SEED_DRY_RUN=yes \
  -e BALLPICKER_STAGING_SEED_PASSWORD="$(cat staging/secrets/qa_password)" app php artisan db:seed --class='Database\Seeders\ClubsStagingSeeder' --force
staging/dc exec -e BALLPICKER_STAGING_SEED_CONFIRM=yes \
  -e BALLPICKER_STAGING_SEED_PASSWORD="$(cat staging/secrets/qa_password)" app php artisan db:seed --class='Database\Seeders\ClubsStagingSeeder' --force
```

## 3. Tunnel + verification
```bash
staging/dc --profile tunnel up -d tunnel
staging/dc logs tunnel 2>&1 | grep -o 'https://[a-z0-9-]*\.trycloudflare\.com' | tail -1
# put that URL in <worktree>/backend/.env (APP_URL, BALLSPOT_WEB_URL, FRONTEND_URL), then:
staging/dc up -d --force-recreate app scheduler
staging/smoke.sh https://<words>.trycloudflare.com       # local + tunnel + API + signed media + portal 2FA + mail isolation
```
The tunnel only reaches nginx's **public allowlist server**:
- **Allowed:** `/api/*`, `/media/clubs/*` (signed), `/storage/*` (public disk, GET only), `/privacy`, `/terms`, `/support`,
  password-reset pages, and `/club/*` (behind basic auth `qa`, then the portal's own password and emailed code).
- **404:** everything else, including `/admin`, `/up`, `/sanctum`, `/` and dotfiles.

Mailpit (`http://127.0.0.1:8025`) and the platform admin (`http://127.0.0.1:8088/admin`) are reachable from this Mac only.

## 4. Redeploy a newer commit
```bash
# 4a. Backup first (outside the repo)
mkdir -p ~/bp-staging-backups && STAMP=$(date +%Y%m%d-%H%M%S)
staging/dc exec -T db sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction ballpicker_staging' | gzip > ~/bp-staging-backups/db-$STAMP.sql.gz
staging/dc exec -T app tar -C storage/app -czf - public private > ~/bp-staging-backups/storage-$STAMP.tgz
gunzip -t ~/bp-staging-backups/db-$STAMP.sql.gz && echo dump-ok
# 4b. New code, new images
git -C ../ballspot-staging checkout --detach <commit>
staging/dc build && staging/dc up -d app scheduler web
# 4c. Migrations with verification
staging/dc exec app php artisan migrate:status | grep Pending
staging/dc exec app php artisan migrate --pretend
staging/dc exec app php artisan migrate --force
staging/dc exec app php artisan migrate:status | grep -c Pending   # 0
```
Then repeat §3 (`smoke.sh`).

## 5. Mobile — STAGING app variant (separate identity)
`APP_VARIANT=staging` builds **BallPicker STG** with bundle id/package `be.vanmalderstudio.ballpicker.staging` and scheme
`ballpicker-staging`. It installs next to the store app.
- It shows an always-visible **STAGING · <api host>** label.
- It **refuses to use the production API**. Missing or production URLs resolve to an unroutable `.invalid` host, and the label turns
  red: "NO STAGING API CONFIGURED".
- Production builds (`APP_VARIANT` unset) are byte-identical to before (verified with `expo config`).

```bash
cd mobile
export APP_VARIANT=staging
export EXPO_PUBLIC_API_BASE_URL=https://<tunnel>/api EXPO_PUBLIC_API_URL=https://<tunnel>/api EXPO_PUBLIC_WEB_URL=https://<tunnel>
npx expo prebuild --clean                                  # ios/ + android/ are gitignored (CNG); revert prebuild's package.json "scripts" edits
# iOS simulator (debug, JS from Metro)
npx expo run:ios --device "iPhone 17e" --no-bundler && npx expo start --port 8081
# iOS physical device (release: JS + staging URL embedded, no Metro needed; team set in app.config.js for the staging variant)
npx expo run:ios --device "<device name>" --configuration Release --no-bundler
# Android (needs a JDK 17 toolchain: Homebrew openjdk@17)
export JAVA_HOME=/opt/homebrew/opt/openjdk@17/libexec/openjdk.jdk/Contents/Home ANDROID_HOME=$HOME/Library/Android/sdk
npx expo run:android --device Medium_Phone_API_37.0 --no-bundler
```
Push notifications need an EAS development build (approved in principle, **not triggered**). For Android, push additionally
needs a Firebase app for the staging package.

## 6. Rollback (staging)
```bash
git -C ../ballspot-staging checkout --detach <previous-commit> && staging/dc build && staging/dc up -d app scheduler web
staging/dc exec app php artisan migrate:rollback --force        # one batch (all 18 Clubs migrations verified reversible)
```
If a rollback fails, or the data must return to a known point, restore the §4a backup:
```bash
gunzip -c ~/bp-staging-backups/db-<STAMP>.sql.gz | staging/dc exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" ballpicker_staging'
staging/dc exec -T app tar -C storage/app -xzf - < ~/bp-staging-backups/storage-<STAMP>.tgz
```
Never roll back past the Clubs migrations: the pre-sprint `2026_07_30_000001::down()` fails on MySQL/MariaDB.

## 7. Push QA session (only after an approved EAS development build)
Set `BALLPICKER_PUSH_ENABLED=true` **and** remove the `EXPO_PUSH_URL` sink line in `<worktree>/backend/.env`, then
`staging/dc up -d --force-recreate app scheduler`. Afterwards put both back.

## 8. Teardown (staging only)
```bash
staging/dc --profile tunnel down
staging/dc down -v                        # deletes the staging DB + storage volumes (staging only!)
git worktree remove ../ballspot-staging
```
