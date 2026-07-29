# BallSpot Store Readiness

This document covers content rights, pre-release checklists, and the store-readiness Artisan command for Play Store internal testing and TestFlight readiness.

---

## v1.8.1 store-relevant notes (Security, Privacy & Test Readiness)

- **Account deletion is now complete** (avatar file, push tokens, settings and
  pending codes removed; row anonymized) — satisfies the Play/App Store
  deletion requirement more fully.
- **Data export** exists (`GET /api/me/export`) — helpful for privacy reviews.
- **Registration requires Terms/Privacy consent** (checkbox + links) — ensure
  `/terms` and `/privacy` are live before submitting builds.
- **Privacy policy draft** at docs/privacy-policy-draft.md — **must get legal
  review** before the store listing links to it.
- **Closed testing:** set `BALLPICKER_BETA_CODE` to gate registration during
  internal/closed tracks; clear for open testing.
- Before any external testing, walk docs/test-readiness-checklist.md
  (HTTPS, CORS origins, APP_DEBUG=false, backups, rate-limit spot checks).

---

## v1.8.0 store-relevant notes (Monthly Competition Close)

- **Competition trophies are virtual only.** Closing a monthly (or weekly) competition awards
  virtual finish records, XP, and badges — **no prizes, money, entry fees, gambling, or
  payments** of any kind. No change to billing capability, content rating, or permissions.
  Do not describe competitions as prized in the store listing.
- **No fake winners/trophies:** the Trophy Room "Competition trophies" section shows only real
  closed-period placements (empty state otherwise); the current open period is never displayed
  as a trophy.
- **Deploy steps:** run `php artisan migrate` (adds `competition_finishes`) and
  `php artisan db:seed --class=BadgeSeeder` (adds the 3 competition badges — idempotent
  `updateOrCreate`, safe to re-run, never `migrate:fresh`). Closing a period is a manual ops
  command (`ballspot:close-competition`) — nothing runs automatically and `--announce` only
  drafts an announcement (an admin sends it manually).

---

## v1.7.7 store-relevant notes (Notifications)

- **New permission: notifications.** The app now requests OS notification permission, via a
  clear pre-permission in-app prompt ("Stay in the game"), asked once and never blocking the
  app. Declining is fully supported. This adds a **push-notifications capability** to the store
  listing (`expo-notifications` plugin in `app.json`; iOS `UIBackgroundModes`/APNs and Android
  `POST_NOTIFICATIONS` are handled by the config plugin). No IAP, ads, or tracking are added.
- **Content rating unchanged.** Notifications are engagement reminders and admin announcements
  only — no gambling, prizes, money, chat, or user-generated messaging.
- **Privacy / data.** Stored personal data is limited to notification preferences, an optional
  timezone, and (if the user opts in) an Expo push token. All of it cascade-deletes with the
  account and is user-controllable (per-category opt-out). Push tokens are never exposed via
  the API. Reflect this in the store privacy form: "Push tokens — app functionality — not
  shared; deleted with the account."
- **Remote push in production** requires an EAS `projectId` and native push credentials
  (APNs/FCM). Without them, local reminders still work; remote admin announcements are a no-op
  (token registration is best-effort). Set `BALLPICKER_PUSH_ENABLED=true` on the backend once
  credentials exist.

---

## v1.7.9 store-relevant notes (Playable Packs)

- **Packs are now playable but remain content-only.** No prices, purchases, IAP, or shop —
  playing a pack awards only virtual XP and virtual badges. No change to billing capability or
  content rating; no new device permissions.
- **Deploy step:** run `php artisan db:seed --class=BadgeSeeder` after deploy to add the 3 new
  pack badges (idempotent `updateOrCreate` — safe to re-run, never `migrate:fresh`).
- **No fake trophies:** Trophy Room "Pack trophies" only lists real completed packs.

---

## v1.7.8 store-relevant notes (Packs, Subcategories, Monthly)

- **Challenge packs are content-only.** No prices, purchases, IAP, or shop — packs are curated
  collections for discovery. This adds **no** billing capability and does not change the content
  rating. Do not describe packs as purchasable in the store listing.
- **No new device permissions.** Subcategories and the Packs discovery screen use existing
  networked content only.
