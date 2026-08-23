# BallPicker Privacy Policy (DRAFT)

> **⚠️ DEVELOPER DRAFT — NOT LEGAL ADVICE.**
> This draft was written by the development team to describe what the app
> actually does. It MUST be reviewed by a qualified lawyer (GDPR/consumer law)
> before public launch. Placeholders were filled in on 2026-08-20.

_Last updated: 2026-08-20_

## Who we are

BallPicker is operated by **Xander Van Malder**, based in
**Belgium**. For anything in this policy, contact us at
**duisburg2103@gmail.com**.

## The short version

- We collect only what the game needs: your email, a display name, your
  guesses/scores, and optional settings like an avatar and notification
  preferences.
- We **do not sell your data**. We show **no ads**. There are **no payments,
  no gambling, and no real-money prizes** — every reward in BallPicker
  (XP, ranks, badges, trophies) is virtual and has no monetary value.
- There is **no chat** and no tracking/analytics SDK in the app. Adding a
  friend does not open a message channel — friends only see each other's
  public profile.
- Your **camera** is used for one thing only: scanning a friend's QR code,
  and only when you tap "Scan QR code". Nothing is recorded or uploaded.
- You can download your data and delete your account from within the app at
  any time.

## What we collect and why

**Account data** — your email address, display name, username and a securely
hashed password. We need these to create your account, sign you in, and send
you account emails (verification codes, password reset). Your email is never
shown to other players.

**Gameplay data** — your guesses, scores, XP, streaks, badges, trophies and
tournament/competition placements. This is the game itself: leaderboards,
rankings and your Trophy Room are built from it. Your username/display name
appears on leaderboards next to your scores, and the trophies you have earned
are shown on your public profile alongside your rank.

**Optional profile data** — an avatar photo, a preferred sport and a theme,
if you choose to set them.

**Notifications** — if you enable reminders or announcements we store your
notification settings (daily-reminder, tournament-reminder and announcement
switches, your chosen reminder time, and your timezone so reminders arrive at
the right local time) and, per device, a **push token** together with the
platform (iOS/Android), an optional device name, and when that device was last
seen. A push token is a technical address for one app install — it is not your
name or email.

We use it to send: daily challenge reminders, tournament reminders and updates,
and important announcements from us. The operating-system permission prompt is
always separate and entirely up to you, you can switch every notification type
off in your Profile, and **announcements are opt-out at any time — if you turn
them off we exclude your devices from the send**. You can also revoke
notifications entirely in your device settings.

*Legal basis: your consent / the preference you set. We do not send optional
announcements to anyone who has opted out.*

**Friends** — when you use the friends feature we store a **friend code** for
your account (a short shareable code, also shown as a QR code), the friend
requests you send and receive (who sent it, who received it, its status and
when), and your confirmed friendships. We use this to let people add each
other, to show your friends list and pending requests, and to show a friend's
public profile.

Other players **never** see your email address or your friend code. A player's
public profile shows only their display name, username, avatar, rank/level/XP,
aggregate gameplay stats and the trophies/badges they have earned. The QR code encodes the friend code
and nothing else — no email, no account identifier, no personal data.

*Legal basis: performance of the service — this is a feature you choose to use.*

**Security data** — when a login verification code is issued we record the IP
address and browser/device string it was requested from, to detect abuse.

*Legal basis: our legitimate interest in keeping accounts secure.*

## What we do NOT do

- No sale or sharing of personal data for marketing.
- No advertising, no ad networks, no tracking or analytics SDKs.
- No payments, no gambling, no real-money rewards of any kind.
- No chat or private messaging — including between friends.
- No reading of your contacts, location, or photos (except the single photo
  you pick as an avatar).
- No background camera use. The camera opens only on the QR scanner screen,
  only after you tap "Scan QR code", and only to read a friend code. We do not
  store, upload or keep any image or video from it. If you decline the camera
  permission the feature still works — you can type the friend code by hand.
- No silent clipboard reading. The app only **writes** to your clipboard, when
  you tap "Copy friend code". It never reads your clipboard contents.

## Who processes your data

Our servers at **Hetzner Online GmbH, Germany** store your data. Two service
providers touch small parts of it to make features work:

- **Zxcs B.V. (Netherlands)** delivers account emails (verification, password reset).
- **Expo push service** delivers notifications to your device, if you opted in
  (it receives your push token and the message text, not your name or email).
  Expo in turn hands the message to your platform's own push network:
  **Apple Push Notification service (APNs)** on iOS, and **Firebase Cloud
  Messaging (FCM)** on Android where applicable. Which of these is involved
  depends on your device and platform. We never send your email, password or
  gameplay history through these services.

## How long we keep things

- Account data: until you delete your account.
- Verification codes and reset tokens: minutes to hours (then purged).
- Push tokens: removed when you log out (where your device can reach our servers
  at that moment), on account deletion, and automatically after ~90 days if a
  device stops checking in.
- Friend code, friend requests and friendships: until you remove the friend or
  delete your account (deleting your account removes all of them).
- Gameplay history (scores, XP, placements): kept after account deletion, but
  permanently disconnected from your identity — your entries become
  "Deleted User" (see below).

**Removing a finished tournament from your list is not a deletion.** It only
hides that tournament from your own Home list — your result stays saved, still
appears in your Profile history, and other players' leaderboards are unchanged.
Nobody else's view is affected.

## Deleting your account

Profile → Delete account. This immediately:

- signs out every device and invalidates all sessions,
- deletes your avatar photo, push tokens, notification settings and pending
  verification codes,
- deletes your friendships and every friend request you sent or received, so
  you disappear from other players' friends lists and pending requests,
- clears your friend code, so it stops working for anyone who still has it,
- replaces your name, username and email with anonymous placeholders.

Your scores remain in past leaderboards and tournaments as "Deleted User" so
other players' results stay intact — they can no longer be linked to you.

We keep an **anonymous, aggregate count** of how many accounts have been
deleted (a single number, visible only to administrators). It contains no
personal data and cannot be traced back to you.

## Your rights

Depending on where you live (e.g. under the GDPR), you have the right to
**access**, **correct**, **delete**, **restrict**, and **object to** the
processing of your personal data, and to **data portability**.

- **Access/portability:** the app's data-export endpoint
  (`GET /api/me/export`) returns everything we store about you as JSON —
  including your friend code, your friends list and your pending friend
  requests. It deliberately excludes secrets (no password hash, no API or
  reset tokens, and **no raw push-token values** — only the platform and
  timestamps for each device). It also never includes another player's email
  address or friend code.
- **Deletion:** Profile → Delete account, or email us.
- **Everything else:** email **duisburg2103@gmail.com** and we will respond
  within the legally required time. You also have the right to complain to
  your local data-protection authority.

## Children

BallPicker is not directed at children under **16** (the GDPR Art. 8 default;
configurable per market via `BALLSPOT_MINIMUM_AGE` — if lowered for a specific
market, update this policy too). Registration requires the user to confirm they
meet this age. We do not knowingly collect data from children below that age; if
you believe a child has created an account, contact us and we will delete it.

## Changes to this policy

If this policy changes in a way that matters, we will tell you in the app
before the change takes effect. The "last updated" date at the top always
reflects the current version.

## Contact

Privacy questions: **duisburg2103@gmail.com**
General support: **duisburg2103@gmail.com**
