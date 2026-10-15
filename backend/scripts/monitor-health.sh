#!/usr/bin/env bash
# =============================================================================
# BallPicker — independent 5-minute health monitor (plain bash + curl, so it
# keeps working when PHP, the database or the cache are the thing that broke)
#
#   */5 * * * * /var/www/ballpicker/backend/scripts/monitor-health.sh
#
# Each run fetches GET /api/health (one retry after 20 s, so a single blip
# never pages). "Healthy" = HTTP 200 and "status":"ok"; the endpoint answers
# 503 "degraded" with per-check booleans (database, storage, cache, queue,
# scheduler) when anything is wrong.
#
# A deploy's `php artisan down` answers 503 WITHOUT the health JSON; that is
# logged as maintenance and only becomes an incident after
# BALLPICKER_MAINTENANCE_GRACE_MINUTES (default 10).
#
# Alerting (to BALLPICKER_ALERT_EMAIL, default from the app config):
#   first failure        -> "[BallPicker] DOWN"       + incident start recorded
#   still failing        -> "[BallPicker] STILL DOWN" at most once per cooldown
#   first success after  -> "[BallPicker] RECOVERED"  with the outage duration
# Delivery: `php artisan ballspot:send-alert` as the web user (the app's SMTP),
# falling back to sendmail / mail(1) when artisan itself fails, else the log.
#
# State:  $STATE_DIR/incident (epoch of first failure), last_alert, last_ok,
#         maintenance_since
# Log:    $LOG_FILE — one line per failure / state change, never secrets
#
# Exit codes: 0 healthy | 1 unhealthy (so `run-parts`/cron mail also notice)
# =============================================================================

set -Eeuo pipefail

HEALTH_URL="${BALLPICKER_HEALTH_URL:-https://ballpicker.vanmalderstudio.be/api/health}"
BACKEND_DIR="${BALLPICKER_BACKEND_DIR:-/var/www/ballpicker/backend}"
WEB_USER="${BALLPICKER_WEB_USER:-www-data}"
ALERT_EMAIL="${BALLPICKER_ALERT_EMAIL:-}"                  # empty = app default (BALLPICKER_ALERT_EMAIL in .env)
STATE_DIR="${BALLPICKER_MONITOR_STATE_DIR:-/var/lib/ballpicker-monitor}"
LOG_FILE="${BALLPICKER_MONITOR_LOG:-/var/log/ballpicker-monitor.log}"
COOLDOWN_MIN="${BALLPICKER_ALERT_COOLDOWN_MINUTES:-60}"
MAINT_GRACE_MIN="${BALLPICKER_MAINTENANCE_GRACE_MINUTES:-10}"   # `php artisan down` during a deploy
TIMEOUT_SEC="${BALLPICKER_MONITOR_TIMEOUT:-15}"
RETRY_WAIT_SEC="${BALLPICKER_MONITOR_RETRY_WAIT:-20}"
PHP_BIN="${BALLPICKER_PHP:-php}"

mkdir -p "$STATE_DIR"
touch "$LOG_FILE" 2>/dev/null || LOG_FILE="$STATE_DIR/monitor.log"
body="$(mktemp)"; trap 'rm -f "$body"' EXIT

log() { printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >> "$LOG_FILE"; }

probe() { curl -sS -m "$TIMEOUT_SEC" -o "$body" -w '%{http_code}' "$HEALTH_URL" 2>/dev/null || echo 000; }

json_field() {   # json_field <name>  -> value of a top-level string field
  if command -v python3 >/dev/null 2>&1; then
    python3 -I -c 'import json,sys
try: print(json.load(open(sys.argv[1])).get(sys.argv[2], ""))
except Exception: print("")' "$body" "$1"
  else
    grep -o "\"$1\":\"[^\"]*\"" "$body" | head -n1 | cut -d'"' -f4
  fi
}
failing_checks() {
  if command -v python3 >/dev/null 2>&1; then
    python3 -I -c 'import json,sys
try: d=json.load(open(sys.argv[1])); print(",".join(k for k,v in d.get("checks",{}).items() if v is not True))
except Exception: print("")' "$body"
  else
    grep -o '"[a-z_]*":false' "$body" | cut -d'"' -f2 | paste -sd, -
  fi
}

