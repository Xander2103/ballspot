# BallPicker Security & Authentication

**Sprint:** v1.6.1 — Secure Email Two-Factor Login

This document describes how login authentication works in BallPicker, focused on
the email-based two-factor (2FA) login introduced in v1.6.1. Registration,
`/api/me`, logout, account deletion, and password reset are unchanged — but note
that **after a password reset, the next login now goes through 2FA** like any
other login.

---

## Overview

Login is now **email two-factor**. A correct password alone no longer returns an
API token. Instead, a valid password triggers a one-time **6-digit code** emailed
to the account address. The user must submit that code to a verification endpoint
before any token is issued. The Sanctum token is created **only** after the code
is successfully verified.

The design goals for this sprint:

- No account/email enumeration — invalid credentials and unknown emails are
  indistinguishable, and no email is sent for them.
- No raw code is ever returned by any API response; the plain code exists only in
  the outgoing email.
- Only the bcrypt **hash** of the code is stored.
- Codes expire quickly, are attempt-limited, and lock on abuse.
- Generic error messages throughout (wrong / expired / consumed / locked codes are
  indistinguishable to the caller).

---

## Login flow

### 1. `POST /api/login` — password step

Request:

```json
{ "email": "x@example.com", "password": "password123" }
```

On **valid** credentials — **no token is returned**. A 6-digit code is emailed and
the response is:

```json
{
  "requires_2fa": true,
  "verification_id": "<uuid>",
  "message": "We sent a verification code to your email."
}
```

On **invalid** credentials (wrong password) **or unknown email** — a generic
HTTP **422** validation error (under `email`), and **no email is sent**:

```json
{ "message": "Invalid credentials." }
```

The unknown-email path runs a dummy `Hash::make` so the response timing matches the
valid-user path (anti-enumeration / timing-attack mitigation).

### 2. `POST /api/login/verify` — code step

Request:

```json
{ "verification_id": "<uuid>", "code": "123456" }
```

On **success**, the token is issued and the response is the same shape the app has
always expected from login:

```json
{ "user": { "id": 1, "name": "Xander", "username": "xander" }, "token": "1|..." }
```

On **any failure** — HTTP **422**, generic message (a wrong code, an expired code,
an already-consumed code, and a locked code are all indistinguishable):

```json
{ "message": "Invalid or expired verification code." }
```

A wrong code **increments the attempt counter** for that code (see attempt limits).

### 3. `POST /api/login/resend-code` — resend step

Request:

```json
{ "verification_id": "<uuid>" }
```

Re-issues a code on the **same** verification session (same `verification_id`),
resetting the code hash, attempts, and expiry. It is cooldown-limited.

```json
// Success (generic)
{ "message": "If your login is still pending, a new code has been sent to your email." }

// 422 — session expired / consumed / unknown
{ "message": "Please login again." }

// 422 — still within the resend cooldown window
{ "message": "Please wait a moment before requesting another code." }
```

---

## Email 2FA storage & security

Codes live in the `login_verification_codes` table:

| Column | Notes |
|--------|-------|
| `id` | Raw DB primary key — **never exposed** in any API response |
| `verification_id` | UUID, unique — the public handle used by the API |
| `user_id` | FK → `users` (cascade on delete) |
| `email` | Address the code was sent to |
| `code_hash` | **bcrypt hash of the code** — the plain code is never stored |
| `code_sent_at` | Drives the resend cooldown |
| `expires_at` | Expiry timestamp (10 minutes after issue) |
| `attempts` | Wrong-code counter (default 0) |
| `consumed_at` | Nullable — set when the code is successfully verified |
| `ip_address` | Captured at issue |
| `user_agent` | Captured at issue |
| `created_at` / `updated_at` | Timestamps |

Security properties:

- **Hashed storage only.** Only the bcrypt hash of the code is persisted. The plain
  6-digit code exists only in the outgoing email — there is no endpoint or response
  that returns it.
- **Expiry — 10 minutes.** Codes expire after `login_code_expiry_minutes` (default
  10). Expired codes fail verification with the generic error.
- **Attempt limit — 5, then locked.** After `login_code_max_attempts` (default 5)
  wrong attempts, the code is **locked**: even the correct value is then rejected.
  The user must log in again to receive a fresh code.
- **Single active code per user.** Issuing a new code on login **deletes the user's
  prior pending codes**. A successful verify **consumes** the code (sets
  `consumed_at`) and deletes any other pending codes for that user. Consumed codes
  cannot be reused.
