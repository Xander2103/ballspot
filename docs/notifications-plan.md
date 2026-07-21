# Notifications Plan (Foundation / Docs Only)

Status: **planning only — no native push is implemented in v1.6.**

This document scopes a future push-notification feature. Nothing here ships yet;
it exists so the next sprint can implement notifications safely and with an
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
