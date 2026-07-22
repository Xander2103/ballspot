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
     * Named rate limiters for the email two-factor login flow. Keys combine the
     * relevant identifier with the client IP so one abuser cannot lock out
     * everyone, while still bounding brute-force / email-spam.
     */
    private function configureRateLimiters(): void
    {
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
