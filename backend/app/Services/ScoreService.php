<?php
namespace App\Services;

/**
 * Distance-based scoring. This is the "default" scoring profile shared by all
 * sports today.
 *
 * MULTI-SPORT FOUNDATION (TODO): sports may later need different scoring curves
 * (e.g. a small puck in hockey vs a large ball). Sport::$scoring_profile carries
 * the intended profile name ("default" for now). When more profiles are needed,
 * branch here on the profile (or resolve a strategy) rather than hardcoding —
 * the existing football behaviour must remain the "default" path unchanged.
 */
class ScoreService
{
    public function calculate(float $guessX, float $guessY, float $ballX, float $ballY): array
    {
        $dx = $guessX - $ballX;
        $dy = $guessY - $ballY;
        $distance = sqrt($dx * $dx + $dy * $dy);
        $score = max(0, (int) round($this->maxScore() - ($distance * 250)));
        return ['distance' => $distance, 'score' => $score];
    }

    /** The maximum achievable score (a perfect pick), config-driven. */
    public function maxScore(): int
    {
        return (int) config('ballspot.scoring.max_score', 100);
    }

    /** True when a guess is a perfect pick (exact maximum score / 100%). */
    public function isPerfectScore(int $score): bool
    {
        return $score >= $this->maxScore();
    }

    /** True when a guess is "almost perfect" (>= configured threshold, e.g. 95%). */
    public function isAlmostPerfect(int $score): bool
    {
        return $score >= (int) config('ballspot.scoring.almost_perfect_threshold', 95);
    }
}
