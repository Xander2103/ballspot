<?php

namespace Database\Seeders;

use App\Models\Challenge;
use App\Models\Sport;
use Illuminate\Database\Seeder;

class ChallengeSeeder extends Seeder
{
    public function run(): void
    {
        $sport = Sport::where('slug', 'football')->first();

        $challenges = [
            ['title' => 'Corner Kick',  'ball_x_ratio' => 0.12, 'ball_y_ratio' => 0.85, 'difficulty' => 'easy'],
            ['title' => 'Center Field', 'ball_x_ratio' => 0.50, 'ball_y_ratio' => 0.50, 'difficulty' => 'easy'],
            ['title' => 'Penalty Spot', 'ball_x_ratio' => 0.50, 'ball_y_ratio' => 0.78, 'difficulty' => 'medium'],
            ['title' => 'Crowd Scene',  'ball_x_ratio' => 0.33, 'ball_y_ratio' => 0.60, 'difficulty' => 'hard'],
            ['title' => 'Goal Line',    'ball_x_ratio' => 0.72, 'ball_y_ratio' => 0.92, 'difficulty' => 'hard'],
            ['title' => 'Kick Off',     'ball_x_ratio' => 0.50, 'ball_y_ratio' => 0.48, 'difficulty' => 'medium'],
        ];

        foreach ($challenges as $c) {
            Challenge::firstOrCreate(
                ['title' => $c['title'], 'sport_id' => $sport->id],
                [
                    'hidden_image_path' => 'challenges/hidden/placeholder.jpg',
                    'ball_x_ratio'      => $c['ball_x_ratio'],
                    'ball_y_ratio'      => $c['ball_y_ratio'],
                    'difficulty'        => $c['difficulty'],
                    'status'            => 'active',
                ]
            );
        }
    }
}
