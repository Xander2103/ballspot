<?php

namespace Database\Seeders;

use App\Models\Challenge;
use App\Models\Sport;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class ChallengeSeeder extends Seeder
{
    public function run(): void
    {
        $sport = Sport::where('slug', 'football')->first();

        $challenges = [
            ['title' => 'Corner Kick',  'slug' => 'corner-kick',  'ball_x_ratio' => 0.12, 'ball_y_ratio' => 0.85, 'difficulty' => 'easy'],
            ['title' => 'Center Field', 'slug' => 'center-field', 'ball_x_ratio' => 0.50, 'ball_y_ratio' => 0.50, 'difficulty' => 'easy'],
            ['title' => 'Penalty Spot', 'slug' => 'penalty-spot', 'ball_x_ratio' => 0.50, 'ball_y_ratio' => 0.78, 'difficulty' => 'medium'],
            ['title' => 'Crowd Scene',  'slug' => 'crowd-scene',  'ball_x_ratio' => 0.33, 'ball_y_ratio' => 0.60, 'difficulty' => 'hard'],
            ['title' => 'Goal Line',    'slug' => 'goal-line',    'ball_x_ratio' => 0.72, 'ball_y_ratio' => 0.92, 'difficulty' => 'hard'],
            ['title' => 'Kick Off',     'slug' => 'kick-off',     'ball_x_ratio' => 0.50, 'ball_y_ratio' => 0.48, 'difficulty' => 'medium'],
        ];

        foreach ($challenges as $c) {
            $svgSource = public_path("demo/challenges/{$c['slug']}.svg");
            $storagePath = "challenges/hidden/{$c['slug']}.svg";

            if (file_exists($svgSource)) {
                Storage::disk('public')->put($storagePath, file_get_contents($svgSource));
            }

            Challenge::firstOrCreate(
                ['title' => $c['title'], 'sport_id' => $sport->id],
                [
                    'hidden_image_path' => $storagePath,
                    'ball_x_ratio'      => $c['ball_x_ratio'],
                    'ball_y_ratio'      => $c['ball_y_ratio'],
                    'difficulty'        => $c['difficulty'],
                    'status'            => 'active',
                ]
            );
        }
    }
}
