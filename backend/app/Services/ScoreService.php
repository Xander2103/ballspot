<?php
namespace App\Services;

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
