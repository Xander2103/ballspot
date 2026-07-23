<?php

namespace App\Services;

use App\Models\DailyChallengeGuess;
use Illuminate\Support\Collection;

/**
 * Computes daily-challenge leaderboard standings for a date window. Single
 * source of truth shared by the live monthly/weekly leaderboard AND the
 * competition close flow, so both rank players identically.
 *
 * Tie handling (deterministic):
 *   1. total_score DESC
 *   2. earliest last-qualifying guess time ASC (reaching the total sooner wins)
 *   3. user_id ASC (final stable fallback)
 */
class CompetitionStandingsService
{
    /**
     * Ranked standings for [start, end] (inclusive, Y-m-d). Each row:
     * user_id, total_score, challenges_played, avg_score, last_at, placement.
     *
     * @return Collection<int, array>
     */
    public function forWindow(string $start, string $end): Collection
    {
        return DailyChallengeGuess::whereHas('dailyChallenge', function ($q) use ($start, $end) {
            $q->whereBetween('challenge_date', [$start, $end]);
        })
            ->get(['id', 'user_id', 'score', 'submitted_at'])
            ->groupBy('user_id')
            ->map(fn ($guesses, $userId) => [
                'user_id'           => (int) $userId,
                'total_score'       => (int) $guesses->sum('score'),
                'challenges_played' => $guesses->count(),
                'avg_score'         => round($guesses->avg('score'), 1),
                'last_at'           => $guesses->max('submitted_at'),
            ])
            ->sort($this->comparator())
            ->values()
            ->map(function ($row, $index) {
                $row['placement'] = $index + 1;
                return $row;
            });
    }

    /** Deterministic comparator (see class docblock). */
    private function comparator(): callable
    {
        return function (array $a, array $b): int {
            if ($a['total_score'] !== $b['total_score']) {
                return $b['total_score'] <=> $a['total_score']; // higher score first
            }
            if ($a['last_at'] != $b['last_at']) {
                return $a['last_at'] <=> $b['last_at'];          // reached total earlier first
            }
            return $a['user_id'] <=> $b['user_id'];              // stable fallback
        };
    }
}
