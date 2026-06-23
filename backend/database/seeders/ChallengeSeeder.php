<?php

namespace Database\Seeders;

use App\Models\Challenge;
use App\Models\ChallengeCategory;
use App\Models\Sport;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class ChallengeSeeder extends Seeder
{
    public function run(): void
    {
        $sport = Sport::where('slug', 'football')->first();
        $generalCategory = ChallengeCategory::where('sport_id', $sport->id)->where('slug', 'general')->first();

        $challenges = [
            ['title' => 'Corner Kick',  'slug' => 'corner-kick',  'ball_x_ratio' => 0.12, 'ball_y_ratio' => 0.85, 'difficulty' => 'easy'],
            ['title' => 'Center Field', 'slug' => 'center-field', 'ball_x_ratio' => 0.50, 'ball_y_ratio' => 0.50, 'difficulty' => 'easy'],
            ['title' => 'Penalty Spot', 'slug' => 'penalty-spot', 'ball_x_ratio' => 0.50, 'ball_y_ratio' => 0.78, 'difficulty' => 'medium'],
            ['title' => 'Crowd Scene',  'slug' => 'crowd-scene',  'ball_x_ratio' => 0.33, 'ball_y_ratio' => 0.60, 'difficulty' => 'hard'],
            ['title' => 'Goal Line',    'slug' => 'goal-line',    'ball_x_ratio' => 0.72, 'ball_y_ratio' => 0.92, 'difficulty' => 'hard'],
            ['title' => 'Kick Off',     'slug' => 'kick-off',     'ball_x_ratio' => 0.50, 'ball_y_ratio' => 0.48, 'difficulty' => 'medium'],
        ];

        foreach ($challenges as $c) {
            // Hidden image — prefer demo/challenges/hidden/{slug}.* over legacy demo/challenges/{slug}.svg
            $hiddenPath = "challenges/hidden/{$c['slug']}.svg";
            foreach (['jpg', 'jpeg', 'png', 'svg', 'webp'] as $ext) {
                $src = public_path("demo/challenges/hidden/{$c['slug']}.{$ext}");
                if (file_exists($src)) {
                    $hiddenPath = "challenges/hidden/{$c['slug']}.{$ext}";
                    Storage::disk('public')->put($hiddenPath, file_get_contents($src));
                    break;
                }
            }
            // Fallback: legacy location demo/challenges/{slug}.svg
            if (!Storage::disk('public')->exists($hiddenPath)) {
                $legacySrc = public_path("demo/challenges/{$c['slug']}.svg");
                if (file_exists($legacySrc)) {
                    Storage::disk('public')->put($hiddenPath, file_get_contents($legacySrc));
                }
            }

            // Reveal image — optional, from demo/challenges/reveal/
            $revealPath = null;
            foreach (['jpg', 'jpeg', 'png', 'svg', 'webp'] as $ext) {
                $src = public_path("demo/challenges/reveal/{$c['slug']}.{$ext}");
                if (file_exists($src)) {
                    $revealPath = "challenges/original/{$c['slug']}.{$ext}";
                    Storage::disk('public')->put($revealPath, file_get_contents($src));
                    break;
                }
            }

            Challenge::firstOrCreate(
                ['title' => $c['title'], 'sport_id' => $sport->id],
                [
                    'challenge_category_id' => $generalCategory?->id,
                    'hidden_image_path'     => $hiddenPath,
                    'original_image_path'   => $revealPath,
                    'ball_x_ratio'          => $c['ball_x_ratio'],
                    'ball_y_ratio'          => $c['ball_y_ratio'],
                    'difficulty'            => $c['difficulty'],
                    'status'                => 'active',
                ]
            );
        }
    }
}
