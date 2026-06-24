# BallSpot Challenge Content Guide

This guide explains how to prepare and upload challenge images for BallSpot.

> **⚠️ Before uploading real content:** Run `php artisan ballspot:backup-content` so you have a copy of your database and images. See [docs/content-safety.md](content-safety.md) for full backup and recovery instructions.

---

## Image Concepts

Each challenge has two images:

| Image | Field | Purpose |
|-------|-------|---------|
| **Hidden image** | `hidden_image_path` | Shown to players *while guessing*. The ball should be difficult to spot. |
| **Reveal image** | `original_image_path` | Shown to players *after guessing*. The full, unaltered image where the ball is clearly visible. Optional but recommended. |

The reveal image is never exposed before the player submits a guess — it is only included in the result API response.

---

## Image Specifications

| Property | Recommendation |
|----------|---------------|
| Format | JPEG or PNG (avoid SVG — React Native's `Image` component does not support SVG natively) |
| Aspect ratio | 16:9 or 4:3 recommended (e.g. 800×600, 1200×900, 1920×1080) — any aspect ratio supported, no cropping |
| File size | Max **5 MB** per image |
| Resolution | 800×600 px minimum for acceptable quality on device |

---

## Creating Good Hidden Images

The goal is to make the ball hard to spot, not invisible. Tips:

- **Busy scene** — crowds, pitch markings, and shadows make the ball blend in
- **Small ball** — wide-angle shots where the ball is a small speck
- **Motion blur** — action shots where the ball is mid-flight
- **Low contrast** — ball against similarly-coloured background (muddy pitch, night game)
- Avoid cropping the ball out entirely — the game is *finding* the ball, not *where it was*

---

## Setting the Ball Position

After uploading images in the admin panel, you must mark the ball position:

1. Click on either the hidden or reveal image to place the red marker
2. The reveal image (if uploaded) is preferable for precision — the ball is visible there
3. The X and Y ratio fields update automatically (0 = left/top, 1 = right/bottom)
4. Fine-tune by editing the ratio fields directly if needed

---

## Demo Content Pipeline

Drop images into these folders before running `php artisan db:seed`:

```
backend/public/demo/challenges/
├── hidden/
│   ├── corner-kick.jpg      ← hidden image for "Corner Kick"
│   ├── center-field.jpg
│   ├── penalty-spot.jpg
│   ├── crowd-scene.jpg
│   ├── goal-line.jpg
│   └── kick-off.jpg
└── reveal/
    ├── corner-kick.jpg      ← reveal image for "Corner Kick" (optional)
    ├── center-field.jpg
    └── ...
```

Filenames must match the `slug` in `ChallengeSeeder.php`. Supported extensions: `jpg`, `jpeg`, `png`, `svg`, `webp` (checked in that order).

The seeder will:
1. Copy each file into `storage/app/public/challenges/hidden/` or `challenges/original/`
2. Call `Challenge::firstOrCreate(...)` — so re-seeding does not duplicate challenges

> **Note:** The legacy location `demo/challenges/{slug}.svg` is still checked as a fallback for the hidden image, so existing SVG placeholders continue to work.

---

## Uploading via Admin Panel

Go to **http://127.0.0.1:8000/admin/challenges/create** and:

1. Enter title, difficulty, and status
2. Upload the **hidden image** → click the preview to set the ball position
3. Upload the **reveal image** (optional) → click the reveal preview to refine the ball position
4. Submit

The ball position can be set from either image — whichever is clearer.

---

## Image Display Behavior

- The app detects the natural dimensions of each challenge image via `Image.getSize()`.
- Images are displayed at their full natural aspect ratio — **no cropping**.
- Any aspect ratio is supported: 16:9, 4:3, 1:1, portrait, etc.
- Ball position ratios are always relative to the full image dimensions.
- **Fallback:** If image dimensions cannot be loaded, the app defaults to 4:3 container aspect ratio.

---

## Categories

Challenges can be assigned to a category for grouping and future pack filtering. Categories are managed at `/admin/categories`.

**Default football categories:**

| Category | Slug | Description |
|----------|------|-------------|
| General | `general` | Miscellaneous challenges |
| Corner Kicks | `corner-kicks` | Ball near the corner arc |
| Dribbles | `dribbles` | Ball during dribbling sequences |
| Goalkeeper Saves | `goalkeeper-saves` | Ball near the goal during saves |
| Headers | `headers` | Aerial situations |
| Penalties | `penalties` | Ball at or near the penalty spot |
| Hard Mode | `hard-mode` | Expert-level challenges |

Categories are optional — a challenge can be uncategorised. The mobile app shows the category name as a small badge on the GuessScreen and ResultScreen.

**Future use:** categories will power pack-based challenge browsing (e.g. "Play the Corner Kicks pack") and leaderboards scoped to a category.

---

## Recommended Image Specifications

- **Format:** JPEG or PNG (SVG may not render on all React Native versions)
- **Aspect ratio:** 16:9 or 4:3 recommended for best gameplay experience
- **Resolution:** 800×600px minimum, 1920×1080px maximum
- **File size:** Under 2MB recommended (hard limit: 5MB)
- **Avoid:** Extreme portrait orientation (taller than 1:1) — takes excessive vertical space
- **Ball placement:** Avoid placing the ball very near image edges (within 5%)

## Difficulty Guidelines

| Difficulty | Ball visibility | Example |
|-----------|----------------|---------|
| Easy | Ball clearly visible, not much competing detail | Corner kick close-up |
| Medium | Ball visible but some search required | Penalty spot in crowd noise |
| Hard | Ball very small or heavily occluded | Wide crowd scene |
