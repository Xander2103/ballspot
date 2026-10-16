#!/usr/bin/env bash
# =============================================================================
# BallPicker — production deploy, run as the `deploy` user
#
#   /var/www/ballpicker/deploy.sh                 (wrapper → this script)
#   backend/scripts/deploy.sh                     deploy origin/master
#   backend/scripts/deploy.sh --ref=<commit>      deploy/roll back to that commit
#   backend/scripts/deploy.sh --skip-composer     app-code-only hotfix (vendor unchanged)
#   backend/scripts/deploy.sh --no-maintenance    skip `artisan down/up`
#   backend/scripts/deploy.sh --help
#
# The steps mirror the original /var/www/ballpicker/deploy.sh (reviewed
# 2026-10-15, docs/ops-runbook.md §5.0). Three changes matter:
#
#   1. EVERY `php artisan` runs as www-data (sudo -u www-data). The original
#      ran them as `deploy`, which created deploy-owned, group-unwritable
#      files and directories under storage/ that php-fpm could not write —
#      the 2026-09-07 events-log and 2026-10-15 cache/data → Start Pack 500
#      incidents. The rule has no exceptions: `deploy` never runs PHP.
#   2. composer runs with --no-scripts and `package:discover` then runs as
#      www-data (the composer hooks would write bootstrap/cache as deploy).
#   3. No `optimize:clear` / `cache:clear` — they wipe every rate-limit
#      counter and scheduler lock. `view:clear` + the three *:cache rebuilds
#      are what the app needs.
#
# Kept as before: maintenance mode, `git reset --hard origin/master`,
# BadgeSeeder, sprint-badge backfill, queue:restart + supervisor restart,
# php-fpm + nginx reload, post-deploy curl checks. Added: the ownership repair
# (scripts/fix-permissions.sh) BEFORE the first artisan call and a permission
# + health check at the end.
#
# Maintenance mode is ALWAYS lifted again: after a failed step, on Ctrl-C or a
# lost terminal (INT/TERM/HUP) and on any unexpected error the EXIT trap runs
# `artisan up`; if that fails too it removes storage/framework/down and
# maintenance.php directly. Only `kill -9` can leave the app down — then run:
#   sudo -u www-data php artisan up      (or: sudo rm -f storage/framework/down)
#
# Rollback: step 1 prints the previous commit; `--ref=<that commit>` resets to
# it and runs every step again. The whole file is parsed before anything runs
# (main() at the bottom) and fix-permissions.sh is copied aside first, so a
# rollback to a commit that has no scripts/ directory still completes.
#
# Ownership model (§5.1): code and vendor/ belong to `deploy` (world-readable),
# storage/ and bootstrap/cache/ belong to www-data (dirs 2775, files 664),
# `deploy` is a member of group www-data, .env is deploy:www-data 640.
#
# Never run here, by design: migrate:fresh, db:wipe, db:seed without --class,
# cache:clear, optimize:clear.
#
# Exit codes:
#   0  deployed and every check passed. A scheduler heartbeat that is missing
#      or stale right after the deploy is tolerated (the www-data cron writes
#      it within 5 minutes) — step 14 says so explicitly.
#   1  a deploy step failed. The app was brought back up and is LIVE ON
#      WHATEVER THE FAILED STEP LEFT (the STATE: line says exactly what). The
#      script never reverts anything itself — this is not a rollback. Read
#      DEPLOY FAILED + STATE, then either run again (forward, idempotent) or
#      roll back with --ref=<previous commit>; docs/ops-runbook.md §5.3.
#   2  deployed and up, but a post-deploy check failed (permissions, or a
#      health check other than the heartbeat). Fix before announcing.
# =============================================================================

set -Eeuo pipefail

APP_ROOT="${BALLPICKER_APP_ROOT:-/var/www/ballpicker}"
BACKEND="${BALLPICKER_BACKEND_DIR:-$APP_ROOT/backend}"
BRANCH="${BALLPICKER_BRANCH:-master}"
WEB_USER="${BALLPICKER_WEB_USER:-www-data}"
WEB_URL="${BALLPICKER_WEB_URL:-https://ballpicker.vanmalderstudio.be}"
HEALTH_URL="${BALLPICKER_HEALTH_URL:-$WEB_URL/api/health}"
FPM_UNIT="${BALLPICKER_FPM_UNIT:-php8.3-fpm}"
PHP_BIN="${BALLPICKER_PHP:-php}"
COMPOSER_BIN="${BALLPICKER_COMPOSER:-composer}"
ENTRY="${BALLPICKER_DEPLOY_ENTRY:-$APP_ROOT/deploy.sh}"   # what to type for a rollback (the wrapper sets it)

