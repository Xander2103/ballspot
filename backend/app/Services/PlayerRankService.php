<?php

namespace App\Services;

use App\Models\DailyChallengeGuess;
use App\Models\Guess;
use App\Models\User;

/**
 * Personal player progression: rank / level / XP.
 *
 * This is DIFFERENT from leaderboard rank (position vs. other players). It is a
 * long-term personal ladder. XP currently equals a player's lifetime score
 * total = sum(tournament guess scores) + sum(daily challenge guess scores).
 * There is no XP ledger table yet — XP is derived on read.
 */
class PlayerRankService
{
    /** Lifetime XP for a user = tournament score + daily score. */
    public function totalXpForUser(User $user): int
    {
        $tournamentXp = (int) Guess::where('user_id', $user->id)->sum('score');
        $dailyXp      = (int) DailyChallengeGuess::where('user_id', $user->id)->sum('score');

        return $tournamentXp + $dailyXp;
    }

    /** Full rank payload for a user. */
    public function forUser(User $user): array
    {
        return $this->forXp($this->totalXpForUser($user));
    }

    /**
     * Resolve a rank payload for an arbitrary XP total. Pure/testable — no DB.
     */
    public function forXp(int $totalXp): array
    {
        $ranks = config('ballspot.ranks');

        $current      = $ranks[0];
        $currentIndex = 0;
        foreach ($ranks as $i => $rank) {
            if ($totalXp >= $rank['min_xp']) {
                $current      = $rank;
                $currentIndex = $i;
            }
        }

        $next  = $ranks[$currentIndex + 1] ?? null;
        $isMax = $next === null;

        if ($isMax) {
            $xpToNext    = null;
            $progressPct = 100;
        } else {
            $xpToNext = max(0, $next['min_xp'] - $totalXp);
            $span     = $next['min_xp'] - $current['min_xp'];
            $into     = $totalXp - $current['min_xp'];
            $progressPct = $span > 0 ? (int) round($into / $span * 100) : 0;
            $progressPct = max(0, min(100, $progressPct));
        }

        return [
            'name'                      => $current['name'],
            'level'                     => $current['level'],
            'total_xp'                  => $totalXp,
            'current_rank_min_xp'       => $current['min_xp'],
            'next_rank_name'            => $next['name'] ?? null,
            'next_rank_xp'              => $next['min_xp'] ?? null,
            'xp_to_next_rank'           => $xpToNext,
            'progress_to_next_rank_pct' => $progressPct,
            'is_max_rank'               => $isMax,
        ];
    }
}
