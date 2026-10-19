<?php

namespace App\Support;

use App\Models\Challenge;

/**
 * The `result` block of a scored guess (pack and club competition guesses):
 * score, distance, the guess, and — safe only AFTER the guess is scored —
 * the ball position, the reveal image and the challenge's sport marker.
 * Extracted from PackPlayController::guess() without changing its output.
 */
final class ChallengeReveal
{
    public static function result(Challenge $challenge, int $score, float $distance, float $guessedX, float $guessedY): array
    {
        $challenge->loadMissing('sport');

        return [
            'score'            => $score,
            'distance'         => $distance,
            'guessed_x'        => $guessedX,
            'guessed_y'        => $guessedY,
            // Safe to reveal AFTER the guess is scored.
            'ball_x_ratio'     => (float) $challenge->ball_x_ratio,
            'ball_y_ratio'     => (float) $challenge->ball_y_ratio,
            'reveal_image_url' => ChallengeImageUrl::original($challenge),
            // Marker object on the result screen (the CHALLENGE's sport —
            // a Mixed Sports pack has no single sport).
            'sport'            => $challenge->sport ? [
                'slug'          => $challenge->sport->slug,
                'name'          => $challenge->sport->name,
                'emoji'         => $challenge->sport->emoji,
                'object_name'   => $challenge->sport->object_name,
                'primary_color' => $challenge->sport->primary_color,
            ] : null,
        ];
    }
}
