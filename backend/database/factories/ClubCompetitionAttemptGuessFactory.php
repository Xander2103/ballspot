<?php

namespace Database\Factories;

use App\Models\Challenge;
use App\Models\ClubCompetitionAttempt;
use App\Models\ClubCompetitionAttemptGuess;
use App\Models\Sport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClubCompetitionAttemptGuess>
 */
class ClubCompetitionAttemptGuessFactory extends Factory
{
    protected $model = ClubCompetitionAttemptGuess::class;

    public function definition(): array
    {
        return [
            'attempt_id'   => ClubCompetitionAttempt::factory(),
            'challenge_id' => fn () => Challenge::create([
                'sport_id'          => Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football'])->id,
                'title'             => 'Guess challenge ' . fake()->unique()->numberBetween(1, 999999),
                'hidden_image_path' => 'challenges/hidden/test.jpg',
                'ball_x_ratio'      => 0.5,
                'ball_y_ratio'      => 0.5,
                'difficulty'        => 'easy',
                'status'            => 'active',
            ])->id,
            'position'     => 0,
            'guess_x'      => 0.5,
            'guess_y'      => 0.5,
            'distance'     => 0,
            'score'        => 100,
            'submitted_at' => now(),
        ];
    }
}
