# BallSpot Store Readiness

This document covers content rights, pre-release checklists, and the store-readiness Artisan command for Play Store internal testing and TestFlight readiness.

---

## v1.7 store-relevant notes

- **Profile avatar upload = user-generated content (UGC).** Users can upload a photo
  (`POST /api/me/avatar`; jpeg/jpg/png/webp, max 2 MB; SVG rejected). This introduces
  user-supplied imagery, which both stores treat as UGC. **Moderation consideration:** have
  a reporting/removal path and Terms language covering objectionable content before opening
  avatars to a wide audience. Files are stored on the public disk under `avatars/` with
  randomized names; deleting/replacing an avatar only removes files under `avatars/`
  (challenge images are never touched). Avatars are not surfaced in leaderboards or
  tournament lobbies yet, limiting exposure for now.
- **Photo permission string.** The avatar picker uses `expo-image-picker`; `app.json`
  declares the plugin with a `photosPermission` string. Ensure this string is user-friendly
  and accurate — Apple requires a clear purpose string for photo-library access
  (`NSPhotoLibraryUsageDescription`), and Google Play surfaces the media permission. This is
  the only new permission added in v1.7.
- **Themes & sport selection add no new store risk.** Themes are a local styling choice
  (allow-list of 5); sport selection only chooses which active sport's content is shown.
  Neither adds IAP, ads, gambling, tracking, or new content-review surfaces. Inactive sports
  remain backend scaffolding ("Coming soon", disabled) and are not playable.
- **"Tournament Blue" theme.** Original styling inspired by the general energy/vibe of
  televised European sport nights (deep navy, turquoise, vivid red accent, cool silver). It
  is **NOT UEFA branding** and uses no UEFA logos, names, or protected assets — no
  trademark/IP review is triggered.

---

## v1.6 store-relevant notes

- **Account recovery:** password reset is implemented (`/api/forgot-password`,
  `/api/reset-password`). For production, configure a real `MAIL_MAILER` (SMTP) so
  reset emails are delivered — locally `log` writes the link to `laravel.log`.
- **No monetization surfaces:** no payments, in-app purchases, ads, or real prizes
  are present. Trophies/badges are virtual only. This keeps the current store
  listing category (no gambling, no IAP) intact.
- **Multi-sport:** only football is active. Inactive sports are backend scaffolding
  and are not surfaced as playable content, so no additional store content review
  is needed.
- **Tags & badge icons:** text-only tags and emoji badge icons — no copyrighted
  logos or imagery introduced.

---

## Content Rights Checklist

Before submitting to any app store, verify every challenge image you use:

| Check | Notes |
|-------|-------|
| Own the image (you took the photo) | Safest option — no rights issues |
| Licensed for commercial use (CC0, Unsplash, Pexels) | Confirm license allows derivatives and commercial use |
| Permission obtained in writing | Keep evidence |
| No identifiable faces without consent | Required by Google/Apple guidelines |
| No trademarked branding visible (club badges, sponsor logos) | Can be cropped or blurred |
| No copyrighted stadium art or murals | Check specific venue rules |

**Demo / placeholder images** shipped with the seeder are for development only. Replace them with real images before any public-facing release.

---

## Demo Content Warning

The seeder creates 6 demo challenges with titles like "Corner Kick", "Free Kick", "Penalty Kick", "Header", "Goal Kick", "Throw In". These are recognised by `Challenge::isDemoContent()` and flagged by `ballspot:store-readiness-check`.

Demo challenges use placeholder SVG images which may not render on all devices (see Known Limitations in test-report.md). Replace them with JPEG/PNG photos before release.

---

## Internal Testing Checklist

Before submitting a build for internal testing (Play Store internal track / TestFlight):

