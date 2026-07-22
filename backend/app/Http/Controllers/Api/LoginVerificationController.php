<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResendLoginCodeRequest;
use App\Http\Requests\VerifyLoginCodeRequest;
use App\Http\Resources\UserResource;
use App\Services\LoginVerificationService;
use Illuminate\Http\JsonResponse;

class LoginVerificationController extends Controller
{
    public function __construct(private LoginVerificationService $service) {}

    /**
     * POST /api/login/verify
     *
     * Exchanges a valid one-time code for a Sanctum token. This is the only
     * place a token is issued for the login flow — a correct password alone
     * never yields a token.
     */
    public function verify(VerifyLoginCodeRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user  = $this->service->verify($data['verification_id'], $data['code']);
        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'user'  => new UserResource($user),
            'token' => $token,
        ]);
    }

    /**
     * POST /api/login/resend-code
     *
     * Re-issues a code for an in-flight verification (cooldown-limited). The
     * response is intentionally generic and never reveals whether a resend
     * actually happened.
     */
    public function resend(ResendLoginCodeRequest $request): JsonResponse
    {
        $this->service->resend(
            $request->validated()['verification_id'],
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json([
            'message' => 'If your login is still pending, a new code has been sent to your email.',
        ]);
    }
}
