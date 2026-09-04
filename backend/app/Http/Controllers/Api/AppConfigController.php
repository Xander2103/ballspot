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
            'minimum_age'                 => (int) config('ballspot.legal.minimum_age', 16),
            'terms_version'               => (string) config('ballspot.legal.terms_version', '2026-08'),
        ]);
    }
}
