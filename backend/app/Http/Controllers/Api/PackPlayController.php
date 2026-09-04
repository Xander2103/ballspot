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
use Symfony\Component\HttpKernel\Exception\HttpException;

class PackPlayController extends Controller
{
    public function __construct(private PackPlayService $play) {}

    /**
     * POST /api/packs/{slug}/start — start a new attempt or resume the active one.
     *
     * A pack the user already completed answers 409 with the completed attempt
     * and its completion overview, so the app shows results instead of
     * offering a replay.
     */
    public function start(Request $request, string $slug): JsonResponse
    {
        $pack = $this->visiblePack($slug);

        try {
            $attempt = $this->play->startOrResume($request->user(), $pack);
        } catch (HttpException $e) {
            if ($e->getStatusCode() !== 409) {
                throw $e;
            }
            $completed = $this->play->latestCompleted($request->user(), $pack->id);

            return response()->json([
                'message'    => PackPlayService::ALREADY_COMPLETED_MESSAGE,
                'attempt'    => $completed ? $this->play->attemptState($completed) : null,
                'challenge'  => null,
                'completion' => $completed ? $this->play->completionSummary($completed) : null,
            ], 409);
        }

        return response()->json([
            'attempt'    => $this->play->attemptState($attempt),
            'challenge'  => $this->play->challengePayload($this->play->currentChallenge($attempt)),
            'completion' => null,
        ]);
    }

    // GET /api/packs/{slug}/attempt — active or latest attempt for the pack.
    public function attempt(Request $request, string $slug): JsonResponse
    {
        $pack    = $this->visiblePack($slug);
        $attempt = $this->play->activeOrLatest($request->user(), $pack);

        if (! $attempt) {
            return response()->json(['attempt' => null, 'challenge' => null, 'completion' => null]);
        }

        return response()->json([
            'attempt'    => $this->play->attemptState($attempt),
            'challenge'  => $attempt->status === PackAttempt::STATUS_ACTIVE
                ? $this->play->challengePayload($this->play->currentChallenge($attempt))
                : null,
            'completion' => $this->play->completionSummary($attempt),
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

        try {
            $r = $this->play->submitGuess(
                $request->user(),
                $attempt,
                (int) $data['challenge_id'],
                (float) $data['guessed_x'],
                (float) $data['guessed_y'],
            );
        } catch (HttpException $e) {
            if ($e->getStatusCode() !== 409) {
                throw $e;
            }
            // Completed attempt, unknown challenge: friendly, with the state the
            // app needs to show the overview instead of an error.
            $attempt->refresh();

            return response()->json([
                'message'        => PackPlayService::ALREADY_COMPLETED_MESSAGE,
                'pack_completed' => true,
                'progress'       => $this->play->attemptState($attempt),
                'completion'     => $this->play->completionSummary($attempt),
            ], 409);
        }

        /** @var Challenge $challenge */
        $challenge = $r['challenge'];
        $guess     = $r['guess'];

        return response()->json([
            'result' => [
                'score'            => $r['score']['score'],
                'distance'         => $r['score']['distance'],
                'guessed_x'        => (float) $guess->guessed_x,
                'guessed_y'        => (float) $guess->guessed_y,
                // Safe to reveal AFTER the guess is scored.
                'ball_x_ratio'     => (float) $challenge->ball_x_ratio,
                'ball_y_ratio'     => (float) $challenge->ball_y_ratio,
                'reveal_image_url' => $challenge->original_image_path ? asset('storage/' . $challenge->original_image_path) : null,
            ],
            'progress'          => $this->play->attemptState($r['attempt']),
            'next_challenge'    => $r['completed'] ? null : $this->play->challengePayload($this->play->currentChallenge($r['attempt'])),
            'rank_progress'     => $r['rank_progress'],
            'rank_up'           => $r['rank_up'],
            'new_badges'        => BadgeResource::collection($r['new_badges'])->resolve(),
            'pack_completed'    => $r['completed'],
            'already_completed' => $r['already_completed'],
            'final_score'       => $r['completed'] ? $r['attempt']->total_score : null,
            'completion_xp'     => $r['completed'] ? $r['completion_xp'] : null,
            'completion'        => $r['completion'],
        ]);
    }

    /** Resolve an active+public pack by slug, or 404. */
    private function visiblePack(string $slug): ChallengePack
    {
        return ChallengePack::visibleToUsers()->where('slug', $slug)->firstOrFail();
    }
}
