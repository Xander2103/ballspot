# BallSpot Challenge Content Guide

How to add, manage, and replace challenge images in the admin panel.

---

## Image requirements

| Field | Purpose | Required |
|-------|---------|----------|
| **Hidden image** | What the player sees while guessing — ball should not be obvious | Yes |
| **Reveal image** | Shown after guessing — ball is clearly visible | No (but strongly recommended) |

**Format:** JPEG or PNG, max 5 MB per file.  
**Recommended size:** 1200 × 800 px or similar 3:2 ratio. The admin panel and mobile app scale to fit.  
**Avoid SVG** — React Native's Image component does not render SVG natively. The 6 seeded demo challenges use SVG placeholders; they will appear blank on device until replaced with JPEG/PNG.

---

## Replacing demo placeholder images

The seeder ships 6 demo challenges with SVG placeholders:

| Title | Notes |
|-------|-------|
| Corner Kick | Football corner-kick scenario |
| Center Field | Midfield wide shot |
| Penalty Spot | Penalty area close-up |
| Crowd Scene | Stadium crowd with ball hidden |
| Goal Line | Goal-line decision |
| Kick Off | Centre-circle kick-off |

These are marked **Demo** in the admin challenge list. Replace them before going live:

1. Log in to the admin panel at `/admin/login`
2. Run a backup first: `php artisan ballspot:backup-content`
3. Go to **Challenges** and click **Edit** on the demo challenge
4. Upload a JPEG/PNG for **Replace Hidden Image** and optionally **Replace Reveal Image**
5. Click the hidden image to reposition the ball marker exactly
6. Set status to **active** and save

---

## Challenge workflow

```
draft → active → archived
```

- **draft** — Incomplete or not yet ready. Can have missing image or ball position. Not shown to players.
- **active** — Fully ready. Can be assigned as a daily challenge. Shown in tournament rounds when selected.
- **archived** — Retired. Not selected for new tournaments or daily challenges. Records and images preserved.

**Activation guard:** The admin panel blocks activating a challenge that has no hidden image. Upload the image first, then change status to active.

---

## Setting ball position

Ball position is stored as X/Y ratios (0–1) relative to the image dimensions:

- `0, 0` = top-left corner
- `1, 1` = bottom-right corner
- `0.5, 0.5` = center

**Click to set:** On the create/edit form, click anywhere on the hidden or reveal image to drop the red marker at that exact point. The X/Y fields update automatically.

**Fine-tune:** Edit the numeric X/Y fields directly for precision. Ratios are stored to 4 decimal places.

**Why ratios?** Images are displayed at different sizes on different devices. Ratios are device-independent — the score calculation on the server converts both the ball position and the player's tap to the same coordinate space.

---

## Usage pools (v1.8.9)

Every challenge has a **Usage pool** (set on create/edit):

| Pool | Daily schedule | Tournament rounds |
|------|----------------|-------------------|
| General (default) | ✅ | ✅ |
| Daily only | ✅ | ❌ |
| Tournament only | ❌ | ✅ |
| Pack only | ❌ | ❌ (curated packs only) |

**Daily has priority.** Once a photo has been scheduled as a daily (any date,
any status) it is permanently *Daily-used*: it can never be a daily again and
will never be drawn into a new tournament, whatever its pool says. Tournaments
draw `duration_days` **unique** photos; if a sport has too few eligible
Tournament/General photos, players get "Not enough unused tournament challenges
available" until you add more. The challenge list warns per sport below 7.

**Tournament lengths (v1.9.0):** players can only pick **7 days, 14 days or
1 month (30 days)**, one photo per day. So a sport needs at least 7 eligible
photos for the shortest tournament and 30 for a month-long one. Older
tournaments with other lengths keep playing as they are.

## Tournament challenge cooldown (v1.9.0)

Admin → **Settings** (`/admin/settings`) → **Tournament challenge cooldown**.

- **What it does:** when a tournament starts, BallPicker prefers photos that
  none of its players have guessed (in a daily, a tournament or a pack)
  within the last *N* days. If there are not enough fresh photos for the
  whole tournament, older eligible Tournament/General photos are reused to
  fill the remaining rounds — a tournament is never blocked just because the
  pool is small, only when the pool is smaller than the tournament length.
- **Default:** 90 days. **Range:** 0–365 whole days.
- **0 means disabled:** guess history is ignored and any eligible photo can
  be drawn straight away.