REF=""; SKIP_COMPOSER=0; MAINTENANCE=1
IN_MAINTENANCE=0; SNAP=""; BEFORE="unknown"; NEW=""; STAGE=0; STARTED=0; POST_PROBLEMS=0
HEALTH_CHECKS="database|storage|cache|queue|scheduler"

usage() {
  cat <<EOF
usage: $ENTRY [--ref=<commit>] [--skip-composer] [--no-maintenance] [--help]
  --ref=<commit>     deploy / roll back to that commit instead of origin/$BRANCH
  --skip-composer    app-code-only hotfix (vendor/ unchanged)
  --no-maintenance   skip artisan down/up
exit 0 deployed and healthy | 1 a step failed (app brought back up) | 2 deployed, but a post-deploy check failed
EOF
}

step()    { printf '\n==> %s\n' "$*"; }
as_web()  { sudo -u "$WEB_USER" -H "$@"; }
artisan() { as_web "$PHP_BIN" artisan "$@"; }

# `artisan up`, and when even that fails, the flag files themselves.
lift_maintenance() {
  [[ $IN_MAINTENANCE -eq 1 ]] || return 0
  if artisan up; then IN_MAINTENANCE=0; return 0; fi
  echo "artisan up failed — removing the maintenance flag files directly" >&2
  if sudo rm -f "$BACKEND/storage/framework/down" "$BACKEND/storage/framework/maintenance.php"; then
    IN_MAINTENANCE=0
    echo "Maintenance mode lifted (storage/framework/down removed)." >&2
    return 0
  fi
  echo "COULD NOT LIFT MAINTENANCE MODE — run now: sudo rm -f $BACKEND/storage/framework/down $BACKEND/storage/framework/maintenance.php" >&2
  return 1
}

# What the server is left with when a step fails (STAGE advances as steps succeed).
state_text() {
  case $STAGE in
    0) echo "nothing changed — the code is still $BEFORE" ;;
    1) echo "code is $NEW (was $BEFORE); vendor/, permissions and the config/route/view caches are still those of $BEFORE" ;;
    2) echo "code and vendor/ are $NEW; permissions and the config/route/view caches are still those of $BEFORE" ;;
    3) echo "code, vendor/ and permissions are $NEW; migrations not run; config/route/view caches are still those of $BEFORE" ;;
    4) echo "code, vendor/, permissions and migrations are $NEW; config/route/view caches are still those of $BEFORE" ;;
    5) echo "code, vendor/, migrations and caches are $NEW; queue worker, php-fpm and nginx are not reloaded yet" ;;
    *) echo "fully deployed: $NEW" ;;
  esac
}

# Runs on every exit: normal end, die(), set -e, Ctrl-C, lost terminal.
cleanup() {
  local rc=$?
  trap - ERR INT TERM HUP
  set +e
  if [[ $IN_MAINTENANCE -eq 1 ]]; then
    echo "Bringing the app back up before exiting." >&2
    lift_maintenance
  fi
  if [[ $STARTED -eq 1 && $rc -ne 0 && $rc -ne 2 ]]; then
    echo >&2
    echo "STATE: $(state_text)" >&2
    if [[ $STAGE -eq 0 ]]; then
      echo "Nothing to roll back. Fix the cause, then run again:  $ENTRY" >&2
    else
      echo "This is NOT a rollback — nothing was reverted, and the app is live on that mixed state. Within minutes, do one of:" >&2
      echo "  deploy forward (idempotent):   $ENTRY" >&2
      echo "  roll back to the old commit:   $ENTRY --ref=$BEFORE" >&2
    fi
  fi
  [[ -n "$SNAP" ]] && rm -rf "$SNAP"
  exit "$rc"
}
die() { printf 'DEPLOY FAILED: %s\n' "$*" >&2; exit 1; }

trap cleanup EXIT
trap 'die "unexpected error at line $LINENO"' ERR
trap 'printf "\nDEPLOY INTERRUPTED\n" >&2; exit 130' INT TERM HUP

