# v1.9.7 — Auth UX launch-blocker sprint (2026-09-08)

Scope: register errors, confirm password, account-deletion reuse, password
reset 500, specific account errors, optional login 2FA, preferred language,
register keyboard layout, safe auth logging. No redesign, no `migrate:fresh`,
no production data touched.

## Root causes

| Reported issue | What was actually going on |
| --- | --- |
| Register with existing email shows a generic error | Backend already sent a field message, but the body carried no stable code and the app only showed the first `errors.*` text it found. Some TestFlight sessions saw the *generic 500* instead, caused by the events-log write failure fixed in v1.9.6 (`applog.write_failed`), which fired on the register path **after** the account/verification email existed. |
| Same email cannot register again after delete | Deletion already anonymized `email`/`username` to `deleted-{id}`; the failures seen were the same v1.9.6 500 on the *register* call (account created, app showed "something went wrong") plus a stale reset-token row that was never cleared. Now covered by explicit tests and the reset-token cleanup. |
| Password reset link → HTTP 500 | On production the GET page and the invalid-token path answer correctly today (verified with curl). The success path logged `password.reset_completed` via AppLog **after** changing the password — under the v1.9.6 log-file permission incident that threw a 500 while the password *had* changed and the token was consumed, which is exactly why "copying the link" then failed too. Hardened: the whole reset is now one DB transaction, `reset()` never throws, expired vs invalid is distinguished, and every outcome is a friendly page/JSON. |
| "Always need a code when logging in" | Backend forced the login code for **admins** and whenever `BALLPICKER_FORCE_LOGIN_2FA` was on. The tester account is an admin. Login 2FA is now a per-user opt-in (`users.two_factor_enabled`, default false); admins are no longer special-cased on the API (the admin web panel has its own login). |

## Backend changes

- Migration `2026_09_08_000001_add_two_factor_and_language_to_users`
  (additive: `two_factor_enabled` bool default false, `preferred_language`
  varchar(5) default `en`, guarded with `hasColumn`).
- `App\Support\AuthError` — one error shape for known account failures:
  `{ message, code, errors?, codes?, reason? }`. Codes: `email_taken`,
  `username_taken`, `password_mismatch`, `invalid_credentials`,
  `two_factor_required|code_invalid|code_expired|locked|session_invalid`,
  `verification_code_invalid|code_expired|locked|no_code`,
  `reset_token_invalid|expired`, `reset_failed`.
- `RegisterRequest` — `password` `confirmed` (enforced when the field is sent;
  `BALLPICKER_REQUIRE_PASSWORD_CONFIRMATION=true` makes it mandatory),
  `preferred_language` in `nl|en|fr|de|es`, spec'd messages, `codes` map in
  the 422 body, `register.validation_failed {fields, codes}` log.
- `AuthController` — register stores `preferred_language`; login: anonymized
  accounts always get `invalid_credentials`; 2FA only when
  `$user->wantsLoginTwoFactor()` (setting OR force flag); logs
  `login.2fa_required {reason}` / `login.2fa_skipped`.
- `LoginVerificationService` — wrong/expired/locked/session failures carry
  their own code + `reason`; logs `login.2fa_failed {reason}` /
  `login.2fa_completed`.
- `PasswordResetFlow` — transactional, returns an outcome (`completed`,
  `invalid_token`, `expired_token`, `unknown_account`, `failed`), never throws;
  expired detection only when the submitted token matches the stored hash.
  API/web controllers map outcomes to 200 / 422 (+code) / friendly 500 with a
  "Try again" link (link still valid). Events `password_reset.requested|
  completed|failed`.
- `AccountDeletionService` — also deletes `password_reset_tokens` for the old
  address. Events `account.delete.completed` / `account.delete.failed`
  (`account.anonymized` kept as legacy alias).
- `EmailVerificationService` — 422 bodies now carry `code`; events renamed to
  `email_verification.completed|failed|sent|skipped|send_failed`.
- `PreferenceController` + `UpdatePreferencesRequest` — `preferred_language`,
  `two_factor_enabled` (logged as `login.2fa_setting_changed`), payload adds
  `available_languages`. `UserResource` exposes both (self only).
  `GET /api/config` adds `supported_languages`, `default_language`.
- `User` implements `HasLocalePreference` (`preferredLocale()` = stored
  language, safe fallback `en`) — notifications render under it; copy is still
  English (no lang files yet).
- Diagnostics "Failed flows" watches the renamed events + `login.2fa_failed`.
- Config: `ballspot.languages`, `ballspot.default_language`,
  `ballspot.auth.require_password_confirmation`. Env:
  `BALLPICKER_FORCE_LOGIN_2FA`, `BALLPICKER_REQUIRE_PASSWORD_CONFIRMATION`,
  `BALLPICKER_DEFAULT_LANGUAGE` (all optional, defaults shown in .env.example).

## Mobile changes

- `src/utils/authErrors.ts` (+tests) — code → friendly copy, field mapping,
  `classifyResetError`, `validatePasswordPair`.
- `src/utils/language.ts` (+tests) — supported list/labels, device-locale
  resolution via `Intl` (try/catch, fallback English).
- `src/components/LanguagePicker.tsx` — chip row, no native dependency.
- `RegisterScreen` — Confirm password field (tab order name → username → email
  → password → confirm → [beta] → submit), Preferred language picker, per-field
  friendly errors from `codes`, sends `password_confirmation` +
  `preferred_language`. Keyboard: unchanged `Screen` (iOS
  `automaticallyAdjustKeyboardInsets` + bottom padding; Android resize).
- `LoginScreen` / `LoginVerificationScreen` / `ResetPasswordScreen` — code
  aware copy; expired vs invalid reset link screens; confirm-password error on
  its own field.
- `ProfileScreen` — "Account & security" section: language picker + Two-factor
  login switch (PATCH `/me/preferences`, local user state updated).
- `types/auth.ts`, `api/authApi.ts`, `api/preferencesApi.ts`, `api/configApi.ts`
  — new fields. `app.json` iOS buildNumber 23 → 24.

## Tests

Backend (new): `RegisterValidationUxTest` (9), `OptionalTwoFactorLoginTest`
(11), `AccountDeletionReuseTest` (7), `PasswordResetHardeningTest` (13),
`LanguagePreferenceTest` (9). Updated: `EmailVerificationTest` (admin follows
the per-user setting), `EmailTwoFactorLoginTest`, `EmailVerificationHardeningTest`,
event-name renames across existing tests. Suite: **775 passed, 2 skipped**.

Mobile (new): `authErrors.test.ts`, `language.test.ts`. Jest **72 passed**,
`tsc --noEmit` clean, `expo export --platform web` OK.

## Deploy

1. `php artisan migrate` (additive, no `migrate:fresh`), `config:cache` if used.
2. Optional `.env`: the three new flags default sensibly when absent.
3. EAS build required (register/profile/reset screens changed). Backend is
   backward compatible with the current store build (confirmation optional,
   language defaults to `en`, old clients still read `errors.*` text).

## Accepted limitations

- Admin API logins are no longer forced through 2FA; admins should enable it in
  Profile (one tap). The admin web panel login is unchanged.
- `BALLPICKER_REQUIRE_PASSWORD_CONFIRMATION` stays `false` until the v1.9.7
  build is the store minimum; the new app always sends the field.
- Language only changes the notification locale; all copy is still English.
- Device locale via `Intl` — on a runtime without `Intl` the picker preselects
  English (user can change it).
- Universal links still not configured; the web reset page + `ballpicker://`
  deep link remain the paths.
