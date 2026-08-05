<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    /**
     * POST /api/forgot-password
     *
     * Always returns a generic success response so email addresses cannot be
     * enumerated. If the email exists, a reset link is emailed (logged locally
     * when MAIL_MAILER=log).
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'If an account exists for that email, a password reset link has been sent.',
        ]);
    }

    /**
     * POST /api/reset-password
     *
     * Validates the token, sets the new password, and revokes existing
     * sessions/API tokens so a leaked old session cannot survive a reset.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password'       => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Revoke all existing Sanctum API tokens after a reset.
                $user->tokens()->delete();

                // ...and the database-backed web sessions behind the admin
                // panel, which Sanctum revocation does not touch. Without this
                // a hijacked admin session survives the one remediation the
                // product offers.
                if (Schema::hasTable('sessions')) {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                }

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Your password has been reset. Please log in.']);
        }

        // Generic failure for invalid/expired token or unknown email — do not
        // leak which of the two failed (avoids enumeration).
        return response()->json([
            'message' => 'This password reset link is invalid or has expired.',
        ], 422);
    }
}
