<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Services\PasswordResetFlow;
use Illuminate\Http\JsonResponse;

class PasswordResetController extends Controller
{
    public function __construct(private PasswordResetFlow $flow) {}

    /**
     * POST /api/forgot-password
     *
     * Always returns a generic success response so email addresses cannot be
     * enumerated. If the email exists, a reset link is emailed (logged locally
     * when MAIL_MAILER=log).
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->flow->request((string) $request->input('email'), 'api');

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
        $ok = $this->flow->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            'api',
        );

        if ($ok) {
            return response()->json(['message' => 'Your password has been reset. Please log in.']);
        }

        // Generic failure for invalid/expired token or unknown email — do not
        // leak which of the two failed (avoids enumeration).
        return response()->json([
            'message' => PasswordResetFlow::INVALID_LINK_MESSAGE,
            'reason'  => 'invalid_or_expired',
        ], 422);
    }
}
