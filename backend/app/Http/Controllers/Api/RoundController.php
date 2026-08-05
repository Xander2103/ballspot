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
use Illuminate\Http\Request;

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

        if (Guess::where('league_round_id', $round->id)->where('user_id', $userId)->exists()) {
            return response()->json(['message' => 'Already submitted a guess for this round'], 422);
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

        $challenge = $round->challenge;
        $result = $this->scoreService->calculate(
            $request->guess_x_ratio,
            $request->guess_y_ratio,
            $challenge->ball_x_ratio,
            $challenge->ball_y_ratio,
        );

        $user     = $request->user();
        $xpBefore = $this->xpService->getTotalXp($user);

        $guess = Guess::create([
            'league_round_id' => $round->id,
            'user_id' => $userId,
            'guess_x_ratio' => $request->guess_x_ratio,
            'guess_y_ratio' => $request->guess_y_ratio,
            'distance' => $result['distance'],
            'score' => $result['score'],
            'submitted_at' => now(),
        ]);

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