main() {
  local arg fix_perms failing
  for arg in "$@"; do
    case "$arg" in
      --ref=*) REF="${arg#--ref=}" ;;
      --skip-composer) SKIP_COMPOSER=1 ;;
      --no-maintenance) MAINTENANCE=0 ;;
      -h|--help) usage; exit 0 ;;
      *) usage >&2; exit 1 ;;
    esac
  done

  [[ $EUID -ne 0 ]] || die "run as the deploy user, not root (git/composer must not create root-owned files in the checkout)"
  cd "$BACKEND" 2>/dev/null || die "backend dir not found: $BACKEND"
  [[ -f .env ]] || die ".env missing in $BACKEND"
  id -u "$WEB_USER" >/dev/null 2>&1 || die "no such user: $WEB_USER"
  sudo -v || die "sudo is required (chown, supervisorctl, systemctl, artisan as $WEB_USER)"
  STARTED=1

  # Keep a copy of fix-permissions.sh: step 3 may reset to a commit without scripts/ (rollback).
  SNAP=$(mktemp -d "${TMPDIR:-/tmp}/ballpicker-deploy.XXXXXX") || die "mktemp failed"
  [[ -f scripts/fix-permissions.sh ]] && cp scripts/fix-permissions.sh "$SNAP/"

  echo "=================================================="
  echo " BallPicker deploy — $(date '+%Y-%m-%d %H:%M:%S') on $(hostname)"
  echo " Branch: $BRANCH   Backend: $BACKEND   Web user: $WEB_USER   Running as: $(id -un)"
  echo "=================================================="

  step "1. Current version"
  BEFORE=$(git rev-parse --short HEAD 2>/dev/null || echo 'not-a-git-checkout')
  echo "$BEFORE"; git status --short
  echo "   rollback later with: $ENTRY --ref=$BEFORE"

  if [[ $MAINTENANCE -eq 1 ]]; then
    step "2. Maintenance mode on (the monitor treats this as maintenance for 10 min before alerting)"
    IN_MAINTENANCE=1   # set before the call: a half-written down file must be lifted too
    artisan down --retry=30 || echo "   artisan down failed — continuing; 'up' at the end is harmless"
  fi

  step "3. Code: $( [[ -n "$REF" ]] && echo "reset to $REF" || echo "reset to origin/$BRANCH" )"
  git fetch origin || die "git fetch failed"
  NEW="${REF:-origin/$BRANCH} (the reset itself failed — check: git -C $BACKEND status)"; STAGE=1
  if [[ -n "$REF" ]]; then
    git reset --hard "$REF" || die "cannot reset to $REF"
  else
    git reset --hard "origin/$BRANCH" || die "git reset --hard origin/$BRANCH failed"
  fi
  NEW=$(git rev-parse --short HEAD 2>/dev/null || echo "${REF:-origin/$BRANCH}")

  if [[ $SKIP_COMPOSER -eq 0 ]]; then
    step "4. composer install --no-dev --no-scripts (as $(id -un); vendor/ is read-only for $WEB_USER)"
    "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts \
      || die "composer install failed"
  fi
  STAGE=2

  step "5. Ownership: storage/ and bootstrap/cache/ -> $WEB_USER, .env 640 (scripts/fix-permissions.sh)"
  fix_perms="$BACKEND/scripts/fix-permissions.sh"
  if [[ ! -f "$fix_perms" ]]; then
    [[ -f "$SNAP/fix-permissions.sh" ]] || die "scripts/fix-permissions.sh is missing in this checkout and no copy existed before the reset"
    fix_perms="$SNAP/fix-permissions.sh"
    echo "   (this commit has no scripts/fix-permissions.sh — using the copy taken before the reset)"
  fi
  sudo env BALLPICKER_BACKEND_DIR="$BACKEND" BALLPICKER_WEB_USER="$WEB_USER" bash "$fix_perms" \
    || die "fix-permissions.sh failed"
  STAGE=3

  if [[ $SKIP_COMPOSER -eq 0 ]]; then
    step "6. package:discover (the composer hook, now as $WEB_USER)"
    artisan package:discover --ansi || die "package:discover failed"
  fi

  step "7. Migrations (additive only — never migrate:fresh)"
  artisan migrate --force || die "migrate failed"
  STAGE=4

  step "8. BadgeSeeder (idempotent updateOrCreate)"
  artisan db:seed --class=BadgeSeeder --force || die "BadgeSeeder failed"

  step "9. Backfill sprint badges (non-fatal, as before)"
  artisan ballspot:backfill-sprint-badges || true

  step "10. Rebuild caches (no optimize:clear — rate-limit counters and locks are kept)"
  artisan view:clear   || die "view:clear failed"
  artisan config:cache || die "config:cache failed"
  artisan route:cache  || die "route:cache failed"
  artisan view:cache   || die "view:cache failed"
  artisan storage:link >/dev/null 2>&1 || true
  STAGE=5

  step "11. Queue worker: graceful restart"
  artisan queue:restart || true
  if command -v supervisorctl >/dev/null 2>&1; then
    sudo supervisorctl restart all || echo "   supervisorctl restart failed — check: sudo supervisorctl status"
  fi

  step "12. Reload services"
  sudo systemctl reload "$FPM_UNIT" || echo "   reload $FPM_UNIT failed — check: systemctl status $FPM_UNIT"
  sudo systemctl reload nginx       || echo "   reload nginx failed — check: systemctl status nginx"
  STAGE=6

  if [[ $IN_MAINTENANCE -eq 1 ]]; then
    step "13. Maintenance mode off"
    lift_maintenance || die "could not lift maintenance mode"
  fi

  step "14. Post-deploy checks (the app is up from here on; a PROBLEM below sets exit code 2, never a rollback)"
  echo "Commit: $(git rev-parse HEAD) (was $BEFORE)"
  echo "Pending migrations: $(artisan migrate:status | grep -ci pending || true)"
  echo
  echo "Permissions as $WEB_USER:"
  if ! sudo env BALLPICKER_BACKEND_DIR="$BACKEND" BALLPICKER_WEB_USER="$WEB_USER" bash "$fix_perms" --check; then
    POST_PROBLEMS=$((POST_PROBLEMS + 1))
    echo "   PROBLEM — something still writes to storage/ as another user."
    echo "            Repair now:  sudo bash $fix_perms"
    echo "            Then find the writer: the scheduler cron belongs in $WEB_USER's crontab, supervisor programs need user=$WEB_USER (runbook §5.1/§5.2)."
  fi
  echo
  if artisan list --raw 2>/dev/null | grep -q '^ballspot:health'; then
    echo "Health (php artisan ballspot:health as $WEB_USER):"
    if ! artisan ballspot:health; then
      failing=$(artisan ballspot:health --json 2>/dev/null | grep -oE "\"($HEALTH_CHECKS)\": *false" | cut -d'"' -f2 | paste -sd, - || true)
      if [[ "$failing" == "scheduler" ]]; then
        echo "   Only the scheduler heartbeat is missing or stale — expected right after a deploy: the $WEB_USER cron writes it within 5 min."
        echo "   Verify in 5 min:  sudo -u $WEB_USER $PHP_BIN artisan ballspot:health   (still red → the cron is not running as $WEB_USER, runbook §5.2)"
      else
        POST_PROBLEMS=$((POST_PROBLEMS + 1))
        echo "   PROBLEM — failing checks: ${failing:-unknown}. Fix before announcing (runbook §5.4)."
      fi
    fi
  else
    echo "Health: ballspot:health is not part of this version — skipped."
  fi
  echo
  echo "Health over HTTP:"
  curl -s -m 15 "$HEALTH_URL" || true
  echo
  echo
  echo "Schedule:"
  artisan schedule:list || true
  echo
  if command -v supervisorctl >/dev/null 2>&1; then echo "Supervisor:"; sudo supervisorctl status || true; echo; fi
  echo "CORS evil origin check. Expected: no output below."
  curl -s -m 15 -D - -o /dev/null "$HEALTH_URL" -H "Origin: https://evil.example.com" | grep -i "access-control-allow-origin" || true
  echo
  echo "CORS good origin check. Expected: Access-Control-Allow-Origin: $WEB_URL"
  curl -s -m 15 -D - -o /dev/null "$HEALTH_URL" -H "Origin: $WEB_URL" | grep -i "access-control-allow-origin" || true
  echo
  echo "Privacy push token check. Expected: > 0"
  curl -s -m 15 "$WEB_URL/privacy" | grep -ci "push token" || true

  echo
  echo "=================================================="
  if [[ $POST_PROBLEMS -gt 0 ]]; then
    echo " Deploy complete: $(git rev-parse --short HEAD) — the app is UP, but $POST_PROBLEMS post-deploy check(s) failed (see PROBLEM above). Exit 2."
    echo "=================================================="
    exit 2
  fi
  echo " Deploy complete: $(git rev-parse --short HEAD) — now open /admin/diagnostics and clear every red warning"
  echo "=================================================="
}

main "$@"
