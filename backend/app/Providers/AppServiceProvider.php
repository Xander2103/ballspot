<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiters();
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
        RateLimiter::for('register', function (Request $request) {
            return [
                Limit::perMinute(5)->by('reg-m|' . $request->ip()),
                Limit::perHour(20)->by('reg-h|' . $request->ip()),
            ];
        });

        // Password reset request: strict per email+IP (mail bomb / enumeration
        // hammering) plus a per-IP ceiling.
        RateLimiter::for('forgot-password', function (Request $request) {
            $email = strtolower((string) $request->input('email'));
            return [
                Limit::perMinute(3)->by('fp-e|' . $email . '|' . $request->ip()),
                Limit::perMinute(10)->by('fp-ip|' . $request->ip()),
            ];
        });

        // Password reset submit: bounds reset-token brute force.
        RateLimiter::for('reset-password', function (Request $request) {
            return Limit::perMinute(5)->by('rp|' . $request->ip());
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

        // Admin session login: strict brute-force gate (admins also have
        // forced 2FA on the API path; this protects the web form).
        RateLimiter::for('admin-login', function (Request $request) {
            return Limit::perMinute(5)->by('admin-login|' . $request->ip());
        });

        // Admin announcement send: pushes to every opted-in device.
        RateLimiter::for('admin-send', function (Request $request) {
            return Limit::perMinute(10)->by('admin-send|' . ($request->user()?->id ?: $request->ip()));
        });
        // Credential submission: per email+IP (tight), per IP (looser), and a
        // per-email hourly ceiling. The last one is IP-independent on purpose —
        // without it, an attacker with N addresses gets 5 guesses/min *per IP*
        // against one victim account, which no other limit here bounds. Hourly
        // (not per-minute) so a spammer cannot cheaply lock a victim out.
        RateLimiter::for('login', function (Request $request) {
            $email = strtolower((string) $request->input('email'));
            return [
                Limit::perMinute(5)->by($email . '|' . $request->ip()),
                Limit::perMinute(20)->by('ip|' . $request->ip()),
                Limit::perHour(50)->by('login-email|' . $email),
            ];
        });

        // Code verification: per verification_id+IP. Higher than the app-level
        // 5-attempt lock so the per-code lock is what stops guessing (the
        // throttle only bounds automated fan-out).
        RateLimiter::for('login-verify', function (Request $request) {
            $id = (string) ($request->input('verification_id') ?: 'none');
            return Limit::perMinute(20)->by($id . '|' . $request->ip());
        });

        // Resend: per verification_id+IP. The 60s cooldown is the real gate;
        // this caps automated fan-out.
        RateLimiter::for('login-resend', function (Request $request) {
            $id = (string) ($request->input('verification_id') ?: 'none');
            return Limit::perMinute(5)->by($id . '|' . $request->ip());
        });
    }
}
