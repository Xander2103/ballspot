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
use App\Services\XpService;
use Illuminate\Http\Request;

class RoundController extends Controller
{
    public function __construct(
        private ScoreService $scoreService,
        private BadgeService $badgeService,
        private PlayerRankService $rankService,
        private XpService $xpService,
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

        if (Guess::where('league_round_id', $round->id)->where('user_id', $userId)->exists()) {
            return response()->json(['message' => 'Already submitted a guess for this round'], 422);
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

        $xpAfter  = $this->xpService->getTotalXp($user);
        $rankUp   = $this->rankService->rankUp($xpBefore, $xpAfter);

        return (new GuessResultResource($guess))
            ->additional([
                'new_badges'    => BadgeResource::collection($newBadges)->resolve(),
                'rank_progress' => [
                    'xp_gained' => $xpAfter - $xpBefore,
                    'rank'      => $this->rankService->forXp($xpAfter),
                ],
                'rank_up'       => $rankUp,
            ]);
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
