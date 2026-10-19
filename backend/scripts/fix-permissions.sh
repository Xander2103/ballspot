#!/usr/bin/env bash
# =============================================================================
# BallPicker — ownership/permissions of the writable Laravel directories
#
#   sudo scripts/fix-permissions.sh            repair (idempotent, no data touched)
#   sudo scripts/fix-permissions.sh --check    report only; exit 1 if anything is wrong
#
# The model: application code is owned by the deploy user (whoever owns the
# backend directory) and read-only for the web user; everything Laravel
# WRITES — storage/ and bootstrap/cache/ — belongs to the web user (www-data).
# Directories get 2775 (setgid, so files created by anyone in the group keep
# the group), files 0664. .env becomes <code owner>:www-data 640. Nothing is
# ever made world-writable, and no file content is touched.
#
# Why this exists (2026-10-15): the scheduler cron ran as root, so
# withoutOverlapping() locks created root-owned directories under
# storage/framework/cache/data. php-fpm could not write into them and every
# player whose rate-limit key hashed there got a 500 on Start Pack and on
# every guess. Earlier (2026-09-07) the same mistake made the day's events
# log root-owned. This script repairs both and `--check` catches them early.
#
# Rate-limit counters, sessions and compiled views are NOT deleted — only
# ownership and modes change.
#
# Exit codes: 0 ok | 1 problems found (--check) or wrong usage | 2 cannot run
# =============================================================================

set -Eeuo pipefail

BACKEND_DIR="${BALLPICKER_BACKEND_DIR:-/var/www/ballpicker/backend}"
WEB_USER="${BALLPICKER_WEB_USER:-www-data}"
WEB_GROUP="${BALLPICKER_WEB_GROUP:-$WEB_USER}"
WRITABLE_DIRS=(storage bootstrap/cache)
# Subdirectories Laravel expects; created if missing so the first request /
# first cron run never has to create them (as whichever user that happens to be).
EXPECTED_DIRS=(
  storage/app storage/app/public storage/app/private storage/app/private/clubs storage/logs
  storage/framework storage/framework/cache storage/framework/cache/data
  storage/framework/sessions storage/framework/views storage/framework/testing
  bootstrap/cache
)

CHECK=0
case "${1:-}" in
  --check) CHECK=1 ;;
  "") ;;
  *) echo "usage: $0 [--check]" >&2; exit 1 ;;
esac

log() { printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }

cd "$BACKEND_DIR" 2>/dev/null || { echo "backend dir not found: $BACKEND_DIR" >&2; exit 2; }
id -u "$WEB_USER" >/dev/null 2>&1 || { echo "no such user: $WEB_USER" >&2; exit 2; }
if [[ $CHECK -eq 0 && $EUID -ne 0 ]]; then
  echo "repair needs root (sudo); use --check to only report" >&2; exit 2
fi

problems=0

# ---- report ---------------------------------------------------------------
for dir in "${WRITABLE_DIRS[@]}"; do
  if [[ ! -d "$dir" ]]; then
    log "MISSING   $dir"; problems=$((problems + 1)); continue
  fi
  wrong_owner=$(find "$dir" \( ! -user "$WEB_USER" -o ! -group "$WEB_GROUP" \) | wc -l | tr -d ' ')
  world_w=$(find "$dir" -perm -o+w ! -type l | wc -l | tr -d ' ')
  unwritable=$(sudo -u "$WEB_USER" find "$dir" \( -type d -o -type f \) ! -writable 2>/dev/null | wc -l | tr -d ' ')
  log "$dir: not owned by $WEB_USER:$WEB_GROUP=$wrong_owner  world-writable=$world_w  not writable by $WEB_USER=$unwritable"
  problems=$((problems + wrong_owner + world_w + unwritable))
done
for dir in "${EXPECTED_DIRS[@]}"; do
  [[ -d "$dir" ]] || { log "MISSING   $dir"; problems=$((problems + 1)); }
done
CODE_USER=$(stat -c '%U' "$BACKEND_DIR" 2>/dev/null || stat -f '%Su' "$BACKEND_DIR")
if [[ -f .env ]]; then
  env_mode=$(stat -c '%a' .env 2>/dev/null || stat -f '%Lp' .env)
  env_group=$(stat -c '%G' .env 2>/dev/null || stat -f '%Sg' .env)
  if [[ "$env_mode" =~ [0-9][0-9][4-7]$ || "$env_group" != "$WEB_GROUP" ]]; then
    log "WARNING   .env is $env_mode $(stat -c '%U' .env 2>/dev/null || stat -f '%Su' .env):$env_group — wanted 640 $CODE_USER:$WEB_GROUP"
    problems=$((problems + 1))
  fi
fi

if [[ $CHECK -eq 1 ]]; then
  if [[ $problems -eq 0 ]]; then log "OK — nothing to fix"; exit 0; fi
  log "PROBLEMS  $problems — run: sudo $0"; exit 1
fi

# ---- repair ---------------------------------------------------------------
for dir in "${EXPECTED_DIRS[@]}"; do
  install -d -o "$WEB_USER" -g "$WEB_GROUP" -m 2775 "$dir"
done
for dir in "${WRITABLE_DIRS[@]}"; do
  chown -R "$WEB_USER:$WEB_GROUP" "$dir"
  find "$dir" -type d -exec chmod 2775 {} +
  find "$dir" -type f -exec chmod 0664 {} +
done
# A heartbeat or log left by root/deploy is now owned by the web user, so the
# cron (once it runs as the web user) can replace/append to it.
log "repaired ownership ($WEB_USER:$WEB_GROUP) and modes (dirs 2775, files 0664) under: ${WRITABLE_DIRS[*]}"
if [[ -f .env ]]; then
  chown "$CODE_USER:$WEB_GROUP" .env && chmod 640 .env
  log ".env -> $CODE_USER:$WEB_GROUP 640 (content untouched)"
fi

# Verify as the web user — this is what php-fpm will experience.
left=$(sudo -u "$WEB_USER" find "${WRITABLE_DIRS[@]}" \( -type d -o -type f \) ! -writable 2>/dev/null | wc -l | tr -d ' ')
if [[ "$left" != "0" ]]; then
  log "STILL NOT WRITABLE by $WEB_USER: $left entries (ACLs? immutable bits? different mount?)"; exit 1
fi
log "OK — everything under ${WRITABLE_DIRS[*]} is writable by $WEB_USER"
