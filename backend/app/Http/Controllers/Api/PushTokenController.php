<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterPushTokenRequest;
use App\Models\PushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushTokenController extends Controller
{
    /**
     * Ceiling on registrations per account. A person realistically has a
     * handful of devices; the cap stops one account from accumulating
     * unbounded rows that every announcement would then fan out to.
     */
    public const MAX_TOKENS_PER_USER = 10;

    // POST /api/me/push-tokens
    public function store(RegisterPushTokenRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        $existing = PushToken::where('token', $data['token'])->first();

        // Reassignment is intentional: when a device switches accounts the OS
        // hands the new user the same Expo token, and it must follow them.
        // Only the device itself knows its token — it is never returned by any
        // API — so this is not a path to capture someone else's device.
        if (!$existing && $this->tokenCountFor($user->id) >= self::MAX_TOKENS_PER_USER) {
            return response()->json([
                'message' => 'Too many registered devices. Remove one before adding another.',
            ], 422);
        }

        PushToken::updateOrCreate(
            ['token' => $data['token']],
            [
                'user_id'      => $user->id,
                'platform'     => $data['platform'] ?? null,
                'device_name'  => $data['device_name'] ?? null,
                'last_seen_at' => now(),
            ],
        );

        return response()->json(['status' => 'registered'], 201);
    }

    private function tokenCountFor(int $userId): int
    {
        return PushToken::where('user_id', $userId)->count();
    }

    // DELETE /api/me/push-tokens
    // With a token in the body: remove that registration if it belongs to the
    // caller. Without: remove all of the caller's registrations (logout /
    // account deletion). Always scoped to the authenticated user.
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['nullable', 'string', 'max:255'],
        ]);

        PushToken::where('user_id', $request->user()->id)
            ->when($data['token'] ?? null, fn ($q, $token) => $q->where('token', $token))
            ->delete();

        return response()->json(['status' => 'removed']);
    }
}
