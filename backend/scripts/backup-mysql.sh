#!/usr/bin/env bash
# =============================================================================
# BallPicker — nightly MySQL / MariaDB backup
#
#   backup-mysql.sh           dump, verify, rotate (what the cron runs)
#   backup-mysql.sh --check   validate prerequisites + connectivity, write nothing
#
# Credentials are NEVER in this file, in the environment or on the command
# line. mysqldump reads user/password from a 600-mode options file:
#   default  $HOME/.my.cnf of the user running the script (root for the cron)
#   override BALLPICKER_MYSQL_CNF=/path/to/file
# The database name / host / port come from the backend .env (DB_DATABASE,
# DB_HOST, DB_PORT, DB_USERNAME). DB_PASSWORD is never read by this script.
#
# Output
#   /var/backups/ballpicker/ballpicker-db-YYYY-MM-DD-HHMMSS.sql.gz   (mode 600)
#   /var/backups/ballpicker/ballpicker-db-....sql.gz.sha256
#   /var/backups/ballpicker/latest.sql.gz   -> symlink to the newest good dump
#   /var/backups/ballpicker/backup.log      one line per step, no secrets
#
# A dump only counts as a backup after it passed every check below (gzip
# integrity, minimum size, "Dump completed" trailer, users table present).
# Partial files are removed, old backups are pruned only after a verified
# success, and the exit code is non-zero on any failure so cron / a monitor
# can see it.
#
# Exit codes: 0 ok | 1 prerequisite or configuration problem | 2 mysqldump
# failed | 3 the dump failed verification
#
# Runbook: docs/db-backup-runbook.md
# =============================================================================

set -Eeuo pipefail
umask 077

BACKEND_DIR="${BALLPICKER_BACKEND_DIR:-/var/www/ballpicker/backend}"
BACKUP_DIR="${BALLPICKER_BACKUP_DIR:-/var/backups/ballpicker}"
MYSQL_CNF="${BALLPICKER_MYSQL_CNF:-${HOME:-/root}/.my.cnf}"
RETENTION_DAYS="${BALLPICKER_BACKUP_RETENTION_DAYS:-14}"
MIN_BYTES="${BALLPICKER_BACKUP_MIN_BYTES:-10240}"       # compressed; a real dump of this schema is far larger
MIN_FREE_MB="${BALLPICKER_BACKUP_MIN_FREE_MB:-500}"
MYSQLDUMP_BIN="${MYSQLDUMP_BIN:-mysqldump}"
MYSQL_BIN="${MYSQL_BIN:-mysql}"
# Extra mysqldump flags for special setups, e.g. "--no-tablespaces" for a
# restricted MySQL 8 backup user. Space separated, no secrets.
read -r -a EXTRA_DUMP_ARGS <<< "${BALLPICKER_DUMP_EXTRA_ARGS:-}"

LOG_FILE="$BACKUP_DIR/backup.log"
CHECK_ONLY=0
case "${1:-}" in
    --check) CHECK_ONLY=1 ;;
    '') ;;
    *) printf 'usage: %s [--check]\n' "$0" >&2; exit 1 ;;
esac

STARTED_AT=$(date +%s)
TMP=''

# ----------------------------------------------------------------------------
# Logging: always to the log file; echoed to the terminal when interactive.
# Never log a password, a connection string or the contents of the cnf file.
# ----------------------------------------------------------------------------
log() {
    local line
    line="$(date '+%Y-%m-%d %H:%M:%S') $*"
    if [[ -d "$BACKUP_DIR" ]]; then
        printf '%s\n' "$line" >> "$LOG_FILE"
    fi
    if [[ -t 2 || "$CHECK_ONLY" -eq 1 ]]; then
        printf '%s\n' "$line" >&2
    fi
    if command -v logger >/dev/null 2>&1; then
        logger -t ballpicker-backup -- "$*" || true
    fi
}

die() {
    local code=$1
    shift
    log "ERROR: $*"
    exit "$code"
}

on_exit() {
    local rc=$?
    [[ -n "$TMP" ]] && rm -f "$TMP"
    if [[ $rc -ne 0 ]]; then
        log "FAILED (exit $rc) after $(( $(date +%s) - STARTED_AT ))s"
    fi
    exit "$rc"
}
trap on_exit EXIT

