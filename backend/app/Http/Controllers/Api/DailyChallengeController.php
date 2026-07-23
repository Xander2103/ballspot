<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyChallenge;
use App\Models\Challenge;
use App\Models\DailyChallengeGuess;
use App\Models\Sport;
use App\Http\Resources\BadgeResource;
use App\Services\BadgeService;
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
        private BadgeService $badgeService,
        private \App\Services\PlayerRankService $rankService,
        private \App\Services\XpService $xpService,
        private \App\Services\CompetitionPeriodService $period,
        private \App\Services\CompetitionStandingsService $standings,
    ) {}

    /** Award any newly-reached daily-streak milestone bonuses (once each). */
    private function awardStreakXp(\App\Models\User $user): void
    {
        $current = $this->streakService->getStreakForUser($user)['current'];
        foreach ((array) config('ballspot.xp.streak') as $days => $bonus) {
            if ($current >= (int) $days) {
                $this->xpService->awardXp(
                    $user,
                    \App\Models\XpEvent::SOURCE_STREAK_BONUS,
                    (int) $days,
                    (int) $bonus,
                    "{$days}-day streak bonus",
                    ['streak_days' => (int) $days],
                );
            }
        }
    }

    // GET /api/daily/today
    public function today(Request $request): JsonResponse
    {
        // Which sport the player wants today: explicit ?sport=slug wins, then the
        // user's saved preference, then football (keeps existing football-first
        // behaviour for users who have not picked a sport).
        $requestedSlug = $request->query('sport')
            ?: ($request->user()->preferredSport?->slug ?? 'football');
        $requestedSport = Sport::where('slug', $requestedSlug)->first();

        $today = Carbon::today()->toDateString();
        $dc = DailyChallenge::active()->forDate($today)->with(['challenge.category', 'challenge.sport', 'challenge.tags'])->first();

        if (!$dc) {
            return response()->json($this->noDaily($requestedSport));
        }

        // Guard: linked challenge was deleted or archived after scheduling
        if (!$dc->challenge || $dc->challenge->status === 'archived') {
            return response()->json($this->noDaily($requestedSport));
        }

        // Never silently show a different sport than the one requested. Today's
        // challenge belongs to exactly one sport; if it does not match, report a
        // clean "no daily for this sport" so the app can offer football instead.
        if ($dc->challenge->sport && $dc->challenge->sport->slug !== $requestedSlug) {
            return response()->json($this->noDaily($requestedSport));
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
            'sport'            => $dc->challenge->sport ? [
                'slug'          => $dc->challenge->sport->slug,
                'name'          => $dc->challenge->sport->name,
                'emoji'         => $dc->challenge->sport->emoji,
                'primary_color' => $dc->challenge->sport->primary_color,
            ] : null,
            'tags'             => $dc->challenge->tags->map(fn($t) => [
                'name' => $t->name,
                'slug' => $t->slug,
                'type' => $t->type,
            ])->values(),
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

    /**
     * Standard "no daily challenge for this sport" payload, including the
     * requested sport badge so the app can render an on-brand empty state
     * ("No daily challenge for Tennis today. Try Football.").
     */
    private function noDaily(?Sport $sport): array
    {
        return [
            'has_daily' => false,
            'reason'    => 'no_daily_challenge',
            'sport'     => $sport ? [
                'slug'          => $sport->slug,
                'name'          => $sport->name,
                'emoji'         => $sport->emoji,
                'primary_color' => $sport->primary_color,
            ] : null,
        ];
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

        $user     = $request->user();
        $xpBefore = $this->xpService->getTotalXp($user);

        $guess = DailyChallengeGuess::create([
            'daily_challenge_id' => $dailyChallenge->id,
            'user_id'            => $userId,
            'guess_x_ratio'      => $data['guess_x_ratio'],
            'guess_y_ratio'      => $data['guess_y_ratio'],
            'distance'           => $result['distance'],
            'score'              => $result['score'],
            'submitted_at'       => now(),
        ]);

        // XP: the guess score (deduped so reopening the result never re-awards).
        $this->xpService->awardXp(
            $user,
            \App\Models\XpEvent::SOURCE_DAILY_GUESS,
            $guess->id,
            (int) $guess->score,
            'Daily challenge completed',
            ['daily_challenge_id' => $dailyChallenge->id],
        );

        // Award any newly-earned virtual trophies (idempotent; grants badge XP).
        $newBadges = $this->badgeService->evaluateDailyGuess($user, $guess, $dailyChallenge);

        // Streak milestone bonuses (once each).
        $this->awardStreakXp($user);

        $xpAfter  = $this->xpService->getTotalXp($user);
        $xpGained = $xpAfter - $xpBefore;
        $rankUp   = $this->rankService->rankUp($xpBefore, $xpAfter);

        $result = $this->buildGuessResult($guess, $challenge, $dailyChallenge);
        $result['new_badges'] = BadgeResource::collection($newBadges)->resolve();

        return response()->json([
            'data'          => $result,
            'rank_progress' => [
                'xp_gained' => $xpGained,
                'rank'      => $this->rankService->forXp($xpAfter),
            ],
            'rank_up'       => $rankUp,
        ]);
    }

    // GET /api/daily/{dailyChallenge}/result
    public function result(Request $request, DailyChallenge $dailyChallenge): JsonResponse
    {
        $guess = $dailyChallenge->guesses()->where('user_id', $request->user()->id)->first();

        if (!$guess) {
            return response()->json(['message' => 'No guess found for this challenge.'], 404);
        }

        $challenge = $dailyChallenge->challenge;
        return response()->json(['data' => $this->buildGuessResult($guess, $challenge, $dailyChallenge)]);
    }

    // GET /api/daily/leaderboard/weekly
    // (Route name kept for compatibility; the window now follows the configured
    // competition period — monthly by default.)
    public function weeklyLeaderboard(Request $request): JsonResponse
    {
        $periodStart = $this->period->start();
        $periodEnd   = $this->period->end();

        // Shared ranking logic (deterministic tie-break) — same as the close flow.
        $standings = $this->standings->forWindow($periodStart, $periodEnd);
        $users = \App\Models\User::whereIn('id', $standings->pluck('user_id'))
            ->get(['id', 'username', 'name'])
            ->keyBy('id');

        $entries = $standings->map(function ($row) use ($users, $request) {
            $user = $users->get($row['user_id']);
            return [
                'user_id'           => $row['user_id'],
                'username'          => $user?->username,
                'name'              => $user?->name,
                'total_score'       => $row['total_score'],
                'challenges_played' => $row['challenges_played'],
                'avg_score'         => $row['avg_score'],
                'rank'              => $row['placement'],
                'is_current_user'   => $row['user_id'] === $request->user()->id,
            ];
        });

        return response()->json([
            'data'         => $entries,
            // Backward-compatible keys (still the period boundaries).
            'week_start'   => $periodStart,
            'week_end'     => $periodEnd,
            'period_label' => $this->period->label(),
            // Canonical period descriptor (weekly | monthly) with start/end.
            'period'       => $this->period->toArray(),
            'meta'         => $this->buildLeaderboardMeta($entries, $request->user()->id),
        ]);
    }

    /**
     * Rank metadata for a ranked leaderboard collection (already sorted, 1-indexed rank).
     * Provides the "You are #X of Y / better than Z%" data plus a small nearby slice.
     */
    private function buildLeaderboardMeta($entries, int $userId): array
    {
        $total = $entries->count();
        $me    = $entries->firstWhere('user_id', $userId);

        if (!$me) {
            return [
                'total_players'          => $total,
                'current_user_rank'      => null,
                'current_user_score'     => null,
                'current_user_average'   => null,
                'better_than_percentage' => null,
                'top_users'              => $entries->take(3)->values(),
                'nearby_users'           => [],
            ];
        }

        $rank       = $me['rank'];
        $betterThan = $total > 1 ? (int) round(($total - $rank) / $total * 100) : 0;

        // Nearby slice: one above and one below the current user.
        $index  = $rank - 1;
        $start  = max(0, $index - 1);
        $nearby = $entries->slice($start, 3)->values();

        return [
            'total_players'          => $total,
            'current_user_rank'      => $rank,
            'current_user_score'     => $me['total_score'] ?? null,
            'current_user_average'   => $me['avg_score'] ?? null,
            'better_than_percentage' => $betterThan,
            'top_users'              => $entries->take(3)->values(),
            'nearby_users'           => $nearby,
        ];
    }

    // GET /api/daily/stats
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();

        $streaks = $this->streakService->getStreakForUser($user);
        $currentStreak = $streaks['current'];
        $bestStreak = $streaks['best'];

        $allGuesses = DailyChallengeGuess::where('user_id', $user->id)->get();

        // Rank within the current competition period (monthly by default).
        $weeklyRank = $this->getWeeklyRank($user->id, $this->period->start(), $this->period->end());

        return response()->json([
            'current_streak' => $currentStreak,
            'best_streak'    => $bestStreak,
            'total_played'   => $allGuesses->count(),
            'average_score'  => $allGuesses->count() > 0 ? round($allGuesses->avg('score'), 1) : 0,
            'best_score'     => $allGuesses->max('score') ?? 0,
            // Kept key name for BC; value now reflects the configured period.
            'weekly_rank'    => $weeklyRank,
            'period'         => $this->period->toArray(),
        ]);
    }

    private function buildGuessResult(DailyChallengeGuess $guess, Challenge $challenge, DailyChallenge $dailyChallenge): array
    {
        return array_merge([
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
        ], $this->buildRankMeta($dailyChallenge, $guess));
    }

    /**
     * Rank / percentile insight for a single daily challenge.
     * "Closer than X% of players" is derived from score (higher score = closer).
     * Simple aggregate count queries — fine for MVP scale.
     */
    private function buildRankMeta(DailyChallenge $dailyChallenge, DailyChallengeGuess $guess): array
    {
        $myScore = $guess->score;
        $total   = $dailyChallenge->guesses()->count();
        $rank    = $dailyChallenge->guesses()->where('score', '>', $myScore)->count() + 1;
        $beaten  = $dailyChallenge->guesses()->where('score', '<', $myScore)->count();

        // Percentage of the field this player scored strictly better than.
        $betterThan = $total > 1 ? (int) round($beaten / $total * 100) : 0;

        return [
            'rank'                   => $rank,
            'total_players'          => $total,
            'better_than_percentage' => $betterThan,
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