- **Monthly competition** is virtual leaderboard standing — no prizes, money, or entry fees.
  Trophy Room shows a "Competition trophies" placeholder; **no trophies are awarded yet** (no
  fake wins are ever shown).

---

## v1.6.1 store-relevant notes

- **Login is now email two-factor.** Signing in requires a one-time 6-digit code
  emailed to the account address; a valid password alone no longer logs the user
  in. **A valid, deliverable email is therefore required to sign in.** For
  production, configure a real transactional `MAIL_MAILER` (SMTP) so login-code
  emails are delivered reliably — locally `log` writes the code to `laravel.log`.
  Registration is unchanged (no 2FA on register). See
  [security-auth.md](security-auth.md).
- **No new mobile permissions.** The `LoginVerificationScreen` uses a normal
  numeric text input — no new device permissions, IAP, ads, or tracking are
  introduced. This does not change the store listing category or content rating.

---

## v1.7.3 store-relevant notes

- **XP / rank stays cosmetic progression — no rewards, currency, or money.** v1.7.3 moves XP to a
  ledger (`xp_events`) and adds new XP sources (guesses, badges, streaks) plus rank-up moments and
  an XP history screen. This is still a **cosmetic** long-term progression display: XP **cannot be
  bought, sold, earned for money, or redeemed** for anything of value, and there is no marketplace,
  currency, or gambling. It is distinct from leaderboard rank (position vs. other players). The
  store listing category (no IAP, no gambling, no real money) is unchanged.
- **No new permissions or content-review surfaces.** The XP ledger, rank-up cards, `/me/xp-events`
  screen, and per-sport taglines add no device permissions, no IAP, no ads, and no user-facing
  content review surface.
- **Second-sport prep is admin-facing, not player-facing monetization.** A `SportReadinessService`
  and admin Sports readiness badges help admins decide when a `coming_soon` sport has enough
  content to go live; the daily scheduler now guards `coming_soon`/`hidden` sports (opt-in
  `--allow-coming-soon` for content prep). Non-football sports remain visible-but-disabled roadmap
  teasers — **not** playable, **not** purchasable. Only football is `active`.

### Second-sport activation checklist (advisory)

Thresholds live in `config('ballspot.sport_readiness')` (advisory only — activation is **not**
hard-blocked). Before moving a sport `coming_soon → active`:

- [ ] ≥ 5 **ready** active challenges (active + hidden image + ball position)
- [ ] ≥ 1 scheduled daily challenge for the sport
- [ ] No placeholder / copyright-risk content (rights confirmed per the Content Rights Checklist)
- [ ] Sport has `object_name`, `emoji`, and a `tagline`
- [ ] The mobile flow (Choose Sport → Home → Daily/Tournament) has been tested for the sport

---

## v1.7.2 store-relevant notes

- **Avatar upload now works cross-platform (web + native).** The Expo **web** upload bug
  ("The avatar field must be a file.") is fixed — the client now sends a real `Blob` file part
  on web and the RN descriptor on native (`POST /api/me/avatar`; JPEG/PNG/WebP, max 2 MB, SVG
  rejected). This does **not** change the UGC posture from v1.7: avatars are still
  user-supplied imagery, so keep a reporting/removal path and Terms language for objectionable
  content, and the same photo-library permission string applies. No new permissions are added
  in v1.7.2. Invalid uploads return a clear message: "Please choose a JPG, PNG or WebP image
  under 2MB."
- **Sport "coming soon" is a roadmap teaser — no purchasable content.** The new `coming_soon`
  status makes non-football sports **visible but disabled** ("Coming soon" / "SOON" badge) so
  users can see what's planned. These are **not** playable, **not** purchasable, and carry no
  IAP, unlocks, or paywalls — only football is `active`/playable. `hidden` sports are not shown
  at all. This does not add a store content-review surface or monetization.
- **Personal rank / XP is cosmetic progression — no real rewards or money.** The new
  rank/level/XP system (Rookie → Ball Master) is a **cosmetic** long-term progression display
  derived from the player's lifetime score. There are **no real prizes, no currency, no
  purchases, and no gambling** — XP cannot be bought, sold, or redeemed. It is distinct from
  leaderboard rank (position vs. other players). This keeps the store listing category (no IAP,
  no gambling, no real money) intact.

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
