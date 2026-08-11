<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BadgeResource;
use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\PackAttempt;
use App\Services\PackPlayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PackPlayController extends Controller
{
    public function __construct(private PackPlayService $play) {}

    // POST /api/packs/{slug}/start — start a new attempt or resume the active one.
    public function start(Request $request, string $slug): JsonResponse
    {
        $pack    = $this->visiblePack($slug);
        $attempt = $this->play->startOrResume($request->user(), $pack);

        return response()->json([
            'attempt'   => $this->play->attemptState($attempt),
            'challenge' => $this->play->challengePayload($this->play->currentChallenge($attempt)),
        ]);
    }

    // GET /api/packs/{slug}/attempt — active or latest attempt for the pack.
    public function attempt(Request $request, string $slug): JsonResponse
    {
        $pack    = $this->visiblePack($slug);
        $attempt = $this->play->activeOrLatest($request->user(), $pack);

        if (! $attempt) {
            return response()->json(['attempt' => null, 'challenge' => null]);
        }

        return response()->json([
            'attempt'   => $this->play->attemptState($attempt),
            'challenge' => $attempt->status === PackAttempt::STATUS_ACTIVE
                ? $this->play->challengePayload($this->play->currentChallenge($attempt))
                : null,
        ]);
    }

    // POST /api/pack-attempts/{attempt}/guess — score the current challenge.
    public function guess(Request $request, PackAttempt $attempt): JsonResponse
    {
        // Enforce ownership at the route boundary. The service also checks this,
        // but the binding is global (PackAttempt, not user-scoped), so without a
        // guard here authorization would depend solely on service internals.
        abort_unless($attempt->user_id === $request->user()->id, 403, 'This attempt does not belong to you.');

        $data = $request->validate([
            'challenge_id' => ['required', 'integer'],
            'guessed_x'    => ['required', 'numeric', 'min:0', 'max:1'],
            'guessed_y'    => ['required', 'numeric', 'min:0', 'max:1'],
        ]);

        $r = $this->play->submitGuess(
            $request->user(),
            $attempt,
            (int) $data['challenge_id'],
            (float) $data['guessed_x'],
            (float) $data['guessed_y'],
        );

        /** @var Challenge $challenge */
        $challenge = $r['challenge'];

        return response()->json([
            'result' => [
                'score'            => $r['score']['score'],
                'distance'         => $r['score']['distance'],
                'guessed_x'        => (float) $data['guessed_x'],
                'guessed_y'        => (float) $data['guessed_y'],
                // Safe to reveal AFTER the guess is scored.
                'ball_x_ratio'     => (float) $challenge->ball_x_ratio,
                'ball_y_ratio'     => (float) $challenge->ball_y_ratio,
                'reveal_image_url' => $challenge->original_image_path ? asset('storage/' . $challenge->original_image_path) : null,
            ],
            'progress'       => $this->play->attemptState($r['attempt']),
            'next_challenge' => $r['completed'] ? null : $this->play->challengePayload($this->play->currentChallenge($r['attempt'])),
            'rank_progress'  => $r['rank_progress'],
            'rank_up'        => $r['rank_up'],
            'new_badges'     => BadgeResource::collection($r['new_badges'])->resolve(),
            'pack_completed' => $r['completed'],
            'final_score'    => $r['completed'] ? $r['attempt']->total_score : null,
            'completion_xp'  => $r['completed'] ? $r['completion_xp'] : null,
        ]);
    }

    /** Resolve an active+public pack by slug, or 404. */
    private function visiblePack(string $slug): ChallengePack
    {
        return ChallengePack::visibleToUsers()->where('slug', $slug)->firstOrFail();
    }
}
