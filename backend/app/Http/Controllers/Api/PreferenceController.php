<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePreferencesRequest;
use App\Http\Resources\SportResource;
use App\Support\AppLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PreferenceController extends Controller
{
    // GET /api/me/preferences
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->payload($request->user()));
    }

    // PATCH /api/me/preferences
    public function update(UpdatePreferencesRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        // Only touch keys the client actually sent (partial update semantics).
        if (array_key_exists('preferred_sport_id', $data)) {
            $user->preferred_sport_id = $data['preferred_sport_id'];
        }
        if (array_key_exists('selected_theme', $data)) {
            $user->selected_theme = $data['selected_theme'];
        }
        if (array_key_exists('preferred_language', $data)) {
            $user->preferred_language = $data['preferred_language'];
        }
        if (array_key_exists('two_factor_enabled', $data)) {
            $enabled = (bool) $data['two_factor_enabled'];
            if ($enabled !== (bool) $user->two_factor_enabled) {
                // Security setting: always logged (user id + new state only).
                AppLog::event('login.2fa_setting_changed', ['user_id' => $user->id, 'enabled' => $enabled]);
            }
            $user->two_factor_enabled = $enabled;
        }

        $user->save();

        return response()->json($this->payload($user->fresh('preferredSport')));
    }

    private function payload($user): array
    {
        $user->loadMissing('preferredSport');

        return [
            'preferred_sport' => $user->preferredSport
                ? (new SportResource($user->preferredSport))->resolve()
                : null,
            'selected_theme'     => $user->selected_theme,
            'preferred_language' => $user->preferredLocale(),
            'two_factor_enabled' => (bool) $user->two_factor_enabled,
            'avatar_url'         => $user->avatarUrl(),
            'available_themes'    => config('ballspot.themes'),
            'available_languages' => config('ballspot.languages'),
        ];
    }
}
