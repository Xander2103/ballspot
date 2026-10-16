#!/usr/bin/env bash
# =============================================================================
# BallPicker — /var/www/ballpicker/deploy.sh (the entry point operators type)
#
# Install once, as root, AFTER backend/scripts/ exists in the checkout
# (docs/ops-runbook.md §5.2):
#   sudo install -m 0755 backend/scripts/deploy-wrapper.sh /var/www/ballpicker/deploy.sh
#
# The installed copy is NOT tracked by git, so `git reset --hard` never
# touches it. All the logic lives in the versioned backend/scripts/deploy.sh;
# this file only checks that the scripts are present and executable and then
# hands over. It never falls back to the pre-2026-10-15 script (kept under
# /var/backups/ballpicker/), because that one ran artisan as `deploy`.
# =============================================================================

set -u

APP_ROOT="${BALLPICKER_APP_ROOT:-/var/www/ballpicker}"
SCRIPTS="$APP_ROOT/backend/scripts"

for f in "$SCRIPTS/deploy.sh" "$SCRIPTS/fix-permissions.sh"; do
  if [[ ! -f "$f" ]]; then
    echo "deploy: $f is missing — this checkout predates the deploy scripts (rolled back?)." >&2
    echo "        Restore them without touching anything else:" >&2
    echo "          cd $APP_ROOT && git fetch origin && git checkout origin/master -- backend/scripts" >&2
    exit 1
  fi
  if [[ ! -x "$f" ]]; then
    echo "deploy: $f is not executable — run: chmod 0755 $f" >&2
    exit 1
  fi
done

bash -n "$SCRIPTS/deploy.sh" || { echo "deploy: $SCRIPTS/deploy.sh has a syntax error — not starting" >&2; exit 1; }

export BALLPICKER_DEPLOY_ENTRY="$APP_ROOT/deploy.sh"
exec bash "$SCRIPTS/deploy.sh" "$@"
