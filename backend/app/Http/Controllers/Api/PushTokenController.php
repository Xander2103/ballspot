<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterPushTokenRequest;
use App\Models\PushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushTokenController extends Controller
{
    // POST /api/me/push-tokens
    public function store(RegisterPushTokenRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        // Token is globally unique. Re-registering an existing token reassigns
        // it to the current user (device switched accounts) and refreshes its
        // metadata. Raw tokens are never returned.
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
