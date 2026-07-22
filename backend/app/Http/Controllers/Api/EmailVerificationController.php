<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(private EmailVerificationService $service) {}

    /**
     * POST /api/email/verify
     *
     * Verifies the authenticated (but unverified) user's email with the 6-digit
     * code that was emailed at registration. Returns the (now verified) user.
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);

        $user = $request->user();
        $this->service->verify($user, $data['code']);

        return response()->json([
            'email_verified' => true,
            'user'           => new UserResource($user->fresh()),
            'message'        => 'Your email has been verified.',
        ]);
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
            'message'        => 'A new verification code has been sent to your email.',
        ]);
    }
}
