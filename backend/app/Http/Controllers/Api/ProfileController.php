<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Guess;
use App\Services\DailyStreakService;
use App\Services\PlayerRankService;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(
        private DailyStreakService $streakService,
        private PlayerRankService $rankService,
    ) {}

    // GET /api/me/rank — personal rank/level/XP progression.
    public function rank(Request $request)
    {
        return response()->json(['rank' => $this->rankService->forUser($request->user())]);
    }

    public function stats(Request $request)
    {
        $user = $request->user();

        $tournamentsCount = $user->leagues()->count();
        $completedCount   = $user->leagues()->where('status', 'completed')->count();
        $guessesCount     = Guess::where('user_id', $user->id)->count();
        $totalScore       = (int) (Guess::where('user_id', $user->id)->sum('score') ?? 0);
        $avgScore         = $guessesCount > 0
            ? round((float) Guess::where('user_id', $user->id)->avg('score'), 1)
            : 0.0;

        $streaks      = $this->streakService->getStreakForUser($user);
        $dailyGuesses = \App\Models\DailyChallengeGuess::where('user_id', $user->id)->get();
        $dailyStats   = [
            'total_played'   => $dailyGuesses->count(),
            'average_score'  => $dailyGuesses->count() > 0 ? round($dailyGuesses->avg('score'), 1) : 0,
            'best_score'     => $dailyGuesses->max('score') ?? 0,
            'current_streak' => $streaks['current'],
            'best_streak'    => $streaks['best'],
        ];

        return response()->json([
            'tournaments_count'           => $tournamentsCount,
            'completed_tournaments_count' => $completedCount,
            'guesses_count'               => $guessesCount,
            'total_score'                 => $totalScore,
            'average_score'               => $avgScore,
            'daily_challenges_played'     => $dailyStats['total_played'],
            'average_daily_score'         => $dailyStats['average_score'],
            'best_daily_score'            => $dailyStats['best_score'],
            'current_daily_streak'        => $dailyStats['current_streak'],
            'best_daily_streak'           => $dailyStats['best_streak'],
            // Personal progression (distinct from leaderboard position).
            'rank'                        => $this->rankService->forUser($user),
        ]);
    }
}
