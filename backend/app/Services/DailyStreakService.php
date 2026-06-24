<?php

namespace App\Services;

use App\Models\DailyChallengeGuess;
use App\Models\User;
use Carbon\Carbon;

class DailyStreakService
{
    public function getStreakForUser(User $user): array
    {
        $guessDates = DailyChallengeGuess::where('user_id', $user->id)
            ->join('daily_challenges', 'daily_challenges.id', '=', 'daily_challenge_guesses.daily_challenge_id')
            ->orderBy('daily_challenges.challenge_date', 'desc')
            ->pluck('daily_challenges.challenge_date')
            ->map(fn($d) => Carbon::parse($d)->toDateString())
            ->unique()
            ->values()
            ->toArray();

        return [
            'current' => $this->currentStreak($guessDates),
            'best'    => $this->bestStreak($guessDates),
        ];
    }

    private function currentStreak(array $dates): int
    {
        if (empty($dates)) return 0;
        $today = Carbon::today()->toDateString();
        $yesterday = Carbon::yesterday()->toDateString();
        if ($dates[0] !== $today && $dates[0] !== $yesterday) return 0;
        $streak = 0;
        $expected = $dates[0] === $today ? $today : $yesterday;
        foreach ($dates as $date) {
            if ($date === $expected) {
                $streak++;
                $expected = Carbon::parse($expected)->subDay()->toDateString();
            } else {
                break;
            }
        }
        return $streak;
    }

    private function bestStreak(array $dates): int
    {
        if (empty($dates)) return 0;
        $sorted = array_reverse($dates); // oldest first
        $best = 1;
        $current = 1;
        for ($i = 1; $i < count($sorted); $i++) {
            $prev = Carbon::parse($sorted[$i - 1]);
            $curr = Carbon::parse($sorted[$i]);
            if ($curr->diffInDays($prev) === 1) {
                $current++;
                if ($current > $best) $best = $current;
            } else {
                $current = 1;
            }
        }
        return $best;
    }
}
