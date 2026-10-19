<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/config — public, unauthenticated feature flags the app needs
 * before a user has an account (register screen).
 *
 * Booleans and public constants only. The beta CODE itself, mail settings,
 * keys or any other secret must never be added here — see AppConfigTest.
 */
class AppConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'app_name'                    => (string) config('ballspot.app_name', 'BallPicker'),
            // True while BALLPICKER_BETA_CODE is set (private beta); false for
            // the public launch. The app hides its beta-code field when false.
            'beta_gate'                   => (bool) config('ballspot.beta_code'),
            'email_verification_required' => (bool) config('ballspot.auth.require_email_verification', true),
            // False for launch: the app hides the Profile toggle that turns the
            // emailed login code on, and the API refuses new opt-ins. Accounts
            // that already enabled it keep it (they still see the toggle).
            'login_two_factor_opt_in'     => (bool) config('ballspot.auth.login_2fa_opt_in', false),
            'minimum_age'                 => (int) config('ballspot.legal.minimum_age', 16),
            'terms_version'               => (string) config('ballspot.legal.terms_version', '2026-08'),
            // Preferred-language allow-list + default, so the register screen
            // never offers a value the backend would reject.
            'supported_languages'         => (array) config('ballspot.languages', ['en']),
            'default_language'            => (string) config('ballspot.default_language', 'en'),
            // Registration CAPTCHA (v1.9.13). Off by default. The site key is
            // the PUBLIC widget key by design; the secret never appears here.
            'captcha_required'            => (bool) config('ballspot.captcha.enabled', false),
            'captcha_provider'            => (string) config('ballspot.captcha.provider', 'turnstile'),
            'captcha_site_key'            => config('ballspot.captcha.enabled', false) ? (app(\App\Services\CaptchaVerifier::class)->siteKey()) : null,
            // Feature flags for optional areas. The app shows the Clubs tab
            // only when features.clubs is true (the API 404s otherwise).
            'features'                    => [
                'clubs' => \App\Http\Middleware\EnsureFeatureEnabled::enabled('clubs'),
            ],
        ]);
    }
}
