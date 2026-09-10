<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Services\PasswordResetFlow;
use App\Support\AuthError;
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
            'message' => __('messages.auth.reset_link_sent'),
        ]);
    }

    /**
     * POST /api/reset-password
     *
     * Validates the token, sets the new password, and revokes existing
     * sessions/API tokens so a leaked old session cannot survive a reset.
     *
     * Outcomes (never a raw exception):
     *  200 { message }                                   — done, log in again
     *  422 { message, code: reset_token_expired|reset_token_invalid, reason }
     *  500 { message, code: reset_failed }               — retryable; the old
     *      password and the link are both still valid (transactional reset)
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $outcome = $this->flow->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            'api',
        );

        return match ($outcome) {
            PasswordResetFlow::COMPLETED     => response()->json(['message' => __('messages.auth.password_reset_done')]),
            PasswordResetFlow::EXPIRED_TOKEN => AuthError::response(AuthError::RESET_TOKEN_EXPIRED, 422, null, ['reason' => 'expired']),
            PasswordResetFlow::FAILED        => AuthError::response(AuthError::RESET_FAILED, 500, null, ['reason' => 'failed']),
            // Unknown account and wrong token share one answer — no enumeration.
            default                          => AuthError::response(AuthError::RESET_TOKEN_INVALID, 422, null, ['reason' => 'invalid_or_expired']),
        };
    }
}
