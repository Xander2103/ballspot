# BallSpot Content Safety Guide

This document explains how to protect real challenge content (images and database records) from being lost due to destructive database commands.

---

## ⚠️ The Risk

Running `php artisan migrate:fresh --seed` **drops and recreates all tables**, including:

- All challenges (titles, ball positions, status)
- All uploaded images **database records** (the files remain in storage, but their records are gone)
- All daily challenge assignments
- All tournament/league data
- All user accounts and guesses

If you have added real challenge images via the admin panel and then run `migrate:fresh`, the database records are lost. The image files remain on disk in `storage/app/public/`, but they are orphaned — no challenge record points to them.

**Use `php artisan migrate --seed` instead** for normal schema updates on a populated database. It only runs pending migrations and does not drop existing data.

---

## Do Not Commit Backups

The `backups/` folder is listed in the root `.gitignore` and will never be committed. Backups may contain database files with user data, uploaded images, and challenge metadata — committing them would be a data leak and a repository bloat risk.

If your backup files ever appear in `git status`, check that the root `.gitignore` is present and contains `backups/`.

---

## Before Running Any Destructive Command

Run a backup first:

```bash
cd backend
php artisan ballspot:backup-content
```

This creates a timestamped folder at `backups/ballspot-content/YYYY-MM-DD-HHMMSS/` containing:

| File | Contents |
|------|----------|
| `database.sqlite` | Full copy of the SQLite database |
| `storage/` | All uploaded images from `storage/app/public/` |
| `challenges.json` | All challenge metadata (title, ball position, image paths, status) |
| `daily_challenges.json` | All daily challenge assignments |
| `sports.json` | Sports records |
| `challenge_categories.json` | Category records |
| `manifest.json` | Summary with counts, timestamps, and restore notes |

The `backups/` folder is at the **monorepo root** (one level above `backend/`), outside the normal storage path, so it is never accidentally served publicly.

---

## Where Images Are Stored

Uploaded challenge images are stored in:

```
backend/storage/app/public/
├── challenges/
│   ├── hidden/         ← hidden images (shown during guessing)
│   └── original/       ← reveal images (shown after guessing)
```

The database records in the `challenges` table store **relative paths** in `hidden_image_path` and `original_image_path` (e.g. `challenges/hidden/corner-kick.jpg`). Both the database record and the file must exist for a challenge to work correctly.

The `storage/` folder is symlinked to `public/storage/` via `php artisan storage:link` so images are publicly accessible via URL.

---

## How to Back Up

```bash
cd backend
php artisan ballspot:backup-content
```

The backup is created outside the web root and does not affect the running app. You can run it at any time without downtime.

To inspect an existing backup:

```bash
php artisan ballspot:inspect-backup backups/ballspot-content/2026-06-24-120000
```

---

## How to Recover After `migrate:fresh`

If you accidentally ran `migrate:fresh` and lost challenge records:

### Step 1 — Restore from backup (recommended)

If you ran `ballspot:backup-content` before the destructive command:

1. Restore the SQLite database:
   ```bash
   cp backups/ballspot-content/YYYY-MM-DD-HHMMSS/database.sqlite backend/database/database.sqlite
   ```
2. Restore uploaded images (if the `storage/` folder was also cleared):
   ```bash
   cp -r backups/ballspot-content/YYYY-MM-DD-HHMMSS/storage/. backend/storage/app/public/
   ```
3. Run pending migrations only (no `fresh`, no `--seed`):
   ```bash
   cd backend && php artisan migrate
   ```
4. Verify the admin panel shows your challenges: `http://127.0.0.1:8000/admin/challenges`

### Step 2 — Recover orphaned images (if no backup)

If you have no backup but the image files still exist in storage (they often survive `migrate:fresh`):

```bash
# Dry run — see what would be recovered without writing:
cd backend && php artisan ballspot:recover-challenges --dry-run

# Actually recover:
cd backend && php artisan ballspot:recover-challenges
```

This creates **draft** challenge records pointing to the orphaned images. The ball positions are set to the center (0.5, 0.5) as a placeholder.

After recovering:

1. Open the admin panel: `http://127.0.0.1:8000/admin/challenges`
2. Filter by status = **draft**
3. For each recovered challenge:
   - Click **Edit**
   - Verify the hidden/reveal images look correct
   - Click the image to set the correct ball position
   - Update title, category, difficulty
   - Change status to **active** when ready

---

## What to Do Before Adding Real Challenge Images

1. Confirm the database is in a good state: `php artisan migrate:status`
2. Run a backup: `php artisan ballspot:backup-content`
3. Upload images via the admin panel
4. Run another backup after uploading to capture the new records

---

## Commands Reference

| Command | What it does |
|---------|-------------|
| `php artisan ballspot:backup-content` | Back up database + images + JSON exports |
| `php artisan ballspot:inspect-backup <folder>` | Print summary of a backup folder |
| `php artisan ballspot:recover-challenges --dry-run` | Preview what orphaned images would be recovered |
| `php artisan ballspot:recover-challenges` | Create draft challenge records for orphaned images |
| `php artisan migrate --seed` | Run pending migrations safely (does NOT drop data) |
| `php artisan migrate:fresh --seed` | ⚠️ DROPS ALL TABLES — run backup first |

---

## Known Limitations

- The backup command copies the SQLite file as-is. If the app is writing to the database during backup, there is a small risk of a partially-written copy. For safety, stop the dev server before backing up in production.
- MySQL databases are not backed up by `ballspot:backup-content` (it only copies SQLite). For MySQL, use `mysqldump` separately.
- The `backups/` folder is ignored by Git via the root `.gitignore`. Backups will never be accidentally committed.
