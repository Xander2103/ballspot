<?php

namespace App\Support;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Why did GET /api/me answer 401? Logged as a category so /admin/diagnostics
 * can tell "the app shipped no token" from "the token's user was removed"
 * without ever writing the token itself.
 *
 *   me.failed_invalid_token  reason=missing_token | invalid_token | expired_token
 *   me.failed_missing_user   the token row exists but its user is gone
 *
 * (me.failed_anonymized is logged by EnsureAccountIsActive, which sees the
 * authenticated user.)
 */
final class SessionDiagnostics
{
    public static function logMeFailure(Request $request): void
    {
        $bearer = $request->bearerToken();

        if (!$bearer) {
            AppLog::warn('me.failed_invalid_token', ['reason' => 'missing_token']);

            return;
        }

        try {
            $token = PersonalAccessToken::findToken($bearer);
        } catch (\Throwable) {
            $token = null;
        }

        if (!$token) {
            AppLog::warn('me.failed_invalid_token', ['reason' => 'invalid_token']);

            return;
        }

        if ($token->expires_at && $token->expires_at->isPast()) {
            AppLog::warn('me.failed_invalid_token', ['reason' => 'expired_token', 'user_id' => $token->tokenable_id]);

            return;
        }

        if (!$token->tokenable) {
            AppLog::warn('me.failed_missing_user', ['user_id' => $token->tokenable_id]);

            return;
        }

        AppLog::warn('me.failed_invalid_token', ['reason' => 'rejected', 'user_id' => $token->tokenable_id]);
    }
}