# ----------------------------------------------------------------------------
# Read one key from the backend .env without sourcing it (sourcing would put
# DB_PASSWORD into this process environment). Quotes are stripped.
# ----------------------------------------------------------------------------
env_value() {
    local key=$1 line
    line=$(command grep -E "^${key}=" "$BACKEND_DIR/.env" | tail -n 1 || true)
    line=${line#*=}
    line=${line%\"}; line=${line#\"}
    line=${line%\'}; line=${line#\'}
    printf '%s' "$line"
}

file_mode() {
    # GNU stat (Linux) first, BSD stat (macOS) as fallback.
    stat -c '%a' "$1" 2>/dev/null || stat -f '%Lp' "$1"
}

# ----------------------------------------------------------------------------
# Prerequisites (both modes)
# ----------------------------------------------------------------------------
mkdir -p "$BACKUP_DIR" || die 1 "cannot create $BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
touch "$LOG_FILE" && chmod 600 "$LOG_FILE"

[[ -r "$BACKEND_DIR/.env" ]] || die 1 "backend .env not readable at $BACKEND_DIR/.env (set BALLPICKER_BACKEND_DIR)"

DB_CONNECTION=$(env_value DB_CONNECTION)
DB_NAME=$(env_value DB_DATABASE)
DB_HOST=$(env_value DB_HOST)
DB_PORT=$(env_value DB_PORT)
# BALLPICKER_DB_USER lets a dedicated read-only backup user (whose password is
# in the cnf file) replace the app user from .env.
DB_USER="${BALLPICKER_DB_USER:-$(env_value DB_USERNAME)}"

case "$DB_CONNECTION" in
    mysql|mariadb) ;;
    *) die 1 "DB_CONNECTION is '${DB_CONNECTION:-<empty>}', not mysql/mariadb — this script is for the MySQL production database only" ;;
esac
[[ -n "$DB_NAME" ]] || die 1 "DB_DATABASE is empty in $BACKEND_DIR/.env"

[[ -f "$MYSQL_CNF" ]] || die 1 "credentials file $MYSQL_CNF not found (see docs/db-backup-runbook.md, step 2)"
cnf_mode=$(file_mode "$MYSQL_CNF")
case "$cnf_mode" in
    600|400) ;;
    *) die 1 "credentials file $MYSQL_CNF has mode $cnf_mode — must be 600 (chmod 600 $MYSQL_CNF)" ;;
esac
command grep -qE '^\s*password\s*=' "$MYSQL_CNF" || die 1 "credentials file $MYSQL_CNF has no password= line under [client]"

command -v "$MYSQLDUMP_BIN" >/dev/null 2>&1 || die 1 "$MYSQLDUMP_BIN not found in PATH"
command -v "$MYSQL_BIN"     >/dev/null 2>&1 || die 1 "$MYSQL_BIN not found in PATH"
command -v gzip             >/dev/null 2>&1 || die 1 "gzip not found in PATH"

free_mb=$(df -Pm "$BACKUP_DIR" | awk 'NR == 2 { print $4 }')
[[ "${free_mb:-0}" -ge "$MIN_FREE_MB" ]] || die 1 "only ${free_mb:-0} MB free under $BACKUP_DIR (need $MIN_FREE_MB MB)"

# Connection arguments: host/port/user from .env when set; the password comes
# from the cnf file only. --defaults-extra-file must be the first argument.
CONN_ARGS=()
[[ -n "$DB_HOST" ]] && CONN_ARGS+=("--host=$DB_HOST")
[[ -n "$DB_PORT" ]] && CONN_ARGS+=("--port=$DB_PORT")
[[ -n "$DB_USER" ]] && CONN_ARGS+=("--user=$DB_USER")

# Connectivity + privilege smoke test. mysql error text never contains the
# password; it is logged so a wrong host/user shows up in backup.log.
conn_err=$(mktemp)
if ! "$MYSQL_BIN" --defaults-extra-file="$MYSQL_CNF" ${CONN_ARGS[@]+"${CONN_ARGS[@]}"} --batch --skip-column-names \
        -e 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()' "$DB_NAME" \
        >"$conn_err.out" 2>"$conn_err"; then
    while IFS= read -r l; do log "mysql: $l"; done < "$conn_err"
    rm -f "$conn_err" "$conn_err.out"
    die 1 "cannot connect to database [$DB_NAME] with the credentials in $MYSQL_CNF"
fi
table_count=$(tr -d '[:space:]' < "$conn_err.out")
rm -f "$conn_err" "$conn_err.out"
[[ "${table_count:-0}" -gt 0 ]] || die 1 "database [$DB_NAME] has no tables — wrong DB_DATABASE?"

