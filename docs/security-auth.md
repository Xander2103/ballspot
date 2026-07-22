# BallPicker Security & Authentication

**Sprint:** v1.6.2 — Email Verification at Registration + Configurable Login 2FA

This document describes how authentication works in BallPicker. As of v1.6.2 the
strategy is:

- **Email verification happens at registration.** New accounts are created
  **unverified** and must verify a one-time 6-digit code before they can use the
  app. Access to protected endpoints is gated by Laravel's `verified` middleware.
- **Normal login is email + password**, once the email is verified. The 6-digit
  login 2FA introduced in v1.6.1 still exists but is **off by default** and opt-in
  via config (`force_login_2fa`). **Admins always get login 2FA** regardless of the
  flag.
- Password reset and account deletion are unchanged (account deletion works even for
  an unverified user).

> **Change from v1.6.1:** login 2FA used to fire on *every* login. It is now
> **configurable and off by default**; the 6-digit code moved to registration-time
> email verification for regular users.

---

## Overview / design goals

The same security posture applies to both the registration email-verification code
and the (optional) login 2FA code:

- No account/email enumeration — invalid credentials and unknown emails are
  indistinguishable, and no email is sent for them.
- No raw code is ever returned by any API response; the plain code exists only in
  the outgoing email (or the local dev log).
- Only the bcrypt **hash** of the code is stored.
- Codes expire quickly, are attempt-limited, and lock on abuse.
- Generic error messages throughout (wrong / expired / consumed / locked codes are
  indistinguishable to the caller).

---

## Email verification (registration)

New in v1.6.2. The `User` model implements `MustVerifyEmail`.

### 1. `POST /api/register` — create an unverified account

Registration creates the account, emails a 6-digit **verification** code, and
returns a token immediately (HTTP **201**):

```json
{ "user": { "id": 1, "name": "Xander", "username": "xander" },
  "token": "1|...", "email_verified": false }
```

The token is issued so the app can complete verification and read `/me`, but
protected app endpoints stay gated (403) until the email is verified. When
`require_email_verification` is `false`, new accounts are **auto-verified** and this
step is skipped.

### 2. `POST /api/email/verify` — submit the code *(auth; NOT gated by verified)*

```json
// Request
{ "code": "123456" }

// Response 200 — success
{ "email_verified": true, "user": { ... }, "message": "..." }

// Response 422 — wrong / expired / consumed code (generic)
{ "message": "Invalid or expired verification code." }

// Response 401 — no bearer token
{ "message": "Unauthenticated." }
```

A wrong code increments the attempt counter; after 5 wrong attempts the code locks.
Codes expire after **60 minutes** (`email_code_expiry_minutes`).

### 3. `POST /api/email/verification-notification` — resend *(auth)*

Resends a code, cooldown-limited to 60s. Already-verified accounts get a friendly
no-op:

```json
// Response 200 — code (re)sent
{ "email_verified": false, "message": "..." }

// Response 200 — already verified
{ "email_verified": true, "message": "Your email is already verified." }
```

Both email endpoints **require authentication** (401 without a token) but are **not**
behind the `verified` middleware — an unverified user must be able to reach them.

### Access gating (`verified` middleware → 403)

Protected app endpoints (profile/stats, sports, preferences, avatar, badges,
leagues, rounds, daily) are behind the `verified` middleware and return **HTTP 403
"Your email address is not verified."** for unverified users.

Endpoints available to an authenticated-but-**unverified** user:

- `GET /api/me` — now includes an `email_verified` boolean
- `POST /api/logout`
- `DELETE /api/account`
- `POST /api/email/verify`
- `POST /api/email/verification-notification`

So an unverified user can inspect, verify, or delete their account, but cannot play.

### Storage — `email_verification_codes`

Same security posture as the login-code table (hash-only, expiry, attempt lock,
single active code per user, resend cooldown).

