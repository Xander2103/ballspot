<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\XpEventResource;
use App\Models\Guess;
use App\Models\TournamentFinish;
use App\Services\DailyStreakService;
use App\Services\PlayerRankService;
use App\Services\XpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(
        private DailyStreakService $streakService,
        private PlayerRankService $rankService,
        private XpService $xpService,
    ) {}

    // GET /api/me/rank — personal rank/level/XP progression.
    public function rank(Request $request)
    {
        return response()->json(['rank' => $this->rankService->forUser($request->user())]);
    }

    // GET /api/me/xp-events — recent XP ledger activity + total + current rank.
    public function xpEvents(Request $request)
    {
        $user  = $request->user();
        $limit = min(50, max(1, (int) $request->integer('limit', 20)));

        return response()->json([
            'data'     => XpEventResource::collection($this->xpService->getRecentXpEvents($user, $limit)),
            'total_xp' => $this->rankService->totalXpForUser($user),
            'rank'     => $this->rankService->forUser($user),
        ]);
    }

    // GET /api/me/tournament-finishes — the user's tournament placements for the Trophy Room.
    public function tournamentFinishes(Request $request): JsonResponse
    {
        $finishes = TournamentFinish::where('user_id', $request->user()->id)
            ->with('league:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (TournamentFinish $f) => [
                'id'            => $f->id,
                'placement'     => $f->placement,
                'total_score'   => $f->total_score,
                'rounds_played' => $f->rounds_played,
                'total_players' => $f->metadata['total_players'] ?? null,
                'league'        => $f->league ? ['id' => $f->league->id, 'name' => $f->league->name] : null,
                'completed_at'  => $f->created_at?->toISOString(),
            ]);

        return response()->json(['data' => $finishes]);
    }

    // GET /api/me/pack-completions — completed challenge packs for the Trophy Room.
    public function packCompletions(Request $request): JsonResponse
    {
        $completions = \App\Models\PackAttempt::where('user_id', $request->user()->id)
            ->where('status', \App\Models\PackAttempt::STATUS_COMPLETED)
            ->with(['pack:id,name,slug', 'guesses:id,pack_attempt_id,score'])
            ->orderByDesc('completed_at')
            ->get()
            ->map(function (\App\Models\PackAttempt $a) {
                $count = $a->guesses->count();
                $perfect = $count > 0 && $a->guesses->every(
                    fn ($g) => (int) $g->score >= (int) config('ballspot.scoring.max_score', 100)
                );

                return [
                    'id'              => $a->id,
                    'pack'            => $a->pack ? ['id' => $a->pack->id, 'name' => $a->pack->name, 'slug' => $a->pack->slug] : null,
                    'total_score'     => $a->total_score,
                    'challenge_count' => $count,
                    'is_perfect'      => $perfect,
                    'completed_at'    => $a->completed_at?->toISOString(),
                ];
            });

        return response()->json(['data' => $completions]);
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
