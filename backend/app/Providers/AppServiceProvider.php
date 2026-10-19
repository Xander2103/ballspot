<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Same construction as the framework singleton (CacheServiceProvider),
        // but failing open when the store breaks — see the class.
        $this->app->extend(\Illuminate\Cache\RateLimiter::class, function ($limiter, $app) {
            return new \App\Support\ResilientRateLimiter(
                $app->make('cache')->driver($app['config']->get('cache.limiter'))
            );
        });

        // BallPicker Clubs: one membership lookup per (club, user) per request.
        $this->app->scoped(\App\Services\Clubs\ClubAccessContext::class);
        // Normal-track pack access: public packs vs club packs, by pack.club_id.
        $this->app->bind(\App\Contracts\PackAccessGate::class, \App\Services\Clubs\PackAccessResolver::class);
    }

    public function boot(): void
    {
        // Config is loaded here (cached or not) — the only safe place to read
        // TRUSTED_PROXIES. See config/ballspot.php 'trusted_proxies'.
        \App\Support\TrustedProxies::apply();

        $this->configureRateLimiters();
        $this->configureClubAuthorization();
    }

    /**
     * BallPicker Clubs authorization (contracts §3.5). Club abilities live in
     * App\Policies\Club*Policy and are decided by ACTIVE membership role.
     *
     * Platform admins (D21: every is_admin user) are granted everything only
     * for `admin.*` abilities or while the request is in the admin area —
     * never on the member API or the club portal, where an admin without a
     * membership is an outsider (404) like anyone else. Abilities stay behind
     * policies so narrower admin roles can be added later.
     */
    private function configureClubAuthorization(): void
    {
        Gate::policy(\App\Models\Club::class, \App\Policies\ClubPolicy::class);
        Gate::policy(\App\Models\ClubCompetition::class, \App\Policies\ClubCompetitionPolicy::class);
        Gate::policy(\App\Models\ClubInviteCode::class, \App\Policies\ClubInviteCodePolicy::class);
        Gate::policy(\App\Models\ClubPhotoSubmission::class, \App\Policies\ClubPhotoSubmissionPolicy::class);

        // Dotted abilities cannot be policy method names, so they are defined
        // explicitly (Gate checks a model's policy first, then these).
        Gate::define('admin.manage', [\App\Policies\ClubPolicy::class, 'adminManage']);
        Gate::define('portal.access', [\App\Policies\ClubPolicy::class, 'portalAccess']);

        Gate::before(function (\App\Models\User $user, string $ability) {
            if (!$user->is_admin) {
                return null;
            }

            if (str_starts_with($ability, 'admin.')) {
                return true;
            }

            $request = $this->app->bound('request') ? $this->app['request'] : null;

            return $request instanceof Request && $request->is('admin', 'admin/*') ? true : null;
        });
    }

    /**
     * Named rate limiters. Keys combine the relevant identifier with the
     * client IP so one abuser cannot lock out everyone, while still bounding
     * brute-force / email-spam / write floods. Documented in
     * docs/security-hardening.md — keep that table in sync.
     */
    private function configureRateLimiters(): void
    {
        // Global API fallback (applied to every routes/api.php route via
        // throttleApi()). Generous enough for read-heavy screens
        // (leaderboards, packs, ranks, stats) but a hard cap on scraping.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // Account creation: strict per IP — every register sends a
        // verification email (cooldown-bypassing), so this is the mail gate.
        // v1.9.13: plus per normalized email and per username (attempts,
        // IP-independent) so a script cannot hammer one identity from many
        // addresses. Accounts actually CREATED per IP (5/hour, 10/day) are
        // counted separately in App\Support\AuthAbuseGuard.
        RateLimiter::for('register', function (Request $request) {
            $email    = \App\Models\User::normalizeEmail(is_string($request->input('email')) ? $request->input('email') : '');
            $username = strtolower(trim(is_string($request->input('username')) ? $request->input('username') : ''));
            $limits   = (array) config('ballspot.abuse.register', []);

            return [
                Limit::perMinute(5)->by('reg-m|' . $request->ip()),
                Limit::perHour(20)->by('reg-h|' . $request->ip()),
                Limit::perHour((int) ($limits['attempts_per_email_hour'] ?? 5))->by('reg-e|' . ($email !== '' ? sha1($email) : 'none|' . $request->ip())),
                Limit::perHour((int) ($limits['attempts_per_username_hour'] ?? 5))->by('reg-u|' . ($username !== '' ? sha1($username) : 'none|' . $request->ip())),
            ];
        });

        // Password reset request: strict per email+IP (mail bomb / enumeration
        // hammering) plus a per-IP ceiling.
        // v1.9.13: plus an IP-independent per-email hourly ceiling (3) and a
        // per-IP HOURLY ceiling (10, was 10/min). Counted whether or not the
        // address exists, so a 429 never reveals an account.
        RateLimiter::for('forgot-password', function (Request $request) {
            $email  = strtolower(trim((string) $request->input('email')));
            $limits = (array) config('ballspot.abuse.password_reset', []);
            return [
                Limit::perMinute(3)->by('fp-e|' . $email . '|' . $request->ip()),
                Limit::perHour((int) ($limits['per_ip_hour'] ?? 10))->by('fp-ip|' . $request->ip()),
                Limit::perHour((int) ($limits['per_email_hour'] ?? 3))->by('fp-eh|' . sha1($email)),
            ];
        });

        // Password reset submit: bounds reset-token brute force. Per-IP AND
        // an IP-independent per-email ceiling, so an attacker with many IPs
        // cannot multiply attempts against one victim's reset flow.
        RateLimiter::for('reset-password', function (Request $request) {
            $email = strtolower((string) $request->input('email'));
            return [
                Limit::perMinute(5)->by('rp|' . $request->ip()),
                Limit::perMinute(5)->by('rp-e|' . $email),
            ];
        });

        // Email verification submit/resend: the per-code 5-attempt lock and
        // 60s cooldown remain the primary gates; these bound automated fan-out.
        RateLimiter::for('email-verify', function (Request $request) {
            return Limit::perMinute(10)->by('ev|' . ($request->user()?->id ?: $request->ip()));
        });
        RateLimiter::for('email-resend', function (Request $request) {
            return Limit::perMinute(3)->by('er|' . ($request->user()?->id ?: $request->ip()));
        });

        // Guess submission (daily / tournament / pack). 30/min per user is
        // ~2s per guess sustained — far above human play, low enough to stop
        // score-spam scripts.
        RateLimiter::for('gameplay', function (Request $request) {
            return Limit::perMinute(30)->by('play|' . ($request->user()?->id ?: $request->ip()));
        });

        // Push token registration: normal apps register once per launch.
        RateLimiter::for('push-tokens', function (Request $request) {
            return Limit::perMinute(10)->by('push|' . ($request->user()?->id ?: $request->ip()));
        });

        // Friend writes: request/accept/reject/remove. Well above human use,
        // low enough to stop friend-code enumeration scripts.
        RateLimiter::for('friends', function (Request $request) {
            return Limit::perMinute(20)->by('friends|' . ($request->user()?->id ?: $request->ip()));
        });

        // GDPR data export: heavyweight response, rarely needed.
        RateLimiter::for('export', function (Request $request) {
            return Limit::perHour(5)->by('export|' . ($request->user()?->id ?: $request->ip()));
        });

        // Avatar upload: the only multi-megabyte write in the API. The global
        // 120/min would otherwise allow ~240 MB/min of uploads per account.
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(6)->by('up|' . ($request->user()?->id ?: $request->ip()));
        });

        // Public profile lookups. User ids are sequential, so the global limiter
        // alone allowed walking the entire user directory (~172k profiles/day).
        // Keyed on IP as well so extra accounts do not multiply the budget.
        RateLimiter::for('profile-lookup', function (Request $request) {
            return [
                Limit::perMinute(30)->by('pl-u|' . ($request->user()?->id ?: $request->ip())),
                Limit::perHour(300)->by('pl-ip|' . $request->ip()),
            ];
        });

        // The one irreversible endpoint. A stolen token must not be able to
        // hammer the anonymization transaction; a real user never needs more.
        RateLimiter::for('account-delete', function (Request $request) {
            return Limit::perHour(3)->by('account-delete|' . ($request->user()?->id ?: $request->ip()));
        });

        // Tournament writes (create / join / start / cancel): each is a
        // lookup + locked write, and join is a code-guessing surface. The
        // per-user business caps bound state, not request rate — this does.
        RateLimiter::for('tournaments', function (Request $request) {
            return Limit::perMinute(20)->by('tournaments|' . ($request->user()?->id ?: $request->ip()));
        });

        // Admin session login: strict brute-force gate for the web form (the
        // panel is password-only — admin 2FA is per-user opt-in like everyone
        // else, see docs/security-hardening.md). Per IP here; the IP-independent
        // per-email ceiling (50 FAILED attempts / hour) lives in
        // App\Support\FailedLoginLimiter, inside the controller, so that only
        // wrong passwords count — as middleware it counted every request and
        // let anyone who knew the admin address lock the admin out.
        RateLimiter::for('admin-login', function (Request $request) {
            return Limit::perMinute(5)->by('admin-login|' . $request->ip());
        });

        // Friend read endpoints (/friends, /friends/requests, /friends/suggestions)
        // fan out per-row rank/XP lookups over up to 200 rows. The global 120/min
        // would allow a single account to force ~140k queries/min; cap it tighter.
        RateLimiter::for('friends-read', function (Request $request) {
            return Limit::perMinute(40)->by('friends-read|' . ($request->user()?->id ?: $request->ip()));
        });

        // Share claims (v1.9.12): each one is a few ledger lookups and up to
        // four inserts. XP is deduped per account / per day regardless, so
        // this only bounds request volume and event-row growth.
        RateLimiter::for('share', function (Request $request) {
            return [
                Limit::perMinute(10)->by('share|' . ($request->user()?->id ?: $request->ip())),
                Limit::perDay(100)->by('share-d|' . ($request->user()?->id ?: $request->ip())),
            ];
        });

        // Review / rate telemetry (v1.9.12): plain inserts, never rewarded.
        // Keeps one client from filling app_events.
        RateLimiter::for('growth-events', function (Request $request) {
            return [
                Limit::perMinute(30)->by('growth|' . ($request->user()?->id ?: $request->ip())),
                Limit::perDay(300)->by('growth-d|' . ($request->user()?->id ?: $request->ip())),
            ];
        });

        // Admin announcement send: pushes to every opted-in device.
        RateLimiter::for('admin-send', function (Request $request) {
            return Limit::perMinute(10)->by('admin-send|' . ($request->user()?->id ?: $request->ip()));
        });
        // Credential submission: per email+IP (tight) and per IP (looser). The
        // IP-independent per-email ceiling — needed because an attacker with N
        // addresses otherwise gets 5 guesses/min *per IP* against one victim —
        // is App\Support\FailedLoginLimiter (50 FAILED attempts / hour, checked
        // in AuthController::login). It no longer runs as middleware: that
        // counted successful logins too, so 50 junk requests with a victim's
        // address locked the real user out for an hour, repeatable forever.
        RateLimiter::for('login', function (Request $request) {
            $email = strtolower((string) $request->input('email'));
            return [
                Limit::perMinute(5)->by($email . '|' . $request->ip()),
                Limit::perMinute(20)->by('ip|' . $request->ip()),
            ];
        });

        // Code verification: per verification_id+IP. Higher than the app-level
        // 5-attempt lock so the per-code lock is what stops guessing (the
        // throttle only bounds automated fan-out).
        RateLimiter::for('login-verify', function (Request $request) {
            $id = (string) ($request->input('verification_id') ?: 'none');
            return Limit::perMinute(20)->by($id . '|' . $request->ip());
        });

        // BallPicker Clubs: POST /api/clubs/join. Bounds request volume per
        // user and per IP (config ballspot.clubs.join). The guessing gate is
        // the FAILED-code counter (App\Services\Clubs\ClubJoinFailureCounter),
        // which only counts codes that did not lead to a membership. In the
        // fail_closed list: an invite-code guessing surface.
        RateLimiter::for('club-join', function (Request $request) {
            $cfg = (array) config('ballspot.clubs.join', []);

            return [
                Limit::perMinute(max(1, (int) ($cfg['per_user_per_minute'] ?? 10)))->by('club-join-u|' . ($request->user()?->id ?: $request->ip())),
                Limit::perHour(max(1, (int) ($cfg['per_ip_per_hour'] ?? 30)))->by('club-join-ip|' . $request->ip()),
            ];
        });

        // Resend: per verification_id+IP. The 60s cooldown is the real gate;
        // this caps automated fan-out.
        RateLimiter::for('login-resend', function (Request $request) {
            $id = (string) ($request->input('verification_id') ?: 'none');
            return Limit::perMinute(5)->by($id . '|' . $request->ip());
        });
    }
}
