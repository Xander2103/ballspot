# Notifications Plan → Implemented (v1.7.7)

Status: **implemented (v1.7.7)** — opt-in local reminders, per-user notification
settings, Expo push-token registration, and an admin announcement composer now ship.
The rest of this doc is the original design; the section below records what actually
shipped and the known limits.

## Implemented in v1.7.7

- **Permission flow (mobile):** first time on Home after login, a one-time in-app prompt
  ("Stay in the game") explains value, then requests OS permission. Declining does not
  block the app and is not re-asked (persisted flag). Re-enable from Profile → Notifications.
  Web is unsupported and no-ops (never crashes).
- **Settings (synced):** `notification_settings` table + `GET/PUT /api/me/notification-settings`.
  Types: `daily_reminder`, `tournament_reminder`, `admin_announcement`; `reminder_time`
  default **19:00** (config `ballspot.notifications.default_reminder_time`); nullable timezone.
- **Local scheduling (Expo Notifications):** daily reminder scheduled at `reminder_time`
  only when today's daily is **not** already done (re-evaluated on each Home focus, so
  completing it cancels the reminder). Tournament reminder scheduled only when the user has
  a pending tournament action. Copy per spec.
- **Remote push:** `push_tokens` table + `POST /api/me/push-tokens`. Admin composer
  `/admin/notifications` sends via Expo's push HTTP API to opted-in tokens; opt-out is
  always respected; status is real (`draft`/`sent`/`failed`), never faked.

### Known limitations

- **Tournament "pending" is tournament-level** (an active tournament with rounds left), not a
  precise per-user "you haven't guessed this round" signal.
- **Daily completion is evaluated on app open**, not at delivery time — a reminder scheduled
  earlier can still fire after completion if the app isn't reopened. Delivery-time suppression
  needs backend remote push (future).
- **Remote push needs an EAS `projectId`** in a real build; token registration is best-effort
  and silently skips if absent/offline. `send_at` scheduling is stored but not auto-dispatched
  (send is manual/immediate in MVP).

## Competition close draft (v1.8.0)

`php artisan ballspot:close-competition --announce` saves a **draft** admin announcement after
a successful close ("Monthly results are in" / "…check the leaderboard and Trophy Room",
target `opted_in`, no creator). It is **never auto-sent** — an admin reviews and sends it
manually from `/admin/notifications`, so users are never spammed by an automated close. A rerun
of an already-closed period creates no additional draft.

---

## Original design (retained for reference)

This document originally scoped the feature before it shipped, with an
opt-in-first, privacy-respecting design.

## Why not now

Full Expo push setup (native credentials for APNs + FCM, token lifecycle,
delivery backend, opt-in UX, and a send scheduler) is more than this sprint
should take on. We deliberately avoid overbuilding. The app must never crash or
degrade because push is absent — today it simply has no push.

## Candidate notifications

| Notification | Trigger | Priority | Notes |
|---|---|---|---|
| Daily challenge reminder | New daily challenge available and user hasn't played | High | The core retention loop |
| Streak protection reminder | User has an active streak and hasn't played today (evening) | High | Only if user has a streak ≥ 2 |
| Tournament round available | A new round opens in a tournament the user is in | Medium | Respect per-day round limits |
| Friend joined tournament | Someone joins a lobby the user owns/belongs to | Low | Requires membership events |
| Leaderboard beaten | Someone passes the user on the weekly board | Low | Can be noisy — batch/throttle |

## Opt-in requirements

- **Off by default.** No notifications until the user explicitly enables them.
- Ask for OS permission only after an in-app explanation of value (pre-permission prompt).
- Per-category toggles in a future Settings screen (reminders vs. social vs. leaderboard).
- A single global "pause all" switch.
- Honor OS-level permission revocation — never attempt to re-prompt aggressively.

## Expo push token storage (future)

- New table `push_tokens`: `id, user_id, expo_token (unique), platform, last_used_at, timestamps`.
- Store the Expo push token after opt-in; refresh on app launch when it changes.
- Remove tokens on logout and on `DeviceNotRegistered` errors from Expo.
- Never store a token for a user who has not opted in.

## Sending (future)

- A queued job builds the recipient list from opt-in flags + eligibility, then
  sends via Expo's push API in batches. No third-party analytics on message contents.
- All scheduled sends run from backend commands (mirrors `ballspot:schedule-daily-challenges`).

## Privacy considerations

- Notification content must not leak another user's private data (use display names only).
- Minimize payload; no scores/positions of other identifiable users in the payload.
- Provide an in-app and OS-level way to turn everything off.
- Document data retention for tokens (delete on logout / inactivity).
- GDPR: tokens are personal data — include them in account deletion and export flows.

## Non-goals for the notification sprint

- No marketing/promotional blasts.
- No chat or social messaging.
- No real-time delivery guarantees.
