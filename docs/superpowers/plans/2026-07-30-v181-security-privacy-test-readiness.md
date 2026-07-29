# BallPicker v1.8.1 — Security, Privacy & Test Readiness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Harden BallPicker for external testing: centralized rate limiting, capped/paginated heavy endpoints + missing indexes, complete account deletion, data export, security headers + CORS, consent UX, beta-code gate, and privacy documentation.

**Architecture:** All abuse protection is centralized in named `RateLimiter` definitions (`AppServiceProvider`) applied via `throttle:` middleware, with one global `throttle:api` fallback. A single `SecurityHeaders` middleware covers browser-served pages. Account deletion is completed in `AccountController` (no new service). Mobile gains a `signOut()` helper, consent checkbox, beta-code field, and a 429-aware API client.

**Tech Stack:** Laravel 12 (Sanctum, SQLite dev DB), Expo SDK 56 / React Native, TypeScript.

## Global Constraints

- No gambling / betting / real prizes / real-money rewards / payments / ads / chat / realtime; all rewards virtual only.
- No tracking/analytics, no third-party marketing SDKs, no new personal data collection.
- Never run `php artisan migrate:fresh --seed`; never delete storage files; never reset git; never overwrite uploaded challenge images.
- Must not break: Daily Challenge, Tournament mode, Pack Play Mode, Profile, XP, Rank, Badges, Trophy Room, Notifications, Admin CMS.
- Backend: `cd backend`, run `php artisan test`. Mobile: `cd mobile`, run `npx tsc --noEmit`; Expo docs are v56 (`https://docs.expo.dev/versions/v56.0.0/`).
- Commit message for the final commit: `chore: harden security privacy and test readiness`.

## Audit baseline (facts the tasks below rely on)

- Only 3 throttled routes exist (`login`, `login-verify`, `login-resend` in `routes/api.php:28-30`); no global `throttle:api`; `POST /admin/login` (`routes/web.php:18`) is unthrottled.
- `weeklyLeaderboard` (`DailyChallengeController.php:223-258`) returns every player; `stats` endpoints load full guess history via `->get()`.
- Account deletion (`AccountController.php`) anonymizes but leaves avatar file, push tokens, notification settings, and verification-code rows.
- Codes/tokens are already hashed (bcrypt / Sanctum SHA-256); push tokens are plaintext by design, `$hidden` on the model, never echoed by any endpoint. Logging is clean (one `Log::warning` without sensitive data).
- CORS uses framework default wildcard (no `config/cors.php`); no security headers middleware; `SESSION_SECURE_COOKIE` unset; `sanctum.expiration` is null.
- SQLite does NOT auto-index FK columns: `guesses(user_id)`, `daily_challenge_guesses(user_id)`, `league_rounds(league_id)`, `league_members(user_id)` are unindexed.
- Mobile: token in SecureStore (native) / sessionStorage (web); no Terms/Privacy consent at register; no 429 handling; logout/delete leaves theme + notif flag + scheduled reminders + registered push token behind; zero console.log; zero analytics SDKs.
- Mobile leaderboard screen reads "Your position" from `meta` (full-standings based) and hides the jump button when the user is not in `data` — capping `data` is safe.

---

### Task 1: Centralized rate limiting + clean 429 JSON

**Files:**
- Modify: `backend/app/Providers/AppServiceProvider.php`
- Modify: `backend/bootstrap/app.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/routes/web.php`
- Modify: `backend/app/Http/Controllers/Admin/AuthController.php` (no change needed if throttle is route-level)
- Test: `backend/tests/Feature/RateLimitTest.php`

**Interfaces:**
- Produces named limiters: `api`, `register`, `forgot-password`, `reset-password`, `email-verify`, `email-resend`, `gameplay`, `push-tokens`, `export`, `admin-login`, `admin-send`. Existing `login`, `login-verify`, `login-resend` unchanged.
- Produces JSON 429 shape: `{ "message": "Too many requests. Please try again in N seconds.", "retry_after": N }`.

