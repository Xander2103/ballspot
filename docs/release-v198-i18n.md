# v1.9.8 — Localization (nl / en / fr / de / es)

The stored `users.preferred_language` (v1.9.7) now drives real copy: the
mobile UI, mobile validation/error messages, every transactional email, the
web password-reset pages, directly-returned API messages and the server-sent
daily reminder push.

## Approach

**Mobile (Expo / React Native)** — no new dependency. A small typed i18n core
lives in `mobile/src/i18n/`:

- `core.ts` — pure TypeScript store: `translate(key, params, locale)`,
  `setLocale`, `subscribe`, `resolveLocale`. Lookup order: active language →
  English → the key itself (never throws, never renders empty). `{{name}}`
  interpolation; `key_one` / `key_other` plurals picked from `params.count`.
- `index.ts` — React binding: `useI18n()` (re-renders every mounted screen on
  language change via `useSyncExternalStore`), plain `t()` for non-React code,
  `initLocale()` for startup, persistence of the device choice in
  `localFlags` (`ballpicker_language`).
- `locales/en/*.ts` — English, split per namespace (`common`, `nav`,
  `errors`, `auth`, `home`, `daily`, `game`, `packs`, `tournaments`,
  `friends`, `profile`, `notifications`). `Translations = typeof en`, so
  `nl.ts` / `fr.ts` / `de.ts` / `es.ts` are type-checked for completeness by
  `tsc --noEmit`.

Startup fallback order (spec): account `preferred_language` → language chosen
on the register screen / last stored device choice → device locale if
supported → `BALLPICKER_DEFAULT_LANGUAGE` (from `GET /api/config`, cached) →
`en`. Changing the language on Profile (or tapping a chip on Register)
switches the whole app immediately; the Profile change is optimistic and
reverts if the PATCH fails.

The API client sends `Accept-Language: <active>` so backend validation text
comes back localized. Backend error **codes** stay stable; the app translates
`errors.auth.<code>` client-side (`src/utils/authErrors.ts`).

**Backend (Laravel)** — standard `lang/<locale>/` files:

- `auth_codes.php` — sentence per `AuthError` code (`AuthError::message()`
  now reads it; the English constant array is the last-resort fallback).
- `emails.php` — verification code, login 2FA code, password reset.
- `web.php` — forgot / reset / result pages + public layout nav.
- `messages.php` — every `'message' => …` returned directly by the API
  (auth, account, preferences, daily, friends, tournaments, packs), the 429
  body, and the daily reminder push copy.
- `validation.php` — attribute names (+ the common rules in nl/fr/de/es).

`App\Http\Middleware\SetLocale` (API prepend + web append) resolves the
request language through `App\Support\Locale::forRequest()`: signed-in user's
`preferred_language` → `preferred_language` body input (register) → `?lang=`
/ `lang` form field → `Accept-Language` → `BALLPICKER_DEFAULT_LANGUAGE` → en.
Unsupported values are ignored, never an error.

Emails are NOT affected by the request language: `User` implements
`HasLocalePreference`, so every notification renders under the recipient's
own `preferred_language` — register uses the language chosen in the form
(stored before the mail is sent), forgot-password uses the account's stored
language even when nobody is logged in. The reset link now carries
`&lang=<locale>` so the web fallback page matches the email.

## Not translated (by design)

- User-generated / server content: usernames, tournament names, pack names
  and descriptions, challenge titles, category and sport names, badge names
  and descriptions, rank names, XP-event reasons, admin announcements.
- The admin panel (English), including admin-authored push announcements.
- Legal pages (privacy / terms / support) remain English.
- Date/number formatting on the mobile side still uses fixed `en-GB`/`en-US`
  locales (copy only; a follow-up can switch to the active locale).
- There is no account-deletion confirmation email in the product, so none was
  added.

## Tests

Backend `tests/Feature/LocalizationTest.php`: translation files complete for
all five languages with placeholders intact; verification / login-2FA /
password-reset emails per preferred language (through the real endpoints);
register mail uses the language chosen in the form; reset mail ignores the
requester's language; unsupported stored language → English; reset / forgot /
result web pages in all five languages; `?lang=` beats `Accept-Language`;
unsupported `lang` → English; API codes stable while messages translate;
signed-in users get their own language regardless of the header; 429 body
translated; `Locale` helper edge cases.

Mobile `src/i18n/__tests__/core.test.ts` (lookup in every language, missing
key fallback, interpolation, plurals, placeholder parity across languages,
store notifications/persistence, fallback order) and
`src/i18n/__tests__/screens.test.ts` (every `t('…')` key on launch-critical
screens exists in English, no leftover English JSX text, register sends the
chosen language, profile change switches the locale, client sends
`Accept-Language`). `authErrors` / `apiError` / `verificationFlow` tests
updated to the translated copy.

## Deploy

- Backend deploy required (new middleware, lang files, notifications).
- No migration (`preferred_language` shipped in v1.9.7).
- New EAS build required (all screens changed).
- No new env vars; `BALLPICKER_DEFAULT_LANGUAGE` already exists.