- **Resend cooldown — 60 seconds.** Tracked via `code_sent_at`. A resend within the
  cooldown returns the generic "please wait" 422. A resend resets `code_hash`,
  `attempts`, and expiry on the **same** `verification_id`.
- **No enumeration, generic errors.** No raw code in any response; unknown emails
  send no email and return the same generic error as a bad password; verify/resend
  errors are generic so an attacker cannot tell wrong from expired from consumed
  from locked.

---

## Rate limiting

Named limiters are defined in `AppServiceProvider` and applied via the `throttle:`
middleware on the auth routes. These bound automated fan-out; the **per-code
5-attempt lock and the 60s resend cooldown remain the primary guess-stoppers**.

| Route | Limiter | Limit |
|-------|---------|-------|
| `POST /api/login` | `throttle:login` | 5/min per email+IP, plus 20/min per IP |
| `POST /api/login/verify` | `throttle:login-verify` | 20/min per `verification_id`+IP |
| `POST /api/login/resend-code` | `throttle:login-resend` | 5/min per `verification_id`+IP |

---

## Configuration

Config lives in `config/ballspot.php` under the `auth` block, each with an env
override:

| Config key | Default | Env override |
|------------|---------|--------------|
| `auth.login_code_expiry_minutes` | `10` | `BALLSPOT_LOGIN_CODE_EXPIRY_MINUTES` |
| `auth.login_code_max_attempts` | `5` | `BALLSPOT_LOGIN_CODE_MAX_ATTEMPTS` |
| `auth.login_code_resend_cooldown_seconds` | `60` | `BALLSPOT_LOGIN_CODE_RESEND_COOLDOWN_SECONDS` |
| `app_name` | `BallPicker` | `BALLSPOT_APP_NAME` |

`config('ballspot.app_name')` is used in the email subject — **"Your BallPicker
login code"**.

---

## The verification email

Notification class: `App\Notifications\LoginVerificationCodeNotification`.

- **Subject:** "Your BallPicker login code"
- **Body:**
  - "Your login code is: 123456"
  - "This code expires in 10 minutes."
  - "If this was not you, you can ignore this email."

### Local dev mail behavior

- The mail driver is `log` in local dev, so the **full verification email
  (including the code)** is written to `backend/storage/logs/laravel.log`. Read the
  code there to complete a login locally.
- In tests, `MAIL_MAILER=array` and notifications are faked.
- There is **no** endpoint or API response that returns the raw code — the log (or
  a real mailbox in production) is the only place the plain code appears.

---

## Cleanup

Expired and consumed codes are cleaned up two ways:

- **Opportunistic (no cron needed):** login purges the user's old pending codes, and
  a successful verify deletes the user's other pending codes. Nothing depends on a
  scheduled job.
- **Artisan command:** `php artisan ballspot:cleanup-login-codes` deletes expired +
  consumed codes on demand (useful for housekeeping, not required for correctness).

---

## Mobile

- **`authApi.login`** now returns a union type `LoginResult`:
  - `AuthResponse` — `{ user, token }` (unchanged shape), or
  - `TwoFactorRequired` — `{ requires_2fa, verification_id, message }`.
  - An `isTwoFactorRequired` type guard distinguishes them.
- **New methods:** `authApi.verifyLoginCode({ verification_id, code })` and
  `authApi.resendLoginCode({ verification_id })`.
- **New `LoginVerificationScreen`:** title "Check your email", a subtitle naming the
  email, a 6-digit numeric autofocus input, a "Verify and continue" button, a
  "Resend code" control with a 60s cooldown countdown, and "Back to login".
  `LoginScreen` navigates here when the login response is `requires_2fa`.
- **Token handling:** the token is stored **only after** successful verification.
  On success the token is saved, the theme is applied, and the app resets to Home
  (or SportSelection for users with no preferred sport).

---

## Registration is unchanged

`POST /api/register` still returns `{ user, token }` immediately — there is **no**
2FA on register. 2FA applies to login only.

---

## Future upgrades

The following are intentionally out of scope for this sprint and are candidates for
future work:

- **Email-only 2FA** — no SMS, no authenticator app / TOTP, and no passkeys yet (by
  design this sprint).
- **2FA enforced on every login** — there is no device-trust / "remember this
  device" option yet.
- **No suspicious-login alerts yet.**
- Future candidates: authenticator app / TOTP, passkeys, device trust /
  remember-device, suspicious-login alerts, and an optional per-user toggle for 2FA.
