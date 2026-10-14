# BallPicker — MySQL database backup runbook

**Scope:** the production MySQL/MariaDB database on the VPS. Uploaded images
and JSON content exports are still covered by `php artisan ballspot:backup-content`
(which copies a SQLite file only and therefore does **not** back up MySQL).
This runbook closes that gap with `backend/scripts/backup-mysql.sh`.

Never paste `.env` contents, the `.my.cnf` file, `APP_KEY` or any password into
tickets, chats or this file.

Paths assume the production layout `/var/www/ballpicker/backend`. Every
command below runs **as root** on the server unless stated otherwise.

---

## 1. What the script does

`backend/scripts/backup-mysql.sh`

- `mysqldump --single-transaction --quick --routines --triggers --hex-blob`
  of `DB_DATABASE` (name, host, port, user read from `backend/.env`; the
  password comes **only** from a 600-mode `~/.my.cnf`).
- Writes `/var/backups/ballpicker/ballpicker-db-YYYY-MM-DD-HHMMSS.sql.gz`
  (mode 600) plus a `.sha256` checksum and a `latest.sql.gz` symlink.
- Logs every step to `/var/backups/ballpicker/backup.log` (and syslog tag
  `ballpicker-backup`). No secrets are ever logged.
- Refuses to call a dump a backup unless it passes: gzip integrity, minimum
  size, the `-- Dump completed` trailer, the `users` table present, and the
  dumped table count matching the live database. Partial files are deleted.
- Prunes backups older than 14 days, **only after** a verified success.
- Exit codes: `0` ok, `1` prerequisites/config, `2` mysqldump failed,
  `3` verification failed. A failed run never deletes older backups.

---

## 2. One-time setup on the production server

### 2.1 Install the script

```bash
cd /var/www/ballpicker/backend
git pull --ff-only                                   # brings scripts/backup-mysql.sh
sudo chown root:root scripts/backup-mysql.sh
sudo chmod 750 scripts/backup-mysql.sh
```

### 2.2 Create the backup folder

```bash
sudo mkdir -p /var/backups/ballpicker
sudo chown root:root /var/backups/ballpicker
sudo chmod 700 /var/backups/ballpicker
```

### 2.3 Create the credentials file (never on the command line)

Use the **same** MySQL user as `DB_USERNAME` in `backend/.env` (or a dedicated
read-only backup user, see 2.5). Create the file empty with the right mode
first, then edit it, so the password is never world-readable even for a
moment:

```bash
sudo install -m 600 -o root -g root /dev/null /root/.my.cnf
sudo nano /root/.my.cnf
```

Put exactly this in it (fill in the values by hand):

```ini
[client]
user=THE_DB_USERNAME_FROM_ENV
password=THE_DB_PASSWORD_FROM_ENV
host=127.0.0.1
```

Then verify the mode and that nothing else can read it:

```bash
sudo stat -c '%a %U:%G %n' /root/.my.cnf      # must print: 600 root:root /root/.my.cnf
```

### 2.4 Check prerequisites without writing anything

```bash
sudo /var/www/ballpicker/backend/scripts/backup-mysql.sh --check
```

Expected last line: `CHECK OK: database [<name>] reachable (<n> tables), credentials file ok, ...`.
Anything else is a configuration problem and the exit code is 1.

### 2.5 Optional: dedicated read-only backup user

Safer than reusing the app user. Run in `mysql` as root (replace the
placeholder password by hand, do not paste it anywhere else):

```sql
CREATE USER 'ballpicker_backup'@'localhost' IDENTIFIED BY 'CHOOSE_A_LONG_RANDOM_PASSWORD';
GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT ON ballpicker_db.* TO 'ballpicker_backup'@'localhost';
-- MySQL 8 only (not MariaDB): either GRANT PROCESS ON *.* TO 'ballpicker_backup'@'localhost';
-- or run the script with BALLPICKER_DUMP_EXTRA_ARGS="--no-tablespaces"
FLUSH PRIVILEGES;
```

Then put `ballpicker_backup` and its password in `/root/.my.cnf` (step 2.3)
and tell the script to use that user instead of `DB_USERNAME` from `.env` by
prefixing every call, including the crontab line, with
`BALLPICKER_DB_USER=ballpicker_backup`:

```cron
15 3 * * * BALLPICKER_DB_USER=ballpicker_backup /var/www/ballpicker/backend/scripts/backup-mysql.sh >> /var/backups/ballpicker/backup.log 2>&1
```

Reusing the app user (no override) is fine for launch; the dedicated user is
the better long-term setup.

---

## 3. Crontab entry (nightly, 03:15 server time)

```bash
sudo crontab -e
```

Add (one line, keep `MAILTO` so a failed run emails you, or leave it empty to
rely on `backup.log`):

```cron
MAILTO=info@vanmalderstudio.be
15 3 * * * /var/www/ballpicker/backend/scripts/backup-mysql.sh >> /var/backups/ballpicker/backup.log 2>&1
```

The script logs its own lines to `backup.log`; the redirect only catches
unexpected output (for example a missing binary). `grep FAILED` in the log
finds every bad night.

---

## 4. Test the backup now

```bash
sudo /var/www/ballpicker/backend/scripts/backup-mysql.sh
echo "exit=$?"                                        # must print exit=0
sudo tail -n 5 /var/backups/ballpicker/backup.log     # last line starts with "... OK ballpicker-db-"
sudo ls -la /var/backups/ballpicker/
```