| Column | Notes |
|--------|-------|
| `user_id` | FK → `users` |
| `code_hash` | **bcrypt hash of the code** — the plain code is never stored |
| `code_sent_at` | Drives the 60s resend cooldown |
| `expires_at` | Expiry (60 minutes after issue) |
| `attempts` | Wrong-code counter; locks at 5 |
| `consumed_at` | Set when verification succeeds; consumed codes cannot be reused |

Service `EmailVerificationService`; notification
`EmailVerificationCodeNotification` (subject **"Verify your BallPicker email"**;
body "Your email verification code is: 123456", the expiry, and "If you did not
create an account, ignore.").

---

## Login flow (configurable 2FA)

### 1. `POST /api/login` — password step

Request:

```json
{ "email": "x@example.com", "password": "password123" }
```

On **valid** credentials there are now **three** outcomes (all HTTP **200**):

**a) Email not verified** — a verification code is (re)sent (cooldown-respecting),
and a token is returned so the app can drive the verify screen:

```json
{
  "requires_email_verification": true,
  "email_verified": false,
  "user": { ... },
  "token": "1|...",
  "message": "..."
}
```

**b) Verified + forced 2FA** — when `force_login_2fa=true` **OR** the user is an
admin. A 6-digit login code is emailed; **no token** is returned here (only from
`POST /api/login/verify`):

```json
{
  "requires_2fa": true,
  "verification_id": "<uuid>",
  "message": "We sent a verification code to your email."
}
```

**c) Verified + no forced 2FA (DEFAULT)** — email+password is enough; the token is
returned directly:

```json
{ "user": { ... }, "token": "1|..." }
```

On **invalid** credentials (wrong password) **or unknown email** — a generic
HTTP **422** validation error (under `email`), and **no email is sent**:

```json
{ "message": "Invalid credentials." }
```

The unknown-email path runs a dummy `Hash::make` so the response timing matches the
valid-user path (anti-enumeration / timing-attack mitigation).

> The two-step code flow below (`/login/verify`, `/login/resend-code`) is only
> exercised on the **forced-2FA / admin** path (outcome **b**).

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

## Login 2FA storage & security

Applies to the **forced-2FA / admin** login path. Login codes live in the
`login_verification_codes` table:

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
| `auth.require_email_verification` | `true` | `BALLPICKER_REQUIRE_EMAIL_VERIFICATION` |
| `auth.email_code_expiry_minutes` | `60` | `BALLPICKER_EMAIL_CODE_EXPIRY_MINUTES` |
| `auth.force_login_2fa` | `false` | `BALLPICKER_FORCE_LOGIN_2FA` |
| `auth.login_code_expiry_minutes` | `10` | `BALLSPOT_LOGIN_CODE_EXPIRY_MINUTES` |
| `auth.login_code_max_attempts` | `5` | `BALLSPOT_LOGIN_CODE_MAX_ATTEMPTS` |
| `auth.login_code_resend_cooldown_seconds` | `60` | `BALLSPOT_LOGIN_CODE_RESEND_COOLDOWN_SECONDS` |
| `app_name` | `BallPicker` | `BALLSPOT_APP_NAME` |

- **`require_email_verification`** — when `false`, new accounts are auto-verified at
  registration and login is plain email+password (no verification gate).
- **`email_code_expiry_minutes`** — lifetime of the registration verification code
  (default 60). The `email_code` flow **reuses** `login_code_max_attempts` (5) and
  `login_code_resend_cooldown_seconds` (60) for its attempt lock and resend cooldown.
- **`force_login_2fa`** — when `true`, every **verified** login goes through the
  6-digit login 2FA. **Admins always get login 2FA** regardless of this flag.
- Existing login-code config (`login_code_*`) is unchanged.

`config('ballspot.app_name')` is used in the email subjects — **"Your BallPicker
login code"** (login 2FA) and **"Verify your BallPicker email"** (registration).

---

## The verification email

Notification class: `App\Notifications\LoginVerificationCodeNotification`.

