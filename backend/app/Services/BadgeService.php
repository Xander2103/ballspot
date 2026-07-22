<?php

namespace App\Services;

use App\Models\Badge;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeGuess;
use App\Models\Guess;
use App\Models\League;
use App\Models\User;
use App\Models\XpEvent;
use Carbon\Carbon;
use Illuminate\Database\QueryException;

/**
 * Awards virtual trophies/medals. All awarding is idempotent — a badge is
 * earned at most once per user (enforced by the user_badges unique index).
 *
 * NOTE (MVP): rank-based badges (top 10%, daily champion) are evaluated from
 * the standings at the moment the guess is submitted, so early players are
 * compared against a smaller field. This is an accepted MVP simplification.
 */
class BadgeService
{
    /** Score at or above which a guess counts as "near perfect". */
    private const PERFECT_SCORE = 95;

    /** Football challenges played before earning the expert badge. */
    private const FOOTBALL_EXPERT_PLAYS = 25;

    public function __construct(
        private DailyStreakService $streakService,
        private XpService $xpService,
    ) {}

    /**
     * Award a badge by code if the user does not already have it.
     * Returns the freshly-awarded Badge, or null if unknown/already earned.
     */
    public function award(User $user, string $code, array $context = []): ?Badge
    {
        $badge = Badge::where('code', $code)->first();
        if (!$badge) {
            return null;
        }
        if ($user->badges()->where('badges.id', $badge->id)->exists()) {
            return null;
        }

        try {
            $user->badges()->attach($badge->id, [
                'earned_at'  => now(),
                'context'    => empty($context) ? null : json_encode($context),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            return null; // Lost a race on the unique index — treat as already earned.
        }

        // Bonus XP for the unlock (once per badge per user; deduped by the ledger).
        $this->xpService->awardXp(
            $user,
            XpEvent::SOURCE_BADGE_UNLOCK,
            $badge->id,
            $this->badgeXp($badge),
            'Badge unlocked: ' . $badge->name,
            ['badge_code' => $badge->code, 'rarity' => $badge->rarity],
        );

        return $badge;
    }

    /** XP bonus for unlocking a badge, keyed by rarity (config-driven). */
    private function badgeXp(Badge $badge): int
    {
        $amounts = config('ballspot.xp.badge');
        return (int) ($amounts[$badge->rarity] ?? $amounts['default']);
    }

    /**
     * Evaluate and award all badges relevant after a daily challenge guess.
     * Returns the list of newly-earned badges (for "New badge unlocked!" UI).
     *
     * @return Badge[]
     */
    public function evaluateDailyGuess(User $user, DailyChallengeGuess $guess, DailyChallenge $dailyChallenge): array
    {
        $context = ['daily_challenge_id' => $dailyChallenge->id, 'score' => $guess->score];
        $awarded = [];

        if ($this->totalGuessCount($user) <= 1) {
            $awarded[] = $this->award($user, 'first_guess', $context);
        }
        if (DailyChallengeGuess::where('user_id', $user->id)->count() <= 1) {
            $awarded[] = $this->award($user, 'first_daily', $context);
        }

        $awarded = array_merge($awarded, $this->evaluateSport($user, $dailyChallenge->challenge?->sport?->slug));

        if ($guess->score >= self::PERFECT_SCORE) {
            $awarded[] = $this->award($user, 'perfect_guess', $context);
        }

        // Rank within this daily challenge (snapshot at submission time).
        $total = $dailyChallenge->guesses()->count();
        $rank  = $dailyChallenge->guesses()->where('score', '>', $guess->score)->count() + 1;

        if ($total >= 3 && $rank === 1) {
            $awarded[] = $this->award($user, 'daily_champion', array_merge($context, ['rank' => $rank, 'total' => $total]));
        }
        if ($total >= 10 && $rank <= (int) ceil($total * 0.1)) {
            $awarded[] = $this->award($user, 'top_10_percent_daily', array_merge($context, ['rank' => $rank, 'total' => $total]));
        }

        $awarded = array_merge($awarded, $this->evaluateStreak($user));
        $awarded = array_merge($awarded, $this->evaluateWeeklyTop10($user));

        return $this->clean($awarded);
    }

    /**
     * Evaluate and award badges after a tournament round guess.
     *
     * @return Badge[]
     */
    public function evaluateTournamentGuess(User $user, Guess $guess): array
    {
        $context = ['guess_id' => $guess->id, 'score' => $guess->score];
        $awarded = [];

        if ($this->totalGuessCount($user) <= 1) {
            $awarded[] = $this->award($user, 'first_guess', $context);
        }

        $sportSlug = $guess->round?->challenge?->sport?->slug;
        $awarded = array_merge($awarded, $this->evaluateSport($user, $sportSlug));

        if ($guess->score >= self::PERFECT_SCORE) {
            $awarded[] = $this->award($user, 'perfect_guess', $context);
        }

        return $this->clean($awarded);
    }

    /**
     * Award the tournament winner badge. `evaluateTournamentWin` is safe to call
     * whenever a winner is determined; it no-ops if already awarded.
     *
     * @return Badge[]
     */
    public function evaluateTournamentWin(User $user, League $league): array
    {
        return $this->clean([
            $this->award($user, 'tournament_winner', ['league_id' => $league->id]),
        ]);
    }

    /** @return Badge[] */
    private function evaluateSport(User $user, ?string $sportSlug): array
    {
        if ($sportSlug !== 'football') {
            return []; // Only football badges exist for now.
        }

        $awarded = [];
        $plays   = $this->footballPlayCount($user);

        if ($plays >= 1) {
            $awarded[] = $this->award($user, 'football_rookie');
        }
        if ($plays >= self::FOOTBALL_EXPERT_PLAYS) {
            $awarded[] = $this->award($user, 'football_expert');
        }

        return $awarded;
    }

    /** @return Badge[] */
    private function evaluateStreak(User $user): array
    {
        $current = $this->streakService->getStreakForUser($user)['current'];
        $awarded = [];

        if ($current >= 7) {
            $awarded[] = $this->award($user, 'seven_day_streak');
        }
        if ($current >= 30) {
            $awarded[] = $this->award($user, 'thirty_day_streak');
        }

        return $awarded;
    }

    /** @return Badge[] */
    private function evaluateWeeklyTop10(User $user): array
    {
        $today     = Carbon::today();
        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $weekEnd   = $today->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();

        $scores = DailyChallengeGuess::whereHas('dailyChallenge', function ($q) use ($weekStart, $weekEnd) {
            $q->whereBetween('challenge_date', [$weekStart, $weekEnd]);
        })
            ->selectRaw('user_id, SUM(score) as total_score')
            ->groupBy('user_id')
            ->orderByDesc('total_score')
            ->pluck('user_id')
            ->values();

        // Only meaningful with a real field of players.
        if ($scores->count() < 10) {
            return [];
        }

        $rank = $scores->search($user->id);
        if ($rank !== false && $rank < 10) {
            return $this->clean([$this->award($user, 'weekly_top_10')]);
        }

        return [];
    }

    private function totalGuessCount(User $user): int
    {
        return DailyChallengeGuess::where('user_id', $user->id)->count()
            + Guess::where('user_id', $user->id)->count();
    }

    private function footballPlayCount(User $user): int
    {
        $daily = DailyChallengeGuess::where('user_id', $user->id)
            ->whereHas('dailyChallenge.challenge.sport', fn($q) => $q->where('slug', 'football'))
            ->count();

        $tournament = Guess::where('user_id', $user->id)
            ->whereHas('round.challenge.sport', fn($q) => $q->where('slug', 'football'))
            ->count();

        return $daily + $tournament;
    }

    /**
     * Drop nulls (already-earned / unknown) and return a clean list of Badges.
     *
     * @param  array<Badge|null>  $awarded
     * @return Badge[]
     */
    private function clean(array $awarded): array
    {
        return array_values(array_filter($awarded));
    }
}