Expected: one `ballpicker-db-<stamp>.sql.gz` (mode `-rw-------`), its
`.sha256`, `latest.sql.gz` pointing at it, and a log line like
`OK ballpicker-db-2026-10-14-031501.sql.gz size=...B tables=37 duration=3s pruned=0 retention=14d`.

### Verification commands

```bash
cd /var/backups/ballpicker
sudo sha256sum -c ballpicker-db-*.sql.gz.sha256                 # every line: OK
sudo gzip -t latest.sql.gz && echo "gzip ok"
sudo zcat latest.sql.gz | head -n 20                            # header: "-- MySQL dump" / "-- MariaDB dump"
sudo zcat latest.sql.gz | grep -c '^CREATE TABLE'               # table count, must match the DB
sudo zcat latest.sql.gz | tail -n 3                             # last line: "-- Dump completed on ..."
sudo zcat latest.sql.gz | grep -c '^INSERT INTO `users`'        # > 0 once there are users
sudo grep -c FAILED backup.log                                  # 0 on a healthy history
sudo find /var/backups/ballpicker -name 'ballpicker-db-*.sql.gz' -mtime +14   # must print nothing
```

Prove the failure path once (so you know a broken night is loud): run with a
wrong credentials path and confirm a non-zero exit and a `FAILED` log line.

```bash
sudo env BALLPICKER_MYSQL_CNF=/root/does-not-exist /var/www/ballpicker/backend/scripts/backup-mysql.sh; echo "exit=$?"   # exit=1
sudo tail -n 2 /var/backups/ballpicker/backup.log
```

---

## 5. Restore

### 5.1 Restore test into a scratch database (do this once, before launch)

Proves the file is restorable without touching production data.

```bash
cd /var/backups/ballpicker
sudo mysql -e "CREATE DATABASE ballpicker_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo bash -c 'zcat latest.sql.gz | mysql ballpicker_restore_test'
sudo mysql -e "SELECT COUNT(*) AS tables_restored FROM information_schema.tables WHERE table_schema='ballpicker_restore_test';"
sudo mysql -e "SELECT (SELECT COUNT(*) FROM ballpicker_restore_test.users) AS users, (SELECT COUNT(*) FROM ballpicker_restore_test.daily_challenge_guesses) AS daily_guesses;"
sudo mysql -e "DROP DATABASE ballpicker_restore_test;"
```

The table count must equal the live database; the row counts must look like
yesterday's numbers on `/admin/diagnostics`.

### 5.2 Real restore of production (data loss or corruption)

1. Stop writes and take a dump of the *current* state first, even if it is
   broken — it may hold today's guesses:

```bash
cd /var/www/ballpicker/backend
sudo -u www-data php artisan down
sudo /var/www/ballpicker/backend/scripts/backup-mysql.sh      # "pre-restore" copy; ignore exit 3 if the DB is corrupt
```

2. Pick the backup to restore (`ls -la /var/backups/ballpicker/`), verify it,
   then load it. The dump contains `DROP TABLE IF EXISTS` for every table, so
   it replaces the schema and data in place:

```bash
cd /var/backups/ballpicker
sudo sha256sum -c ballpicker-db-YYYY-MM-DD-HHMMSS.sql.gz.sha256
sudo bash -c 'zcat ballpicker-db-YYYY-MM-DD-HHMMSS.sql.gz | mysql ballpicker_db'
```

(Replace `ballpicker_db` with the real `DB_DATABASE`.)

3. Bring the app back and re-check:

```bash
cd /var/www/ballpicker/backend
sudo -u www-data php artisan migrate --force          # applies migrations newer than the dump, never migrate:fresh
sudo -u www-data php artisan optimize:clear && sudo -u www-data php artisan config:cache
sudo -u www-data php artisan up
curl -s https://ballpicker.vanmalderstudio.be/api/health
```

Everything written between the backup and the restore is lost; users who
logged in since then must log in again (their tokens are gone with the
`personal_access_tokens` rows).

---

## 6. Before any risky change (migration, clear daily history, bulk edit)

```bash
sudo /var/www/ballpicker/backend/scripts/backup-mysql.sh && echo "backup ok, proceed"
```

Only proceed when it prints `backup ok, proceed`. The admin "Clear daily
history" tool still runs the content backup (images + JSON) but **not** a
MySQL dump — run this script first.

---

## 7. Final production checklist

- [ ] `scripts/backup-mysql.sh` is on the server, `root:root`, mode `750`
- [ ] `/var/backups/ballpicker` exists, `root:root`, mode `700`
- [ ] `/root/.my.cnf` exists, mode `600`, `[client]` user/password/host, no other copies anywhere
- [ ] `backup-mysql.sh --check` prints `CHECK OK`
- [ ] A manual run exits `0`, `backup.log` ends with an `OK` line, the `.sql.gz` is non-trivial in size
- [ ] `sha256sum -c` passes and `zcat ... | tail -n 3` shows `-- Dump completed`
- [ ] The restore test into `ballpicker_restore_test` restored every table and was dropped again
- [ ] The failure test (wrong cnf path) exits non-zero and logs `FAILED`
- [ ] Root crontab has the 03:15 entry with `MAILTO`
- [ ] After the first night: a second file exists, `latest.sql.gz` points at it, no `FAILED` lines
- [ ] Disk: `df -h /var/backups` leaves at least 14 × one dump's size free