if [[ "$CHECK_ONLY" -eq 1 ]]; then
    log "CHECK OK: database [$DB_NAME] reachable (${table_count} tables), credentials file ok, ${free_mb} MB free, backups in $BACKUP_DIR, retention ${RETENTION_DAYS} days"
    exit 0
fi

# ----------------------------------------------------------------------------
# One backup at a time
# ----------------------------------------------------------------------------
exec 9>"$BACKUP_DIR/.backup.lock"
if command -v flock >/dev/null 2>&1; then
    flock -n 9 || die 1 "another backup is still running (lock $BACKUP_DIR/.backup.lock)"
fi

# ----------------------------------------------------------------------------
# Dump
# ----------------------------------------------------------------------------
stamp=$(date '+%Y-%m-%d-%H%M%S')
OUT="$BACKUP_DIR/ballpicker-db-$stamp.sql.gz"
TMP="$OUT.part"
dump_err=$(mktemp)

log "START database [$DB_NAME] (${table_count} tables) -> $(basename "$OUT")"

set +e
"$MYSQLDUMP_BIN" --defaults-extra-file="$MYSQL_CNF" ${CONN_ARGS[@]+"${CONN_ARGS[@]}"} \
    --single-transaction --quick --routines --triggers \
    --hex-blob --default-character-set=utf8mb4 \
    ${EXTRA_DUMP_ARGS[@]+"${EXTRA_DUMP_ARGS[@]}"} \
    "$DB_NAME" 2>"$dump_err" | gzip -6 > "$TMP"
pipe_status=("${PIPESTATUS[@]}")
set -e

while IFS= read -r l; do log "mysqldump: $l"; done < "$dump_err"
rm -f "$dump_err"

if [[ "${pipe_status[0]}" -ne 0 ]]; then
    die 2 "mysqldump exited with status ${pipe_status[0]} — no backup written"
fi
if [[ "${pipe_status[1]}" -ne 0 ]]; then
    die 2 "gzip exited with status ${pipe_status[1]} — no backup written"
fi

# ----------------------------------------------------------------------------
# Verify before calling it a backup
# ----------------------------------------------------------------------------
gzip -t "$TMP" || die 3 "gzip integrity check failed on $(basename "$TMP")"

size_bytes=$(wc -c < "$TMP" | tr -d '[:space:]')
[[ "$size_bytes" -ge "$MIN_BYTES" ]] || die 3 "dump is only ${size_bytes} bytes (minimum ${MIN_BYTES}) — treated as failed"

# One pass over the dump: it must contain the users table and end with the
# mysqldump trailer, otherwise the stream was cut short.
if ! gzip -dc "$TMP" | awk '
        /^CREATE TABLE `users`/ { users = 1 }
        /^CREATE TABLE /        { tables++ }
        /^-- Dump completed/    { done = 1 }
        END { printf "tables=%d\n", tables; exit !(users && done) }
    ' > "$TMP.stats"; then
    rm -f "$TMP.stats"
    die 3 "dump is incomplete (missing users table or the 'Dump completed' trailer) — treated as failed"
fi
dumped_tables=$(sed -n 's/^tables=//p' "$TMP.stats")
rm -f "$TMP.stats"

if [[ "$dumped_tables" -lt "$table_count" ]]; then
    die 3 "dump holds ${dumped_tables} tables but the database has ${table_count} — treated as failed"
fi

# ----------------------------------------------------------------------------
# Commit: rename into place, checksum, latest symlink, prune
# ----------------------------------------------------------------------------
mv "$TMP" "$OUT"
TMP=''
chmod 600 "$OUT"

if command -v sha256sum >/dev/null 2>&1; then
    ( cd "$BACKUP_DIR" && sha256sum "$(basename "$OUT")" > "$(basename "$OUT").sha256" )
elif command -v shasum >/dev/null 2>&1; then
    ( cd "$BACKUP_DIR" && shasum -a 256 "$(basename "$OUT")" > "$(basename "$OUT").sha256" )
fi

ln -sfn "$(basename "$OUT")" "$BACKUP_DIR/latest.sql.gz"

pruned=$(find "$BACKUP_DIR" -maxdepth 1 -type f \
    \( -name 'ballpicker-db-*.sql.gz' -o -name 'ballpicker-db-*.sql.gz.sha256' \) \
    -mtime +"$RETENTION_DAYS" -print -delete | wc -l | tr -d '[:space:]')

log "OK $(basename "$OUT") size=${size_bytes}B tables=${dumped_tables} duration=$(( $(date +%s) - STARTED_AT ))s pruned=${pruned} retention=${RETENTION_DAYS}d"
exit 0
