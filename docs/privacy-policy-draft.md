# BallPicker Privacy Policy (DRAFT)

> **⚠️ DEVELOPER DRAFT — NOT LEGAL ADVICE.**
> This draft was written by the development team to describe what the app
> actually does. It MUST be reviewed by a qualified lawyer (GDPR/consumer law)
> before public launch. Placeholders in [brackets] must be filled in.

_Last updated: [DATE]_

## Who we are

BallPicker is operated by **[OPERATOR NAME / COMPANY]**, based in
**[COUNTRY]**. For anything in this policy, contact us at
**[privacy@your-domain.com]**.

## The short version

- We collect only what the game needs: your email, a display name, your
  guesses/scores, and optional settings like an avatar and notification
  preferences.
- We **do not sell your data**. We show **no ads**. There are **no payments,
  no gambling, and no real-money prizes** — every reward in BallPicker
  (XP, ranks, badges, trophies) is virtual and has no monetary value.
- There is **no chat** and no tracking/analytics SDK in the app.
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
appears on leaderboards next to your scores.

**Optional profile data** — an avatar photo, a preferred sport and a theme,
if you choose to set them.

**Notifications** — if you enable reminders or announcements, we store your
notification settings (including your timezone, so reminders arrive at the
right local time) and, for announcements, your device's push token. The
operating-system permission prompt is always separate and entirely up to you,
and you can switch every notification type off in your Profile — announcements
from us are opt-out at any time.

**Security data** — when a login verification code is issued we record the IP
address and browser/device string it was requested from, to detect abuse.

## What we do NOT do

- No sale or sharing of personal data for marketing.
- No advertising, no ad networks, no tracking or analytics SDKs.
- No payments, no gambling, no real-money rewards of any kind.
- No chat or private messaging.
- No reading of your contacts, location, or photos (except the single photo
  you pick as an avatar).

## Who processes your data

Our servers at **[HOSTING PROVIDER, REGION]** store your data. Two service
providers touch small parts of it to make features work:

- **[EMAIL PROVIDER]** delivers account emails (verification, password reset).
- **Expo push service** delivers announcements to your device, if you opted in
  (it receives your push token, not your name or email).

## How long we keep things

- Account data: until you delete your account.
- Verification codes and reset tokens: minutes to hours (then purged).
- Push tokens: removed when you log out or delete your account, and
  automatically after ~90 days if a device stops checking in.
- Gameplay history (scores, XP, placements): kept after account deletion, but
  permanently disconnected from your identity — your entries become
  "Deleted User" (see below).

## Deleting your account

Profile → Delete account. This immediately:

- signs out every device and invalidates all sessions,
- deletes your avatar photo, push tokens, notification settings and pending
  verification codes,
- replaces your name, username and email with anonymous placeholders.

Your scores remain in past leaderboards and tournaments as "Deleted User" so
other players' results stay intact — they can no longer be linked to you.

## Your rights

Depending on where you live (e.g. under the GDPR), you have the right to
**access**, **correct**, **delete**, **restrict**, and **object to** the
processing of your personal data, and to **data portability**.

- **Access/portability:** the app's data-export endpoint
  (`GET /api/me/export`) returns everything we store about you as JSON.
- **Deletion:** Profile → Delete account, or email us.
- **Everything else:** email **[privacy@your-domain.com]** and we will respond
  within the legally required time. You also have the right to complain to
  your local data-protection authority.

## Children

BallPicker is not directed at children under **[13/16 — set per target
markets]**. We do not knowingly collect data from children below that age; if
you believe a child has created an account, contact us and we will delete it.

## Changes to this policy

If this policy changes in a way that matters, we will tell you in the app
before the change takes effect. The "last updated" date at the top always
reflects the current version.

## Contact

Privacy questions: **[privacy@your-domain.com]**
General support: **[support@your-domain.com]**
