<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BadgeResource;
use App\Models\AppEvent;
use App\Models\DailyChallenge;
use App\Services\GrowthEventService;
use App\Services\ShareRewardException;
use App\Services\ShareRewardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Growth endpoints: share rewards + review/rate telemetry.
 *
 *  POST /api/share        — the user opened the native share sheet; records
 *                           the share and awards whatever is still available
 *                           (server-side amounts and limits only).
 *  GET  /api/share/status — what sharing can still earn today.
 *  POST /api/events       — review / rate telemetry. Allow-listed names only;
 *                           NEVER awards anything (store reviews must not be
 *                           incentivized), and share events are refused here
 *                           so stats cannot be inflated from the client.
 */
class GrowthController extends Controller
{
    public function share(Request $request, ShareRewardService $rewards): JsonResponse
    {
        $data = $request->validate([
            'type'               => ['required', 'string', Rule::in(ShareRewardService::TYPES)],
            'daily_challenge_id' => ['required_if:type,' . ShareRewardService::TYPE_DAILY_RESULT, 'nullable', 'integer'],
        ]);

        $daily = null;
        if ($data['type'] === ShareRewardService::TYPE_DAILY_RESULT) {
            $daily = DailyChallenge::find((int) $data['daily_challenge_id']);
            if (!$daily) {
                return response()->json(['message' => __('messages.share.no_guess'), 'code' => 'no_guess'], 422);
            }
        }

        try {
            $result = $rewards->claim($request->user(), $data['type'], $daily);
        } catch (ShareRewardException $e) {
            return response()->json(['message' => __('messages.share.' . $e->code_), 'code' => $e->code_], 422);
        }

        $result['new_badges'] = BadgeResource::collection($result['new_badges'])->resolve();

        return response()->json($result);
    }

    public function shareStatus(Request $request, ShareRewardService $rewards): JsonResponse
    {
        return response()->json($rewards->status($request->user()));
    }

    public function track(Request $request, GrowthEventService $events): JsonResponse
    {
        $data = $request->validate([
            'event'              => ['required', 'string', Rule::in(AppEvent::CLIENT_EVENTS)],
            'metadata'           => ['sometimes', 'nullable', 'array'],
            'metadata.score'     => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'metadata.trigger'   => ['sometimes', 'nullable', 'string', Rule::in(GrowthEventService::TRIGGERS)],
        ]);

        // Only the allow-listed keys survive (GrowthEventService::sanitizeMetadata).
        $events->record($request->user(), $data['event'], $data['metadata'] ?? []);

        return response()->json(['recorded' => true, 'event' => $data['event']], 201);
    }
}
