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

        // GDPR data export: heavyweight response, rarely needed.
        RateLimiter::for('export', function (Request $request) {
            return Limit::perHour(5)->by('export|' . ($request->user()?->id ?: $request->ip()));
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
        // Credential submission: per email+IP (tight) and per IP (looser).
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

        // Resend: per verification_id+IP. The 60s cooldown is the real gate;
        // this caps automated fan-out.
        RateLimiter::for('login-resend', function (Request $request) {
            $id = (string) ($request->input('verification_id') ?: 'none');
            return Limit::perMinute(5)->by($id . '|' . $request->ip());
        });
    }
}
