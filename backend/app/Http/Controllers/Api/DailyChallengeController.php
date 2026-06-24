<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyChallenge;
use App\Models\Challenge;
use App\Models\DailyChallengeGuess;
use App\Services\DailyStreakService;
use App\Services\ScoreService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DailyChallengeController extends Controller
{
    public function __construct(
        private ScoreService $scoreService,
        private DailyStreakService $streakService,
    ) {}

    // GET /api/daily/today
    public function today(Request $request): JsonResponse
    {
        $today = Carbon::today()->toDateString();
        $dc = DailyChallenge::active()->forDate($today)->with('challenge.category')->first();

        if (!$dc) {
            return response()->json(['has_daily' => false, 'reason' => 'no_daily_challenge']);
        }

        $alreadyPlayed = $dc->guesses()->where('user_id', $request->user()->id)->exists();

        $challengeData = [
            'id'               => $dc->challenge->id,
            'title'            => $dc->challenge->title,
            'difficulty'       => $dc->challenge->difficulty,
            'hidden_image_url' => $dc->challenge->hidden_image_path
                ? asset('storage/' . $dc->challenge->hidden_image_path)
                : null,
            'category'         => $dc->challenge->category ? [
                'id'   => $dc->challenge->category->id,
                'name' => $dc->challenge->category->name,
                'slug' => $dc->challenge->category->slug,
            ] : null,
        ];

        return response()->json([
            'has_daily'       => true,
            'already_played'  => $alreadyPlayed,
            'daily_challenge' => [
                'id'             => $dc->id,
                'challenge_date' => $dc->challenge_date->toDateString(),
                'challenge'      => $challengeData,
                // SECURITY: never include ball_x_ratio, ball_y_ratio, reveal_image_url in today response
            ],
        ]);
    }

    // POST /api/daily/{dailyChallenge}/guess
    public function guess(Request $request, DailyChallenge $dailyChallenge): JsonResponse
    {
        if ($dailyChallenge->status !== 'active') {
            return response()->json(['message' => 'This daily challenge is not active.'], 422);
        }

        $data = $request->validate([
            'guess_x_ratio' => 'required|numeric|min:0|max:1',
            'guess_y_ratio' => 'required|numeric|min:0|max:1',
        ]);

        $userId = $request->user()->id;

        if ($dailyChallenge->guesses()->where('user_id', $userId)->exists()) {
            return response()->json(['message' => 'You have already played today\'s challenge.'], 422);
        }

        $challenge = $dailyChallenge->challenge;
        $result = $this->scoreService->calculate(
            $data['guess_x_ratio'],
            $data['guess_y_ratio'],
            $challenge->ball_x_ratio,
            $challenge->ball_y_ratio,
        );

        $guess = DailyChallengeGuess::create([
            'daily_challenge_id' => $dailyChallenge->id,
            'user_id'            => $userId,
            'guess_x_ratio'      => $data['guess_x_ratio'],
            'guess_y_ratio'      => $data['guess_y_ratio'],
            'distance'           => $result['distance'],
            'score'              => $result['score'],
            'submitted_at'       => now(),
        ]);

        return response()->json(['data' => $this->buildGuessResult($guess, $challenge)]);
    }

    // GET /api/daily/{dailyChallenge}/result
    public function result(Request $request, DailyChallenge $dailyChallenge): JsonResponse
    {
        $guess = $dailyChallenge->guesses()->where('user_id', $request->user()->id)->first();

        if (!$guess) {
            return response()->json(['message' => 'No guess found for this challenge.'], 404);
        }

        $challenge = $dailyChallenge->challenge;
        return response()->json(['data' => $this->buildGuessResult($guess, $challenge)]);
    }

    // GET /api/daily/leaderboard/weekly
    public function weeklyLeaderboard(Request $request): JsonResponse
    {
        $today = Carbon::today();
        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $weekEnd = $today->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();

        $entries = DailyChallengeGuess::whereHas('dailyChallenge', function ($q) use ($weekStart, $weekEnd) {
            $q->whereBetween('challenge_date', [$weekStart, $weekEnd]);
        })
        ->with('user')
        ->get()
        ->groupBy('user_id')
        ->map(function ($guesses, $userId) {
            $user = $guesses->first()->user;
            return [
                'user_id'           => $userId,
                'username'          => $user->username,
                'name'              => $user->name,
                'total_score'       => $guesses->sum('score'),
                'challenges_played' => $guesses->count(),
                'avg_score'         => round($guesses->avg('score'), 1),
            ];
        })
        ->sortByDesc('total_score')
        ->values()
        ->map(function ($entry, $index) use ($request) {
            return array_merge($entry, [
                'rank'            => $index + 1,
                'is_current_user' => $entry['user_id'] === $request->user()->id,
            ]);
        });

        return response()->json([
            'data'       => $entries,
            'week_start' => $weekStart,
            'week_end'   => $weekEnd,
        ]);
    }

    // GET /api/daily/stats
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();

        $streaks = $this->streakService->getStreakForUser($user);
        $currentStreak = $streaks['current'];
        $bestStreak = $streaks['best'];

        $allGuesses = DailyChallengeGuess::where('user_id', $user->id)->get();

        $today = Carbon::today();
        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $weekEnd = $today->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();

        $weeklyRank = $this->getWeeklyRank($user->id, $weekStart, $weekEnd);

        return response()->json([
            'current_streak' => $currentStreak,
            'best_streak'    => $bestStreak,
            'total_played'   => $allGuesses->count(),
            'average_score'  => $allGuesses->count() > 0 ? round($allGuesses->avg('score'), 1) : 0,
            'best_score'     => $allGuesses->max('score') ?? 0,
            'weekly_rank'    => $weeklyRank,
        ]);
    }

    private function buildGuessResult(DailyChallengeGuess $guess, Challenge $challenge): array
    {
        return [
            'id'               => $guess->id,
            'score'            => $guess->score,
            'distance'         => $guess->distance,
            'guess_x_ratio'    => $guess->guess_x_ratio,
            'guess_y_ratio'    => $guess->guess_y_ratio,
            'ball_x_ratio'     => $challenge->ball_x_ratio,
            'ball_y_ratio'     => $challenge->ball_y_ratio,
            'reveal_image_url' => $challenge->original_image_path
                ? asset('storage/' . $challenge->original_image_path)
                : null,
        ];
    }

    private function getWeeklyRank(int $userId, string $weekStart, string $weekEnd): ?int
    {
        $scores = DailyChallengeGuess::whereHas('dailyChallenge', function ($q) use ($weekStart, $weekEnd) {
            $q->whereBetween('challenge_date', [$weekStart, $weekEnd]);
        })
        ->selectRaw('user_id, SUM(score) as total_score')
        ->groupBy('user_id')
        ->orderByDesc('total_score')
        ->pluck('total_score', 'user_id');

        if (!$scores->has($userId)) return null;

        $rank = 1;
        foreach ($scores as $uid => $score) {
            if ($uid === $userId) return $rank;
            $rank++;
        }
        return null;
    }
}
