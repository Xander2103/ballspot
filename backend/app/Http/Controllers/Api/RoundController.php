<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitGuessRequest;
use App\Http\Resources\BadgeResource;
use App\Http\Resources\GuessResultResource;
use App\Models\Guess;
use App\Models\LeagueRound;
use App\Models\XpEvent;
use App\Services\BadgeService;
use App\Services\PlayerRankService;
use App\Services\ScoreService;
use App\Services\TournamentCompletionService;
use App\Services\XpService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoundController extends Controller
{
    public function __construct(
        private ScoreService $scoreService,
        private BadgeService $badgeService,
        private PlayerRankService $rankService,
        private XpService $xpService,
        private TournamentCompletionService $completionService,
    ) {}

    public function submitGuess(SubmitGuessRequest $request, LeagueRound $round)
    {
        $userId = $request->user()->id;

        if (!$round->league->members()->where('user_id', $userId)->exists()) {
            return response()->json(['message' => 'Not a member of this league'], 403);
        }

        if ($round->status !== 'open') {
            return response()->json(['message' => 'This round is closed'], 422);
        }

        // Cancelling a tournament only flips the league row — its rounds stay
        // 'open', so without this the league's own members can keep scoring
        // (and earning XP) into a tournament that was shut down.
        if ($round->league->status !== 'active') {
            return response()->json(['message' => 'This tournament is not active'], 422);
        }

        // Cheap rejection without a lock for the common replay cases; the
        // same checks run again under the lock below before the insert.
        if ($rejection = $this->rejectIfNotAllowed($round, $userId)) {
            return $rejection;
        }

        $user     = $request->user();
        $xpBefore = $this->xpService->getTotalXp($user);

        try {
            $guess = DB::transaction(function () use ($request, $round, $userId) {
                // Serialize this user's tournament submits. Both checks in
                // rejectIfNotAllowed() are count-then-insert: requests fired in
                // parallel could all pass them and burn every round of a
                // multi-day tournament at once (the per-round unique index does
                // not help — each round is a different row). The user row lock
                // queues the second request until the first has committed.
                // No-op on SQLite (tests); SELECT ... FOR UPDATE on MySQL.
                DB::table('users')->where('id', $userId)->lockForUpdate()->first();

                if ($rejection = $this->rejectIfNotAllowed($round, $userId)) {
                    throw new HttpResponseException($rejection);
                }

                $challenge = $round->challenge;
                $result = $this->scoreService->calculate(
                    $request->guess_x_ratio,
                    $request->guess_y_ratio,
                    $challenge->ball_x_ratio,
                    $challenge->ball_y_ratio,
                );

                return Guess::create([
                    'league_round_id' => $round->id,
                    'user_id' => $userId,
                    'guess_x_ratio' => $request->guess_x_ratio,
                    'guess_y_ratio' => $request->guess_y_ratio,
                    'distance' => $result['distance'],
                    'score' => $result['score'],
                    'submitted_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Two identical submits that still raced past the checks (engines
            // without row locks): the unique index on (round, user) kept the
            // first row — answer like any replay, never a 500.
            return $this->alreadySubmitted();
        }

        $guess->load('round.challenge');

        // XP: the guess score (deduped per guess).
        $this->xpService->awardXp(
            $user,
            XpEvent::SOURCE_TOURNAMENT_GUESS,
            $guess->id,
            (int) $guess->score,
            'Tournament round completed',
            ['league_round_id' => $round->id, 'league_id' => $round->league_id],
        );

        // Award any newly-earned virtual trophies (idempotent; grants badge XP).
        $newBadges = $this->badgeService->evaluateTournamentGuess($user, $guess);

        // If this guess finished the tournament, complete it exactly once and
        // award winner/top-3 rewards. Only the finishing submission gets a fresh
        // result; reopening a result never re-completes (status is no longer active).
        $completion = null;
        $completionResult = $this->completionService->completeIfFinished($round->league);
        if ($completionResult !== null && isset($completionResult['per_user'][$userId])) {
            $mine = $completionResult['per_user'][$userId];
            $completion = [
                'is_completed'  => true,
                'placement'     => $mine['placement'],
                'total_players' => $mine['total_players'],
                'xp_awarded'    => $mine['xp_awarded'],
            ];
            // Surface completion badges through the existing new_badges channel.
            $newBadges = array_merge($newBadges, $mine['new_badges']);
        }

        // Compute XP AFTER completion so any tournament-win XP counts toward
        // xp_gained and can trigger a rank-up in this same response.
        $xpAfter  = $this->xpService->getTotalXp($user);
        $rankUp   = $this->rankService->rankUp($xpBefore, $xpAfter);

        $additional = [
            'new_badges'    => BadgeResource::collection($newBadges)->resolve(),
            'rank_progress' => [
                'xp_gained' => $xpAfter - $xpBefore,
                'rank'      => $this->rankService->forXp($xpAfter),
            ],
            'rank_up'       => $rankUp,
        ];
        if ($completion !== null) {
            $additional['tournament_completion'] = $completion;
        }

        return (new GuessResultResource($guess))->additional($additional);
    }

    /** 422 when this user may not submit to this round right now, else null. */
    private function rejectIfNotAllowed(LeagueRound $round, int $userId): ?JsonResponse
    {
        if (Guess::where('league_round_id', $round->id)->where('user_id', $userId)->exists()) {
            return $this->alreadySubmitted();
        }

        // rounds_per_day was previously enforced only when handing out the next
        // round (LeagueController::currentRound). Round ids are sequential, so
        // a client could skip that read and burn every round of a multi-day
        // tournament in one minute — which also wins score ties, since those
        // break on the earliest last-guess time.
        $playedToday = Guess::whereHas('round', fn ($q) => $q->where('league_id', $round->league_id))
            ->where('user_id', $userId)
            ->whereDate('submitted_at', now()->toDateString())
            ->count();

        if ($playedToday >= $round->league->rounds_per_day) {
            return response()->json(['message' => 'You have played all rounds available for today.'], 422);
        }

        return null;
    }

    private function alreadySubmitted(): JsonResponse
    {
        // The stable `code` lets the app open the stored result instead of
        // showing a double-tap as an error.
        return response()->json([
            'message' => 'Already submitted a guess for this round',
            'code'    => 'already_submitted',
        ], 422);
    }

    public function result(Request $request, LeagueRound $round)
    {
        $guess = Guess::where('league_round_id', $round->id)
            ->where('user_id', $request->user()->id)
            ->with('round.challenge')
            ->first();

        if (!$guess) {
            return response()->json(['message' => 'No guess found'], 404);
        }

        return new GuessResultResource($guess);
    }
}
