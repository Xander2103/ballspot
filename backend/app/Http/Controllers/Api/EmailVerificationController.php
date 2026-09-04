<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmailVerificationController extends Controller
{
    public const SESSION_MISMATCH_MESSAGE = 'This code belongs to a different account than the one signed in on this device. Please log in again with the account you just created.';

    public function __construct(private EmailVerificationService $service) {}

    /**
     * POST /api/email/verify
     *
     * Verifies the authenticated (but unverified) user's email with the 6-digit
     * code that was emailed at registration. Returns the (now verified) user.
     *
     * The code is verified against the TOKEN's user. The app may also send the
     * `email` it believes it is verifying; when that does not match the token's
     * account (a stale session from a previous login on the same device) the
     * response is a 409 `session_mismatch` — not a misleading "invalid code".
     */
    public function verify(Request $request): JsonResponse
    {
        $user = $request->user();

        $code = EmailVerificationService::normalizeCode($request->input('code'));
        if ($code === null) {
            throw ValidationException::withMessages(['code' => ['Enter the 6-digit code from your email.']]);
        }

        $hint = $request->input('email');
        if (is_string($hint) && trim($hint) !== '' && strcasecmp(trim($hint), (string) $user->email) !== 0) {
            $this->service->logFailure($user, 'session_mismatch');

            return response()->json([
                'message' => self::SESSION_MISMATCH_MESSAGE,
                'reason'  => 'session_mismatch',
            ], 409);
        }

        $this->service->verify($user, $code);

        return response()->json([
            'email_verified' => true,
            'user'           => new UserResource($user->fresh()),
            'message'        => 'Your email has been verified.',
        ]);
    }

    /**
     * GET /api/email/verification-status
     *
     * Which account the token belongs to and whether a code is live — lets the
     * verification screen show the real target email and a truthful resend
     * countdown instead of guessing from navigation params.
     */
    public function status(Request $request): JsonResponse
    {
        return response()->json($this->service->status($request->user()));
    }

    /**
     * POST /api/email/verification-notification
     *
     * Resends a verification code (cooldown-limited). Generic response; if the
     * account is already verified it simply reports so.
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'email_verified' => true,
                'message'        => 'Your email is already verified.',
            ]);
        }

        $this->service->resend($user, $request->ip(), $request->userAgent());

        return response()->json([
            'email_verified' => false,
            'email'          => $user->email,
            'message'        => 'A new verification code has been sent to your email.',
        ]);
    }
}
