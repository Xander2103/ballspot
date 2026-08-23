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
    /** Football challenges played before earning the expert badge. */
    private const FOOTBALL_EXPERT_PLAYS = 25;

    /**
     * v1.8.8 rank-milestone badges: minimum rank level => badge code.
     * Levels: 3 Pro, 5 Legend, 6 Ball Master (config ballspot.ranks).
     */
    private const RANK_BADGES = [
        3 => 'rising_star',
        5 => 'golden_touch',
        6 => 'legend_status',
    ];

    public function __construct(
        private DailyStreakService $streakService,
        private XpService $xpService,
        private ScoreService $scoreService,
        private PlayerRankService $rankService,
    ) {}

    /**
     * Award any rank-milestone badges the user's current level qualifies for.
     * Idempotent; one XP-ledger read per call. Called from the XP-earning
     * evaluation paths AFTER the triggering XP is in the ledger. Badge-unlock
     * bonus XP can itself cross a threshold — we deliberately don't loop; the
     * next XP-earning action catches up.
     *
     * @return Badge[]
     */
    public function evaluateRankBadges(User $user): array
    {
        $level = (int) ($this->rankService->forUser($user)['level'] ?? 1);

        $awarded = [];
        foreach (self::RANK_BADGES as $minLevel => $code) {
            if ($level >= $minLevel && ($badge = $this->award($user, $code, ['level' => $level]))) {
                $awarded[] = $badge;
            }
        }

        return $awarded;
    }

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
            $awarded[] = $this->award($user, 'first_daily_win', $context); // canonical (v1.7.4)
        }

        $awarded = array_merge($awarded, $this->evaluateSport($user, $dailyChallenge->challenge?->sport?->slug));
        $awarded = array_merge($awarded, $this->evaluateScore($user, (int) $guess->score, $context));

        // Rank within this daily challenge (snapshot at submission time).
        $total = $dailyChallenge->guesses()->count();
        $rank  = $dailyChallenge->guesses()->where('score', '>', $guess->score)->count() + 1;

        if ($total >= 3 && $rank === 1) {
            $awarded[] = $this->award($user, 'daily_champion', array_merge($context, ['rank' => $rank, 'total' => $total]));
        }
        if ($total >= 10 && $rank <= (int) ceil($total * 0.1)) {
            $meta = array_merge($context, ['rank' => $rank, 'total' => $total]);
            $awarded[] = $this->award($user, 'top_10_percent_daily', $meta);
            $awarded[] = $this->award($user, 'top_10_daily', $meta); // canonical (v1.7.4)
        }

        $awarded = array_merge($awarded, $this->evaluateStreak($user));
        $awarded = array_merge($awarded, $this->evaluateWeeklyTop10($user));

        // v1.8.6: play 14 daily challenges (count includes this guess).
        if (DailyChallengeGuess::where('user_id', $user->id)->count() >= 14) {
            $awarded[] = $this->award($user, 'daily_loyalist', $context);
        }

        $awarded = array_merge($awarded, $this->evaluateRankBadges($user));

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
        $awarded = array_merge($awarded, $this->evaluateScore($user, (int) $guess->score, $context));
        $awarded = array_merge($awarded, $this->evaluateRankBadges($user));

        return $this->clean($awarded);
    }

    /**
     * Score-based skill badges, shared by daily & tournament guesses so the
     * perfect / almost-perfect thresholds live in exactly one place.
     *
     * @return array<Badge|null>
     */
    private function evaluateScore(User $user, int $score, array $context): array
    {
        $awarded = [];

        if ($this->scoreService->isPerfectScore($score)) {
            $awarded[] = $this->award($user, 'perfect_picker', $context); // canonical (v1.7.4)
        }
        if ($this->scoreService->isAlmostPerfect($score)) {
            $awarded[] = $this->award($user, 'almost_perfect', $context); // canonical (v1.7.4)
            $awarded[] = $this->award($user, 'perfect_guess', $context);  // legacy near-perfect
        }

        // v1.8.6 Sharp Scorer: ten guesses scoring 90+. The earned pre-check
        // keeps the two COUNT queries off the hot path once unlocked. Pack
        // guesses are not counted (they evaluate at pack completion, not here).
        if ($score >= 90 && !$user->badges()->where('code', 'sharp_scorer')->exists()) {
            $highScoring = $this->highScoringGuessCount($user);
            if ($highScoring >= 10) {
                $awarded[] = $this->award($user, 'sharp_scorer', array_merge($context, ['high_scoring_guesses' => $highScoring]));
            }
        }

        return $awarded;
    }

    /** Daily + tournament guesses with a score of 90+ (pack guesses excluded). */
    private function highScoringGuessCount(User $user): int
    {
        return DailyChallengeGuess::where('user_id', $user->id)->where('score', '>=', 90)->count()
            + Guess::where('user_id', $user->id)->where('score', '>=', 90)->count();
    }

    /**
     * Friend-count badges, called for BOTH parties when a request is accepted.
     * Counts owned friendship rows (one per direction, so this is "current
     * friends"). Idempotent via award().
     *
     * @return Badge[]
     */
    public function evaluateFriendAccepted(User $user): array
    {
        $count = \App\Models\Friendship::where('user_id', $user->id)->count();
        $awarded = [];

        if ($count >= 1) {
            $awarded[] = $this->award($user, 'social_starter');
        }
        if ($count >= 5) {
            $awarded[] = $this->award($user, 'friendly_five', ['friends' => $count]);
        }

        return $this->clean($awarded);
    }

    /**
     * Called when a user creates a tournament. Idempotent via award().
     *
     * @return Badge[]
     */
    public function evaluateTournamentCreated(User $user): array
    {
        return $this->clean([$this->award($user, 'host_starter')]);
    }

    /**
     * One-off backfill for the v1.8.6 count-based badges: awards any of them a
     * user's historical stats already qualify for. Pure counts only — never
     * touches streak/rank/score-event badges. Idempotent via award().
     *
     * @return Badge[]
     */
    public function backfillCountBadges(User $user): array
    {
        $awarded = $this->evaluateFriendAccepted($user);

        if (League::where('owner_user_id', $user->id)->exists()) {
            $awarded = array_merge($awarded, $this->evaluateTournamentCreated($user));
        }

        $finishes = \App\Models\TournamentFinish::where('user_id', $user->id)->count();
        if ($finishes >= 5) {
            $awarded[] = $this->award($user, 'tournament_regular', ['finishes' => $finishes]);
        }

        $highScoring = $this->highScoringGuessCount($user);
        if ($highScoring >= 10) {
            $awarded[] = $this->award($user, 'sharp_scorer', ['high_scoring_guesses' => $highScoring]);
        }

        $distinctPacks = \App\Models\PackAttempt::where('user_id', $user->id)
            ->where('status', \App\Models\PackAttempt::STATUS_COMPLETED)
            ->distinct('challenge_pack_id')
            ->count('challenge_pack_id');
        if ($distinctPacks >= 3) {
            $awarded[] = $this->award($user, 'pack_explorer', ['distinct_packs' => $distinctPacks]);
        }

        if (DailyChallengeGuess::where('user_id', $user->id)->count() >= 14) {
            $awarded[] = $this->award($user, 'daily_loyalist');
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

    /**
     * Badges for a final tournament placement: the winner badge (1st) and the
     * podium badge (top 3). Idempotent — safe to call when completion replays.
     *
     * @return Badge[]
     */
    public function evaluateTournamentFinish(User $user, League $league, int $placement): array
    {
        $awarded = [];

        if ($placement === 1) {
            $awarded[] = $this->award($user, 'tournament_winner', ['league_id' => $league->id]);
        }
        if ($placement <= 3) {
            $awarded[] = $this->award($user, 'podium_finish', ['league_id' => $league->id, 'placement' => $placement]);
        }

        // v1.8.6: complete five tournaments (any placement). Cheap — the
        // tournament_finishes (user_id, placement) index covers this count.
        $finishes = \App\Models\TournamentFinish::where('user_id', $user->id)->count();
        if ($finishes >= 5) {
            $awarded[] = $this->award($user, 'tournament_regular', ['finishes' => $finishes]);
        }

        return $this->clean($awarded);
    }

    /**
     * Badges for a final placement in a CLOSED monthly competition period.
     * Weekly closes store finishes/XP but award no badges (the monthly_* set is
     * period-specific by design). Top-10% needs a real field (>= 10 players) —
     * mirroring the live weekly_top_10 guard — so tiny fields never trigger it.
     * Idempotent via award().
     *
     * @return Badge[]
     */
    public function evaluateCompetitionFinish(User $user, string $periodType, int $placement, int $totalPlayers, array $context = []): array
    {
        if ($periodType !== 'monthly') {
            return [];
        }

        $awarded = [];
        $context = array_merge($context, ['placement' => $placement, 'total_players' => $totalPlayers]);

        if ($placement === 1) {
            $awarded[] = $this->award($user, 'monthly_winner', $context);
        }
        if ($placement <= 3) {
            $awarded[] = $this->award($user, 'monthly_podium', $context);
        }
        if ($totalPlayers >= 10 && $placement <= (int) ceil($totalPlayers * 0.1)) {
            $awarded[] = $this->award($user, 'monthly_top_10', $context);
        }

        return $this->clean($awarded);
    }

    /**
     * Badges for completing a challenge pack. Called once when a pack attempt
     * completes. Idempotent (award() no-ops on already-earned):
     *   - first_pack_completed: any pack completion.
     *   - perfect_pack: every guess in the attempt was a perfect score.
     *   - pack_master: the user has completed 10+ packs.
     *
     * @return Badge[]
     */
    public function evaluatePackCompletion(User $user, \App\Models\PackAttempt $attempt): array
    {
        $awarded = [];
        $context = ['pack_attempt_id' => $attempt->id, 'challenge_pack_id' => $attempt->challenge_pack_id];

        $awarded[] = $this->award($user, 'first_pack_completed', $context);

        $guesses = $attempt->guesses;
        if ($guesses->isNotEmpty() && $guesses->every(fn ($g) => $this->scoreService->isPerfectScore((int) $g->score))) {
            $awarded[] = $this->award($user, 'perfect_pack', $context);
        }

        $completedPacks = \App\Models\PackAttempt::where('user_id', $user->id)
            ->where('status', \App\Models\PackAttempt::STATUS_COMPLETED)
            ->count();
        if ($completedPacks >= 10) {
            $awarded[] = $this->award($user, 'pack_master', array_merge($context, ['completed_packs' => $completedPacks]));
        }

        // v1.8.6: complete three DIFFERENT packs (pack_master counts attempts).
        $distinctPacks = \App\Models\PackAttempt::where('user_id', $user->id)
            ->where('status', \App\Models\PackAttempt::STATUS_COMPLETED)
            ->distinct('challenge_pack_id')
            ->count('challenge_pack_id');
        if ($distinctPacks >= 3) {
            $awarded[] = $this->award($user, 'pack_explorer', array_merge($context, ['distinct_packs' => $distinctPacks]));
        }

        $awarded = array_merge($awarded, $this->evaluateRankBadges($user));

        return $this->clean($awarded);
    }

    /** @return Badge[] */
    private function evaluateSport(User $user, ?string $sportSlug): array
    {
        if ($sportSlug === null) {
            return [];
        }

        if ($sportSlug !== 'football') {
            // First non-football challenge unlocks the multi-sport starter.
            // award() is idempotent, so only the first ever non-football play grants it.
            return $this->clean([$this->award($user, 'multi_sport_starter', ['sport' => $sportSlug])]);
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

        if ($current >= 3) {
            $awarded[] = $this->award($user, 'streak_3'); // canonical (v1.7.4)
        }
        if ($current >= 7) {
            $awarded[] = $this->award($user, 'seven_day_streak'); // legacy
            $awarded[] = $this->award($user, 'streak_7');         // canonical (v1.7.4)
        }
        if ($current >= 30) {
            $awarded[] = $this->award($user, 'thirty_day_streak'); // legacy
            $awarded[] = $this->award($user, 'streak_30');         // canonical (v1.7.4)
        }

        return $awarded;
    }

    /** @return Badge[] */
    private function evaluateWeeklyTop10(User $user): array
    {
        // Aggregate over the configured competition period (monthly by default)
        // so the top-finishers badge matches the leaderboard the user sees.
        $period    = app(\App\Services\CompetitionPeriodService::class);
        $weekStart = $period->start();
        $weekEnd   = $period->end();

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
