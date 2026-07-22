<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePreferencesRequest;
use App\Http\Resources\SportResource;
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
            'selected_theme'  => $user->selected_theme,
            'avatar_url'      => $user->avatarUrl(),
            'available_themes' => config('ballspot.themes'),
        ];
    }
}
