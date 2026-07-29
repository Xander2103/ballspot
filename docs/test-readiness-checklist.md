# BallPicker — External Test Readiness Checklist

**Sprint:** v1.8.1. Work through this top-to-bottom before inviting external testers.

## Environment

- [ ] Production server provisioned; `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`.
- [ ] Strong `APP_KEY` generated (`php artisan key:generate`) and backed up.
- [ ] **HTTPS only** — valid certificate on the API/admin domain; HTTP redirects to HTTPS.
- [ ] `SESSION_SECURE_COOKIE=true`.
- [ ] `CORS_ALLOWED_ORIGINS` set to the real web origin(s) — never `*` in production.
- [ ] `FRONTEND_URL` points at the deployed app (password-reset links depend on it).
- [ ] Real mail provider configured (`MAIL_MAILER=smtp`/API) — registration REQUIRES deliverable email.
- [ ] `php artisan storage:link` run (challenge images/avatars must be served).
- [ ] Queue + scheduler configured if used; cron for `ballspot:schedule-daily-challenges`, `ballspot:close-competition`, `ballspot:cleanup-login-codes` (also prunes stale push tokens).
- [ ] `SANCTUM_TOKEN_EXPIRATION_MINUTES` set (recommended: 129600 = 90 days).
- [ ] Mobile build: `EXPO_PUBLIC_API_BASE_URL` + `EXPO_PUBLIC_WEB_URL` point at the HTTPS domain (never leave the localhost fallback in a distributed build).

## Legal / privacy

- [ ] Privacy policy reviewed by a lawyer, published at `/privacy` (draft: docs/privacy-policy-draft.md).
- [ ] Terms of service published at `/terms`.
- [ ] Support contact live at `/support` + `BALLSPOT_SUPPORT_EMAIL` monitored.
- [ ] Register screen consent checkbox links resolve to the real `/terms` and `/privacy`.
- [ ] Data inventory (docs/privacy-data-inventory.md) still matches reality.

## Content rights

- [ ] Every uploaded challenge image is either owned, licensed, or otherwise cleared — **no copyrighted sports photos without rights**.
- [ ] Content follows docs/challenge-content-guide.md and docs/content-safety.md.

## Abuse protection

- [ ] Rate limits active (see docs/security-hardening.md for the table) — spot-check: 6 rapid `POST /api/register` from one IP returns a 429 JSON.
- [ ] Beta gate: `BALLPICKER_BETA_CODE` set for closed testing (share the code only with invited testers); clear it to open registration.
- [ ] Admin login throttled (verify a 429 after 5 wrong attempts).

## Data safety

- [ ] **Full database backup taken immediately before testers join**, and a restore has been rehearsed once.
- [ ] Recurring backups scheduled (the repo's `backups/` convention or managed-DB snapshots).
- [ ] `storage/app/public` (challenge images, avatars) included in backups.
- [ ] Test-user cleanup path agreed: testers delete via Profile → Delete account; verify one deletion end-to-end (tokens dead, avatar gone, pushes stop).

## Access control

- [ ] Admin accounts limited to the people who need them; strong unique passwords (admins always get login 2FA).
- [ ] No shared admin credentials.
- [ ] Server/SSH/DB access limited and key-based.

## Monitoring

- [ ] Server logs shipped/rotated; `LOG_LEVEL=warning` confirmed.
- [ ] Uptime check on `GET /api/health`.
- [ ] Someone owns checking logs during the test window.

## Store / build notes

- [ ] EAS `projectId` configured — **without it, push notifications silently never register** (current known state).
- [ ] docs/store-readiness.md walked through; `ballspot:store-readiness-check` passes.
- [ ] App version bumped; release notes mention it's a test build.

## Known limitations to tell testers

- Push announcements require a real EAS build (Expo Go / missing projectId = local reminders only).
- Web build stores the session token in `sessionStorage` (cleared when the tab closes) — see docs/security-hardening.md.
- Deleting your account keeps anonymized scores on leaderboards ("Deleted User").
- Rate limits are deliberately strict; hammering endpoints will produce "try again in N seconds" messages.

## Known risks (accepted for closed beta)

- No admin audit log yet (recommended next — see docs/security-hardening.md).
- No account lock-out UI (limits only), no per-user 2FA toggle.
- No CSP header yet (documented recommendation only).
- Delete account requires no password re-entry (a stolen live token could delete the account).
