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

## Assigning a daily challenge

A challenge must be **active** and have a hidden image and ball position before it can be used as a daily challenge.

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