- [ ] **Step 1: Write failing tests** in `tests/Feature/RateLimitTest.php` covering: register 429 after 5 rapid attempts from one IP; forgot-password 429 after 3 per email; admin login 429 after 5; daily guess 429 after 30; 429 body contains `retry_after`; a normal single register attempt still returns 201/422 (not 429). Use `RateLimiter::clear` or unique IPs via `serverVariables` where needed.
- [ ] **Step 2: Run tests, confirm failure** (`php artisan test --filter=RateLimitTest`).
- [ ] **Step 3: Implement limiters** in `AppServiceProvider::configureRateLimiters()`:

```php
// Global API fallback — generous, keyed by user id (or IP pre-auth).
RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));

RateLimiter::for('register', fn (Request $r) => [
    Limit::perMinute(5)->by('reg-m|' . $r->ip()),
    Limit::perHour(20)->by('reg-h|' . $r->ip()),
]);
RateLimiter::for('forgot-password', function (Request $r) {
    $email = strtolower((string) $r->input('email'));
    return [
        Limit::perMinute(3)->by('fp-e|' . $email . '|' . $r->ip()),
        Limit::perMinute(10)->by('fp-ip|' . $r->ip()),
    ];
});
RateLimiter::for('reset-password', fn (Request $r) => Limit::perMinute(5)->by('rp|' . $r->ip()));
RateLimiter::for('email-verify', fn (Request $r) => Limit::perMinute(10)->by('ev|' . ($r->user()?->id ?: $r->ip())));
RateLimiter::for('email-resend', fn (Request $r) => Limit::perMinute(3)->by('er|' . ($r->user()?->id ?: $r->ip())));
RateLimiter::for('gameplay', fn (Request $r) => Limit::perMinute(30)->by('play|' . ($r->user()?->id ?: $r->ip())));
RateLimiter::for('push-tokens', fn (Request $r) => Limit::perMinute(10)->by('push|' . ($r->user()?->id ?: $r->ip())));
RateLimiter::for('export', fn (Request $r) => Limit::perHour(5)->by('export|' . ($r->user()?->id ?: $r->ip())));
RateLimiter::for('admin-login', fn (Request $r) => Limit::perMinute(5)->by('admin-login|' . $r->ip()));
RateLimiter::for('admin-send', fn (Request $r) => Limit::perMinute(10)->by('admin-send|' . ($r->user()?->id ?: $r->ip())));
```

