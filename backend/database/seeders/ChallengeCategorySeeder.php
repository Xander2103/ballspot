<?php

namespace Database\Seeders;

use App\Models\ChallengeCategory;
use App\Models\Sport;
use Illuminate\Database\Seeder;

class ChallengeCategorySeeder extends Seeder
{
    public function run(): void
    {
        $sport = Sport::where('slug', 'football')->firstOrFail();

        $categories = [
            ['name' => 'General',           'slug' => 'general',           'description' => 'Miscellaneous challenges',                          'sort_order' => 0],
            ['name' => 'Corner Kicks',      'slug' => 'corner-kicks',      'description' => 'Ball hidden near the corner arc or in corner situations', 'sort_order' => 1],
            ['name' => 'Dribbles',          'slug' => 'dribbles',          'description' => 'Ball spotted during dribbling sequences',             'sort_order' => 2],
            ['name' => 'Goalkeeper Saves',  'slug' => 'goalkeeper-saves',  'description' => 'Ball near the goal during save attempts',             'sort_order' => 3],
            ['name' => 'Headers',           'slug' => 'headers',           'description' => 'Ball in aerial situations and headers',               'sort_order' => 4],
            ['name' => 'Penalties',         'slug' => 'penalties',         'description' => 'Ball at or near the penalty spot',                   'sort_order' => 5],
            ['name' => 'Hard Mode',         'slug' => 'hard-mode',         'description' => 'Expert-level challenges with well-hidden balls',       'sort_order' => 6],
        ];

        foreach ($categories as $cat) {
            ChallengeCategory::firstOrCreate(
                ['sport_id' => $sport->id, 'slug' => $cat['slug']],
                [
                    'name'        => $cat['name'],
                    'description' => $cat['description'],
                    'sort_order'  => $cat['sort_order'],
                    'is_active'   => true,
                ]
            );
        }
    }
}
