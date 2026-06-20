<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitGuessRequest;
use App\Http\Resources\GuessResultResource;
use App\Models\Guess;
use App\Models\LeagueRound;
use App\Services\ScoreService;
use Illuminate\Http\Request;

class RoundController extends Controller
{
    public function __construct(private ScoreService $scoreService) {}

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
        return new GuessResultResource($guess);
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