- **Daily-used photos are always excluded**, whatever this value is. That is
  the permanent "Daily has priority" rule from v1.8.9, not a cooldown: a
  photo that was (or is scheduled to be) a Daily Challenge is never drawn
  into a new tournament, so a daily photo stays a one-off surprise for every
  player.
- Rounds are still shared by all players in a tournament, and a photo never
  repeats within the same tournament.

## Assigning a daily challenge

A challenge must be **active**, in the **Daily** or **General** pool, never used as a daily before, and have a hidden image and ball position before it can be used as a daily challenge.

From the **Edit** page (or **Preview** page), a "Set as daily challenge" form appears at the bottom when the challenge is ready. Pick a date and submit.

From the **Daily** admin page, use **New Daily Challenge** → select from the list of active challenges.

**Safety:** Replacing a daily challenge that already has player guesses is blocked — the old challenge stays in place.

---

## Quick actions on the challenge list

| Button | Condition | Effect |
|--------|-----------|--------|
| **Archive** | Not already archived | Sets status to `archived` (preserves record + images) |
| **→ Draft** | Active or archived | Sets status to `draft` |
| **Activate** | Not active AND `isReady()` | Sets status to `active` |
| **Preview** | Always | Opens player-view preview with readiness summary |

**Archive vs Delete:** The Archive button is the recommended way to retire a challenge. The Delete option (available on the edit form) permanently deletes the database record and both image files — use only for test/junk content.

---

## Readiness badges

| Badge | Meaning |
|-------|---------|
| **Ready** (green) | Has hidden image, ball position, title, and sport |
| **Incomplete** (red) | Missing at least one required field — hover for details |
| **Demo** (yellow) | Title matches one of the 6 seeded placeholder names — replace before going live |
| **Used as daily** (blue) | Has been assigned as a daily challenge at least once |

---

## Content safety

Before uploading real images or running any database commands:

```bash
cd backend && php artisan ballspot:backup-content
```

See [docs/content-safety.md](content-safety.md) for the full backup, restore, and recovery guide.

---

## Scheduling daily challenges in bulk

Instead of manually creating each day in the admin panel, use the schedule command:

```bash
# Dry-run: preview what would be scheduled
php artisan ballspot:schedule-daily-challenges --days=14 --dry-run

# Write 14 days (run backup first!)
php artisan ballspot:backup-content
php artisan ballspot:schedule-daily-challenges --days=14

# Replace existing scheduled entries
php artisan ballspot:schedule-daily-challenges --days=14 --force

# Start from a specific future date
php artisan ballspot:schedule-daily-challenges --days=7 --start=2026-07-01
```

**How challenge selection works:**
- Only `active` challenges that pass `isReadyForDaily()` are eligible (must have hidden image + ball position).
- Demo challenges are used as fallback only (a warning is printed).
- The command picks challenges in least-recently-used order — challenges never used come first.
- Within the generated range, the same challenge is not reused if the pool has enough variety.
- New rows are created with `status=scheduled`. Promote to `active` in `/admin/daily` when you are ready for players to see them.
- The command **never deletes challenges or images**.

**Warning:** Always run `php artisan ballspot:backup-content` before bulk-scheduling real production content.

---

## Resetting test Daily history (PRE-LAUNCH ONLY)

During testing many challenges were used as Daily Challenges. Every row in
`daily_challenges` marks its challenge as permanently **"Used as Daily"** and
blocks it from tournaments. That rule is correct in production, but before the
**first public launch** the test history should be wiped so those photos become
reusable.

```bash
# Dry-run (default): shows how many daily_challenges / daily_challenge_guesses
# rows would be deleted and which challenges are affected. Deletes nothing.
php artisan ballspot:reset-test-daily-history

# Actual reset — BOTH flags are required, either one alone is refused.
php artisan ballspot:backup-content
php artisan ballspot:reset-test-daily-history --force --confirm-prelaunch
```

**What it deletes:** only `daily_challenge_guesses` and `daily_challenges`.

**What it never touches:** challenges, challenge images, `usage_pool` values,
tournament rounds/guesses, users, badges, packs. A challenge with
`usage_pool=daily` stays in the Daily pool — change it to Tournament/General in
the admin panel afterwards if needed.

**⚠️ This is a one-time pre-launch tool. Never run it casually after public
launch** — once real players exist it would erase their daily scores, streaks
and leaderboard history.