- [ ] **Step 4: Enable global throttle + 429 rendering** in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias(['admin' => \App\Http\Middleware\EnsureIsAdmin::class]);
    $middleware->throttleApi(); // applies throttle:api to routes/api.php
})
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->render(function (\Illuminate\Http\Exceptions\ThrottleRequestsException $e, \Illuminate\Http\Request $request) {
        if ($request->expectsJson() || $request->is('api/*')) {
            $retryAfter = (int) ($e->getHeaders()['Retry-After'] ?? 60);
            return response()->json([
                'message'     => "Too many requests. Please try again in {$retryAfter} seconds.",
                'retry_after' => $retryAfter,
            ], 429, $e->getHeaders());
        }
    });
})
```

- [ ] **Step 5: Attach route throttles** in `routes/api.php` (`register`, `forgot-password`, `reset-password`, `email/verify`, `email/verification-notification`, all 3 guess endpoints get `throttle:gameplay`, `POST /me/push-tokens` gets `throttle:push-tokens`) and `routes/web.php` (`POST /admin/login` → `throttle:admin-login`; admin notification send action → `throttle:admin-send`).
- [ ] **Step 6: Run RateLimitTest until green, then run the full backend suite** (existing tests hammer endpoints within single tests; if any legit test trips a limiter, call `RateLimiter::clear()`/use higher per-user keys — fix tests only by resetting the limiter in `setUp`, never by weakening limits).
- [ ] **Step 7: Commit** `feat: add centralized rate limiting with clean 429 json`.

### Task 2: Database protection — caps, aggregates, admin pagination

**Files:**
- Modify: `backend/app/Http/Controllers/Api/DailyChallengeController.php` (weeklyLeaderboard, stats)
- Modify: `backend/app/Http/Controllers/Api/ProfileController.php` (stats, tournamentFinishes, competitionFinishes, packCompletions)
- Modify: `backend/app/Http/Controllers/Api/LeaderboardController.php`
- Modify: `backend/app/Http/Controllers/Admin/ChallengePackController.php` + `backend/resources/views/admin/packs/index.blade.php`
- Test: `backend/tests/Feature/EndpointCapsTest.php`

**Interfaces:**
- Produces: `weeklyLeaderboard` returns at most 100 entries in `data`; `meta` still computed from ALL standings (total_players, current_user_rank unchanged for users beyond 100).
- Self-scoped Trophy Room lists capped at 100 rows (newest first — ordering already exists).

- [ ] **Step 1: Write failing tests** in `EndpointCapsTest.php`: (a) weekly leaderboard with 105 players returns 100 entries but `meta.total_players == 105` and a user ranked 105 still gets `meta.current_user_rank == 105`; (b) `/api/me/xp-events?limit=9999` returns ≤ 50 (already passing — regression guard).
- [ ] **Step 2: Confirm (a) fails.**
- [ ] **Step 3: Implement caps:**
  - `weeklyLeaderboard`: `'data' => $entries->take(self::LEADERBOARD_MAX_ENTRIES)->values(),` with `private const LEADERBOARD_MAX_ENTRIES = 100;` — `buildLeaderboardMeta($entries, ...)` keeps receiving the FULL collection.
  - `DailyChallengeController::stats` + `ProfileController::stats`: replace `DailyChallengeGuess::...->get()` with aggregate queries (`count()`, `avg('score')`, `max('score')`) — one grouped `selectRaw('COUNT(*) as c, AVG(score) as a, MAX(score) as m')->first()` per user.
  - `ProfileController` finishes/completions: append `->limit(100)` before `->get()`.
  - `LeaderboardController::index`: append `->limit(100)` (tournaments are ≤8 players; pure safety cap).
  - Admin packs index: `->paginate(25)` + `{{ $packs->links() }}` in the Blade view (check the view's variable name first).
- [ ] **Step 4: Run EndpointCapsTest + full suite green.**
- [ ] **Step 5: Commit** `feat: cap heavy endpoints and use sql aggregates for stats`.

### Task 3: Missing indexes migration

**Files:**
- Create: `backend/database/migrations/2026_07_30_000001_add_missing_query_indexes.php`

- [ ] **Step 1: Write migration** adding: `guesses` → `index('user_id')`; `daily_challenge_guesses` → `index('user_id')`; `league_rounds` → `index(['league_id', 'status'])`; `league_members` → `index('user_id')`. `down()` drops them.
- [ ] **Step 2: Run `php artisan migrate`** (NOT fresh) and full test suite (RefreshDatabase covers migration validity).
- [ ] **Step 3: Commit** `perf: add missing indexes for leaderboard and stats queries`.

### Task 4: Security headers + CORS config

**Files:**
- Create: `backend/app/Http/Middleware/SecurityHeaders.php`
- Create: `backend/config/cors.php`
- Modify: `backend/bootstrap/app.php` (append middleware)
- Modify: `backend/.env.example`
- Test: `backend/tests/Feature/SecurityHeadersTest.php`

- [ ] **Step 1: Failing tests**: GET `/api/health` and GET `/privacy` responses include `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: camera=(), geolocation=(), microphone=()`.
- [ ] **Step 2: Implement middleware** (set the four headers on `$next($request)` response) and append globally: `$middleware->append(\App\Http\Middleware\SecurityHeaders::class);`. NO CSP header (admin uses inline styles) — document recommended CSP instead.
- [ ] **Step 3: Publish `config/cors.php`** with framework defaults except `'allowed_origins' => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', '*')))`. Add `CORS_ALLOWED_ORIGINS=*` + comment to `.env.example` (set to real origins in production).
- [ ] **Step 4: Suite green. Commit** `feat: add security headers middleware and configurable cors`.