- [ ] Run `php artisan ballspot:store-readiness-check` — all FAIL items resolved, WARN items reviewed
- [ ] At least 7 active challenges with real JPEG/PNG images and ball positions set
- [ ] Daily challenge scheduled for today and the next 7 days
- [ ] Storage symlink exists (`php artisan storage:link`)
- [ ] `/privacy`, `/terms`, `/support` public pages load correctly in a browser
- [ ] `BALLSPOT_SUPPORT_EMAIL` set to a real monitored address in `.env`
- [ ] `BALLSPOT_WEB_URL` set to the public server URL (not localhost)
- [ ] `EXPO_PUBLIC_WEB_URL` set in mobile `.env` (or Expo env config) to match backend URL
- [ ] Mobile `APP_VERSION` string updated before building
- [ ] Test account deletion flow end-to-end: confirm modal → API call → app returns to Login
- [ ] Test Privacy Policy, Terms, Support links open in the device browser
- [ ] All demo images replaced with sport-appropriate real photos (rights confirmed)
- [ ] `APP_DEBUG=false` on the test server
- [ ] No hardcoded `localhost` in backend `.env`

---

## Play Store / TestFlight Submission Checklist

### Required by Google Play

- [ ] Account deletion: users can delete their account from inside the app (Profile → Delete account). See `DELETE /api/account`.
- [ ] Privacy Policy URL submitted in Play Console: `https://yourdomain.com/privacy`
- [ ] Support email submitted: `BALLSPOT_SUPPORT_EMAIL`
- [ ] App does not request more permissions than needed (no location, camera, microphone; **photo-library access added in v1.7** for avatar upload — verify the `photosPermission` string is clear and accurate)
- [ ] Content rating questionnaire completed (no violence, no gambling, no real money)

### Required by Apple TestFlight / App Store

- [ ] Privacy Policy URL in App Store Connect: `https://yourdomain.com/privacy`
- [ ] Account deletion in-app (required since June 2023): ✅ implemented
- [ ] Support URL: `https://yourdomain.com/support`
- [ ] No paid features / IAP declared if none present
- [ ] No gambling or real-money mechanics: ✅ game is score-based only

---

## Store Readiness Command

Run a read-only readiness report at any time:

```bash
cd backend
php artisan ballspot:store-readiness-check
```

**Exit codes:**

| Code | Meaning |
|------|---------|
| 0 | All checks PASS or have only WARN items (safe for internal testing) |
| 1 | One or more FAIL items — broken infrastructure must be fixed |

**Check categories:**

| Check | PASS | WARN | FAIL |
|-------|------|------|------|
| `APP_ENV=production` | production | not production | — |
| `APP_DEBUG=false` | false | true | — |
| `APP_URL` not localhost | real domain | localhost/127.0.0.1 | — |
| `BALLSPOT_SUPPORT_EMAIL` customised | real address | default/missing | — |
| `BALLSPOT_WEB_URL` not localhost | real URL | localhost/127.0.0.1 | — |
| Active ready challenges | ≥7 | 1–6 or 0 | — |
| Demo content in pool | none | demo detected | — |
| Today's daily challenge | exists | missing | — |
| Next 7 days schedule | ≥3 days | <3 days | — |
| Storage symlink | exists | — | missing |
| `backups/` in .gitignore | in gitignore | not in gitignore | — |
| `/privacy` route | registered | — | missing |
| `/terms` route | registered | — | missing |
| `/support` route | registered | — | missing |

WARN items are acceptable for internal testing. Fix all FAIL items before any public submission.

---

## Account Deletion (Compliance)

BallSpot implements account deletion as anonymization to preserve leaderboard integrity:

- User's `name` → `"Deleted User"`
- User's `email` → `deleted-{id}@ballspot.deleted`
- User's `username` → `deleted-{id}`
- Password randomized
- All Sanctum tokens revoked immediately
- User row remains in DB (foreign key references from scores/guesses stay valid)

This satisfies Google Play and Apple App Store account deletion requirements. The `/support` page documents the self-service deletion flow for users who prefer to contact support.
