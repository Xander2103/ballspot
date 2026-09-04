# BallPicker v1.9.5 — release-blocker sprint (2026-09-04)

Launch hardening only: no redesign, no data loss, no `migrate:fresh`,
**no new migrations**. Existing users, challenges, packs and tournaments are
untouched.

## Root causes found

| # | Symptom | Root cause |
|---|---|---|
| 2 | Reset link does not work; pasting it in the app does not work | The email links to `{FRONTEND_URL}/reset-password?token=…`. In production that is the Laravel backend domain, which had **no such route** (`curl` → 404). The app had no deep-link config and its "Reset code" field expected the bare 64-char token, so a pasted link failed too. |
| 3 | Registration code "does not work" | Every send **deleted the previous code**, and logging in again (after the 60 s cooldown) silently sent a new one. A late/out-of-order email therefore carried a dead code. Also: switching verification off by config still left the framework `verified` gate 403-ing every unverified account. |
| 4 | Beta code shown in production | The register screen always rendered the field; the app had no way to know whether the gate was on. |
| 5 | "Failed to delete account", no success message | The app hid the real error behind one fixed string and never showed success. Backend deletion was not transactional and logged nothing on failure. (The anonymization itself already frees email + username; that is now covered by tests.) |
| 6 | "This pack attempt is not active" on 10/10 | The final guess completes the attempt correctly; the 422 came from a **second submit** of the same guess (double tap or a lost response, e.g. a post-commit reward exception). The endpoint was not idempotent and there was no completion overview. |
| 7 | Play again allowed | Replay was a deliberate v1.7.9 design; it let players re-run known photos. |
| 1 | Login flow "unstable" | Raw server strings (`Unauthenticated.`, `Server Error`, `The given data was invalid.`) surfaced in alerts; no 401 handling on the verification screen. |

## Backend changes (Laravel)

- **Password reset web fallback**: `GET|POST /reset-password`, `GET|POST /forgot-password`
  (`Web\PasswordResetWebController`, views `public/reset-password*.blade.php`,
  `public/forgot-password.blade.php`), shared `PasswordResetFlow` service used by the
  API too. `no-store` caching, deep-link button `ballpicker://reset-password?…`,
  events `password.reset_requested/failed/completed` (never token/email/password).
- **Verification codes** (`EmailVerificationService`): last 3 unconsumed codes stay
  valid, shared attempt lock, specific expired/locked messages, `hasUsableCode()`;
  login no longer replaces a usable code; `code_sent` in register/login responses;
  mail-transport failures logged (`auth.verification_send_failed`) instead of 500.
  New middleware `EnsureEmailIsVerifiedIfRequired` replaces the `verified` alias and
  honours `BALLPICKER_REQUIRE_EMAIL_VERIFICATION`; `UserResource.email_verified` follows it.
- **`GET /api/config`** (public): `beta_gate`, `email_verification_required`,
  `minimum_age`, `terms_version`, `app_name`. No secrets (tested).
- **Account deletion**: `AccountDeletionService` (one transaction, avatar removed after
  commit), controller returns `{deleted, message}`, logs `account.deleted` /
  `account.delete_failed {user_id, exception}`; friendly 500 on failure.
  Friendlier `email.unique` / `username.unique` messages.
- **Packs** (`PackPlayService`/`PackPlayController`): start on a completed pack → 409
  with attempt + completion; duplicate submit → idempotent 200 with
  `already_completed: true`; different challenge on completed attempt → 409 with
  completion; post-commit rewards guarded (`pack.completion_reward_failed`);
  `completionSummary()` (total/max/average/pct/best guess/trophy/XP) on
  start/attempt/guess payloads.
- **Diagnostics**: "Failed flows (24h)" row counting ERROR/WARNING events by name from
  the events log (account.delete_failed, password.*, auth.verification_*, pack.*).
- Docs: `api-contract.md`, `security-auth.md`, `prizes-and-trophy-room.md` (score-tier
  TODO), `ops-runbook.md` §6, `.env.example`.

## Mobile changes (Expo)

- `apiError.getApiErrorMessage` on every auth screen; no raw server strings.
- `RegisterScreen`: beta field hidden unless `/api/config.beta_gate` (or a 422 asks for
  it); min-age text from config; password length pre-check; "Already have an account".
- `ResetPasswordScreen`: accepts the whole link or the token (`utils/resetLink.ts`),
  fills email from the link, success and expired states with "Request a new link".
- `ForgotPasswordScreen`: only real failures (offline/429/5xx) shown; never enumerates.
- `EmailVerificationScreen`: `codeSent=false` → resend available at once; 401 → back to
  login; specific expired/locked messages from the server.
- `AppNavigator`: `linking` for `ballpicker://reset-password` and
  `https://<WEB_BASE>/reset-password` (custom scheme only — universal links are NOT set
  up); new `PackComplete` route.
- `ProfileScreen`: delete shows the server's message on failure, a clear "Account deleted"
  alert on success, signs out locally and resets to Login; 401 = already gone → sign out.
- Packs: new `PackCompleteScreen` (total, average, best guess, completed count, trophy,
  CTA Packs/Home); `PackDetailScreen` shows "✓ Completed" + "View results", **no Play
  again**; `PackGuessScreen` routes 409/already-completed to the overview, synchronous
  double-submit guard, "Submit final guess" label; `PackResultScreen` → "View pack results".
- Jest: `resetLink.test.ts`, `packCompletion.test.ts` (+12 tests).

## Env / launch settings

| Key | Private beta | Public launch |
|---|---|---|
| `BALLPICKER_BETA_CODE` | set (rotated) | **empty** |
| `BALLPICKER_REQUIRE_EMAIL_VERIFICATION` | `true` | `true` (set `false` only if mail breaks) |
| `FRONTEND_URL` | `https://ballpicker.vanmalderstudio.be` | same (backend serves the reset page) |
| `APP_DEBUG` | `false` | `false` |

After changing `.env`: `php artisan config:cache`. **Migrations: none.**

## Manual test checklist (device)

- [ ] New user can register without beta code (gate off) — no beta field visible
- [ ] Registration code arrives; wrong code → clear error; expired → "request a new one"; Resend works; an older email's code still works
- [ ] Existing user can log in; wrong password → "Invalid credentials."
- [ ] Forgot password → email arrives → link opens the web form → new password works in the app
- [ ] Same link opened twice → friendly "link no longer works" + "Request a new link"
- [ ] Paste the link into the app's reset screen → success → Login
- [ ] Delete account → "Account deleted" → Login screen; old token dead
- [ ] Register again with the same email, then same username → 201
- [ ] Pack: last challenge → result → "View pack results" overview with trophy if configured
- [ ] Completed pack detail shows "View results", no "Play again"
- [ ] `/admin/diagnostics` → "Failed flows (24h)" shows none

## Accepted limitations

- Universal/App Links (`https://…/reset-password` opening the app directly) are not
  configured (needs AASA + assetlinks + entitlements). The web page is the primary
  path; the custom-scheme button is best-effort.
- Score-based pack trophies (Bronze/Silver/Gold) deferred — plan in
  `docs/prizes-and-trophy-room.md`.
- Account deletion success uses a native alert; on Expo web it falls back to a 4 s
  auto-redirect.
- `mobile/.env` locally contains `DB_*` keys; they are not bundled (only `EXPO_PUBLIC_*`
  is), but they do not belong there.
