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
        $score = max(0, (int) round(100 - ($distance * 250)));
        return ['distance' => $distance, 'score' => $score];
    }
}