send_alert() {   # send_alert <subject> <text>
  local subject="$1" text="$2" to_args=()
  [[ -n "$ALERT_EMAIL" ]] && to_args=(--to="$ALERT_EMAIL")
  if [[ -f "$BACKEND_DIR/artisan" ]]; then
    if [[ "$(id -un)" == "$WEB_USER" ]]; then
      printf '%s\n' "$text" | "$PHP_BIN" "$BACKEND_DIR/artisan" ballspot:send-alert --subject="$subject" --stdin ${to_args[@]+"${to_args[@]}"} >/dev/null 2>&1 && return 0
    else
      printf '%s\n' "$text" | sudo -u "$WEB_USER" -H "$PHP_BIN" "$BACKEND_DIR/artisan" ballspot:send-alert --subject="$subject" --stdin ${to_args[@]+"${to_args[@]}"} >/dev/null 2>&1 && return 0
    fi
  fi
  local to="${ALERT_EMAIL:-info@vanmalderstudio.be}"
  if command -v sendmail >/dev/null 2>&1; then
    printf 'To: %s\nSubject: %s\nContent-Type: text/plain; charset=UTF-8\n\n%s\n' "$to" "$subject" "$text" | sendmail -t && return 0
  fi
  if command -v mail >/dev/null 2>&1; then
    printf '%s\n' "$text" | mail -s "$subject" "$to" && return 0
  fi
  log "ALERT NOT DELIVERED (no artisan/sendmail/mail): $subject"
  return 1
}

# ---- probe (with one retry) ----------------------------------------------
code=$(probe)
status=$(json_field status)
if [[ "$code" != "200" || "$status" != "ok" ]]; then
  sleep "$RETRY_WAIT_SEC"
  code=$(probe)
  status=$(json_field status)
fi
healthy=0
[[ "$code" == "200" && "$status" == "ok" ]] && healthy=1

now=$(date +%s)
incident_file="$STATE_DIR/incident"
last_alert_file="$STATE_DIR/last_alert"
maint_file="$STATE_DIR/maintenance_since"
summary="url=$HEALTH_URL http=$code status=${status:-none} failing=[$(failing_checks)] host=$(hostname)"

# Maintenance mode: 503 without our health JSON. Give the deploy its grace
# period, then treat it like any other outage.
if [[ $healthy -eq 0 && "$code" == "503" ]] && ! grep -q '"checks"' "$body" 2>/dev/null; then
  [[ -f "$maint_file" ]] || echo "$now" > "$maint_file"
  msince=$(cat "$maint_file")
  if (( now - msince < MAINT_GRACE_MIN * 60 )); then
    log "maintenance $summary ($(( (now - msince) / 60 )) min, grace $MAINT_GRACE_MIN)"
    exit 1
  fi
  summary="$summary maintenance_for=$(( (now - msince) / 60 ))min"
fi
[[ $healthy -eq 1 ]] && rm -f "$maint_file"

if [[ $healthy -eq 0 ]]; then
  if [[ ! -f "$incident_file" ]]; then
    echo "$now" > "$incident_file"; echo "$now" > "$last_alert_file"
    log "DOWN      $summary"
    send_alert "[BallPicker] DOWN — health check failing" \
"BallPicker health check is FAILING.

$(date -u '+%Y-%m-%d %H:%M:%S UTC')
$summary

Response:
$(head -c 1000 "$body" 2>/dev/null)

Next: docs/ops-runbook.md §5 — open /admin/diagnostics, then
  sudo -u $WEB_USER php $BACKEND_DIR/artisan ballspot:health
Repeat alerts at most every $COOLDOWN_MIN minutes; a RECOVERED mail follows when it passes again." || true
  else
    since=$(cat "$incident_file"); last=$(cat "$last_alert_file" 2>/dev/null || echo 0)
    if (( now - last >= COOLDOWN_MIN * 60 )); then
      echo "$now" > "$last_alert_file"
      log "STILL DOWN $summary (since $(date -u -d "@$since" '+%H:%M UTC' 2>/dev/null || date -u -r "$since" '+%H:%M UTC'))"
      send_alert "[BallPicker] STILL DOWN — $(( (now - since) / 60 )) min" \
"BallPicker health check has been failing for $(( (now - since) / 60 )) minutes.

$(date -u '+%Y-%m-%d %H:%M:%S UTC')
$summary

Response:
$(head -c 1000 "$body" 2>/dev/null)" || true
    else
      log "down      $summary (within cooldown)"
    fi
  fi
  exit 1
fi

echo "$now" > "$STATE_DIR/last_ok"
if [[ -f "$incident_file" ]]; then
  since=$(cat "$incident_file"); minutes=$(( (now - since) / 60 ))
  rm -f "$incident_file" "$last_alert_file"
  log "RECOVERED after ${minutes} min  $summary"
  send_alert "[BallPicker] RECOVERED after ${minutes} min" \
"BallPicker health check passes again.

$(date -u '+%Y-%m-%d %H:%M:%S UTC')
Outage: ${minutes} minutes (since $(date -u -d "@$since" '+%Y-%m-%d %H:%M UTC' 2>/dev/null || date -u -r "$since" '+%Y-%m-%d %H:%M UTC'))
$summary" || true
fi
exit 0