### Task 5: Complete account deletion

**Files:**
- Modify: `backend/app/Http/Controllers/Api/AccountController.php`
- Test: `backend/tests/Feature/AccountDeletionTest.php` (extend)

- [ ] **Step 1: Failing tests**: after `DELETE /api/account` — avatar file removed from `public` disk and `avatar_path` null (use `Storage::fake('public')`); `push_tokens`, `notification_settings`, `email_verification_codes`, `login_verification_codes` rows for the user are gone; guesses/xp/finish rows retained.
- [ ] **Step 2: Implement** in `AccountController::delete` (before the anonymizing `update`):

```php
if ($user->avatar_path) {
    Storage::disk('public')->delete($user->avatar_path);
}
$user->pushTokens()->delete();          // or PushToken::where('user_id', $id)->delete()
NotificationSetting::where('user_id', $id)->delete();
EmailVerificationCode::where('user_id', $id)->delete();
LoginVerificationCode::where('user_id', $id)->delete();
// include 'avatar_path' => null in the anonymize update()
```

Check actual model/relationship names before writing (`app/Models/`).
- [ ] **Step 3: Suite green. Commit** `fix: fully clean up avatar, push tokens and settings on account deletion`.

### Task 6: Push-token deregistration, hidden fields, token TTL config, stale-token pruning

**Files:**
- Modify: `backend/app/Http/Controllers/Api/PushTokenController.php` (add `destroy`)
- Modify: `backend/routes/api.php` (`DELETE /me/push-tokens`)
- Modify: `backend/app/Models/User.php` (`$hidden` += `is_admin`)
- Modify: `backend/config/sanctum.php` (`'expiration' => env('SANCTUM_TOKEN_EXPIRATION_MINUTES')`)
- Modify: `backend/app/Console/Commands/CleanupLoginCodes.php` (prune push tokens `last_seen_at` > 90 days)
- Test: `backend/tests/Feature/PushTokenPrivacyTest.php`

**Interfaces:**
- Produces: `DELETE /api/me/push-tokens` body `{ token?: string }` — with `token`: deletes that row only if owned by the caller; without: deletes all caller's rows. Returns `{ "status": "removed" }`.