- **Subject:** "Your BallPicker login code"
- **Body:**
  - "Your login code is: 123456"
  - "This code expires in 10 minutes."
  - "If this was not you, you can ignore this email."

---

## Mail configuration (local dev vs production)

Both the registration verification code and the login 2FA code are delivered by
email. There is **no** endpoint or API response that returns the raw code — email
(or the local dev log) is the only place the plain code appears.

**Local dev — `MAIL_MAILER=log`:**

- The **full verification/login email (including the code)** is written to
  `backend/storage/logs/laravel.log`. Copy the code from there to complete
  registration/login locally.
- In automated tests, `MAIL_MAILER=array` and notifications are faked.

**Production — real transactional mail is mandatory.** A deliverable email is now
**required to activate an account** (verify at registration), so a real mailer must
be configured. Set the standard Laravel mail env vars:

```
MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=...
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_ENCRYPTION=tls        # or MAIL_SCHEME
MAIL_FROM_ADDRESS=...
MAIL_FROM_NAME="BallPicker"
```

(An API-based mailer works equally well in place of SMTP.)

---

## Cleanup

Expired and consumed codes (both login and email-verification codes) are cleaned up
two ways:

- **Opportunistic (no cron needed):** register / login / verify purge the user's old
  pending codes. Nothing depends on a scheduled job.
- **Artisan command:** `php artisan ballspot:cleanup-login-codes` deletes expired +
  consumed codes on demand — it now also removes stale **email verification** codes.

---

## Mobile

- **`authApi.login`** returns a **3-way** union `LoginResult`:
  - `AuthResponse` — `{ user, token }`,
  - `TwoFactorRequired` — `{ requires_2fa, verification_id, message }`, or
  - `EmailVerificationRequired` — `{ requires_email_verification, email_verified,
    user, token, message }`.
  - Type guards `isTwoFactorRequired` and `isEmailVerificationRequired` distinguish
    them.
- **New methods:** `authApi.verifyEmail({ code })` and
  `authApi.resendEmailVerification()`, alongside the existing
  `authApi.verifyLoginCode({ verification_id, code })` and
  `authApi.resendLoginCode({ verification_id })`. The `User` type gains
  `email_verified`.
- **New `EmailVerificationScreen`:** title "Check your email", a 6-digit autofocus
  input, a "Verify email" button, a "Resend code" control with a 60s cooldown, and
  "Back to login" (which clears the pending token). `RegisterScreen` routes here
  after register; `LoginScreen` routes here on `requires_email_verification`;
  `AppNavigator` boot routes unverified logged-in users here. On success it applies
  the theme and routes to SportSelection (new users) or Home.
- **`LoginVerificationScreen`** (login 2FA) is retained for the forced-2FA / admin
  path: 6-digit autofocus input, "Verify and continue", "Resend code" with a 60s
  cooldown, and "Back to login". `LoginScreen` navigates here on `requires_2fa`. The
  token is stored **only after** successful verification.

---

## Registration (email verification required)

`POST /api/register` now creates an **unverified** account, emails a 6-digit
verification code, and returns `{ user, token, email_verified: false }` (HTTP 201).
The token is issued so the app can complete verification and read `/me`, but
protected endpoints are gated (403) until `POST /api/email/verify` succeeds. See the
**Email verification (registration)** section above. When
`require_email_verification` is `false`, accounts are auto-verified at registration.

---

## Future upgrades

The following are intentionally out of scope for this sprint and are candidates for
future work:

- **Email-only codes** — no SMS, no authenticator app / TOTP, and no passkeys yet
  (by design).
- **Login 2FA is a single global flag (`force_login_2fa`) plus an admin override** —
  there is no per-user opt-in 2FA toggle or device-trust / "remember this device"
  option yet.
- **No suspicious-login alerts yet.**
- Future candidates: authenticator app / TOTP, passkeys, device trust /
  remember-device, suspicious-login alerts, and a per-user 2FA toggle.