- [ ] **Step 1: Failing tests**: register-token response contains no `token` key; DELETE with token removes only own matching row (another user's identical-value row untouched — token is unique so use distinct values; assert scoping via user_id); DELETE without body removes all own rows; cleanup command deletes a token row with `last_seen_at` 91 days old, keeps 89 days old; `User` JSON never contains `is_admin`.
- [ ] **Step 2: Implement.** Sanctum expiration default stays null (no behavior change) — production TTL documented.
- [ ] **Step 3: Suite green. Commit** `feat: add push token deregistration and stale token pruning`.

### Task 7: Data export MVP

**Files:**
- Create: `backend/app/Http/Controllers/Api/DataExportController.php`
- Modify: `backend/routes/api.php` (`GET /me/export` inside `auth:sanctum` but NOT behind `verified` — an unverified user has GDPR rights too; throttle `export`)
- Test: `backend/tests/Feature/DataExportTest.php`

**Interfaces:**
- Produces JSON: `{ exported_at, account: {id,name,username,email,created_at,email_verified,preferred_sport,selected_theme,avatar_url}, notification_settings, push_tokens: [{platform, created_at, last_seen_at}], xp_events: [...], badges: [...], daily_guesses: [{date, score}...], tournament_guesses_summary: {count,total_score,average_score}, tournament_finishes, competition_finishes, pack_completions }`. NO password hash, NO reset/verification tokens, NO push token values.

- [ ] **Step 1: Failing tests**: 401 unauthenticated; 200 authenticated with account.email present; raw JSON string does NOT contain the user's bcrypt hash, any personal-access-token value, or a registered push token's value; xp events and daily guesses included.
- [ ] **Step 2: Implement** — reuse existing query shapes from `ProfileController`; select explicit columns; cap each list at 1000 rows newest-first (`->limit(1000)`).
- [ ] **Step 3: Suite green. Commit** `feat: add gdpr data export endpoint`.

### Task 8: Beta-code registration gate

**Files:**
- Modify: `backend/config/ballspot.php` (`'beta_code' => env('BALLPICKER_BETA_CODE')` — top-level key)
- Modify: `backend/app/Http/Requests/RegisterRequest.php`
- Modify: `backend/.env.example` (`BALLPICKER_BETA_CODE=` + comment)
- Test: `backend/tests/Feature/BetaCodeTest.php`

- [ ] **Step 1: Failing tests**: with `config(['ballspot.beta_code' => 'FRIENDS2026'])` — register without code → 422; wrong code → 422 (generic message, does not echo the expected code); correct code (case-insensitive) → 201. With config null → register works without the field.
- [ ] **Step 2: Implement** in `RegisterRequest::rules()` — when `config('ballspot.beta_code')` is non-empty add `'beta_code' => ['required', 'string', function ($attr, $value, $fail) { if (!hash_equals(strtolower(config('ballspot.beta_code')), strtolower((string) $value))) $fail('Invalid beta code.'); }]`.
- [ ] **Step 3: Suite green. Commit** `feat: add optional beta code gate for registration`.

### Task 9: Mobile — consent UX, beta code, 429 handling, signOut cleanup

**Files:**
- Modify: `mobile/src/api/client.ts` (429 branch reading `Retry-After`)
- Modify: `mobile/src/screens/RegisterScreen.tsx` (consent checkbox + Terms/Privacy links + optional beta-code input)
- Create: `mobile/src/app/signOut.ts`
- Modify: `mobile/src/screens/ProfileScreen.tsx` (use signOut in logout + delete)
- Modify: `mobile/src/api/notificationsApi.ts` (`unregisterPushToken`)
- Modify: `mobile/src/types/auth.ts` or equivalent (RegisterPayload gains optional `beta_code`)

**Interfaces:**
- `signOut(): Promise<void>` — best-effort: unregister push token, cancel scheduled local notifications, clear `notif_prompt_seen` flag and stored theme, remove auth token. Never throws.
- `client.ts` throws `{ status: 429, message: 'Too many attempts. Try again in N seconds.', retry_after: N }` on 429.

- [ ] **Step 1: 429 branch** in `client.ts` before generic error handling: read `response.headers.get('Retry-After')` (fallback to body `retry_after`, then 60).
- [ ] **Step 2: RegisterScreen** — add required checkbox with copy exactly: “I agree to the Terms and have read the Privacy Policy.” where “Terms” and “Privacy Policy” open `${WEB_BASE}/terms` / `${WEB_BASE}/privacy` via `Linking`; Create Account disabled until checked; add optional “Beta code (if you have one)” input sent as `beta_code` when non-empty. Reuse the `WEB_BASE` derivation from `ProfileScreen.tsx:23-24`.
- [ ] **Step 3: signOut helper** + wire into ProfileScreen logout and delete-account handlers (replacing the inline token-removal).
- [ ] **Step 4: `npx tsc --noEmit` green; `npx expo export --platform web` green.**
- [ ] **Step 5: Commit** `feat: add consent ux, beta code field, 429 handling and signout cleanup`.

### Task 10: Documentation + production config

**Files:**
- Create: `docs/privacy-data-inventory.md`, `docs/privacy-policy-draft.md`, `docs/test-readiness-checklist.md`, `docs/security-hardening.md`
- Modify: `docs/api-contract.md` (new/changed endpoints: export, push-token delete, rate limits, caps, beta code), `docs/database-schema.md` (new indexes), `docs/test-report.md`, `docs/store-readiness.md`, `README.md`, `backend/.env.example`

- [ ] **Step 1: privacy-data-inventory.md** — table per data item (email, name/username, password hash, avatar, guesses/scores, XP events, badges/trophies, notification settings + timezone, push tokens, login-code IP/user-agent, sessions): purpose, suggested legal basis, retention, access, storage location.
- [ ] **Step 2: privacy-policy-draft.md** — plain language, clearly marked “DEVELOPER DRAFT — NEEDS LEGAL REVIEW BEFORE PUBLIC LAUNCH”, covering all items from the spec (operator, contact placeholder, data collected/why, no sale/ads/payments/gambling/chat/tracking, retention, deletion+export, GDPR rights, minors note, changes).
- [ ] **Step 3: test-readiness-checklist.md** — env setup, HTTPS/domain, policy/terms links, support email, test-user deletion, rate limits, backups (`backups/` dir exists), content rights, admin access, DB backup before testing, logging, store build notes, notification limits, known risks.
- [ ] **Step 4: security-hardening.md** — rate-limit table, 429 shape, headers added, recommended production CSP, production env checklist (APP_ENV=production, APP_DEBUG=false, LOG_LEVEL=warning, HTTPS, SESSION_SECURE_COOKIE=true, strong APP_KEY, real mail, storage link, CORS_ALLOWED_ORIGINS, SANCTUM_TOKEN_EXPIRATION_MINUTES recommendation, queue/scheduler), admin-audit-log future recommendation, sanctum TTL note, push-token retention.
- [ ] **Step 5: .env.example** — `APP_DEBUG=false` (comment: set true locally), `LOG_LEVEL=warning` (comment: debug locally), add `SESSION_SECURE_COOKIE=`, `CORS_ALLOWED_ORIGINS=*`, `SANCTUM_TOKEN_EXPIRATION_MINUTES=`, `BALLPICKER_BETA_CODE=`, `FRONTEND_URL` (verify it exists already).
- [ ] **Step 6: Commit** `docs: add privacy inventory, policy draft and test readiness checklist`.

### Task 11: Final quality pass

- [ ] **Step 1:** `cd backend && php artisan test` — all green.
- [ ] **Step 2:** `cd mobile && npx tsc --noEmit` — clean.
- [ ] **Step 3:** `npx expo export --platform web` — succeeds.
- [ ] **Step 4:** `git status` — only intended files changed.
- [ ] **Step 5:** Final commit if anything uncommitted: `chore: harden security privacy and test readiness`.

## Self-review notes

- Spec coverage: Tasks 1→(C), 2-3→(Task 4 spec), 4→(H), 5→(I.16), 6→(E.8, I), 7→(I.17), 8→(J.19), 9→(D.7, E.9, L), 10→(D.5-6, F.10, G.13, J.18, M.22), 11→(M.23). Part B audit already performed (findings baked into tasks). Part F.11: audit found logging already clean — documented in security-hardening.md instead of code changes. Part G.12: admin upload tightening folded into Task 1/10? — NO: add explicit `mimes:jpeg,jpg,png,webp` to admin upload rules as part of Task 2 Step 3 companion — moved to its own bullet: tighten `Admin\ChallengeController` lines 58-59/109-110 and `Admin\ChallengePackController` line 140 to `['file','image','mimes:jpeg,jpg,png,webp','max:5120']` (SVG already blocked by `image` rule; this also blocks gif/bmp). Include in Task 2 with a test only if an existing admin upload test covers mime rejection; otherwise assert one gif-rejection case in `EndpointCapsTest` companion or `AdminChallengeWorkflowTest` extension.
- Type consistency: `retry_after` int used in both backend 429 JSON and mobile client. `DELETE /me/push-tokens` shape matches mobile `unregisterPushToken`.
- Placeholder scan: model/relationship names in Task 5 flagged for verification at implementation time (explicit instruction, not a TBD).
