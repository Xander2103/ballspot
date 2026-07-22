<?php

namespace Database\Seeders;

use App\Models\Sport;
use Illuminate\Database\Seeder;

class SportSeeder extends Seeder
{
    /**
     * Multi-sport foundation. Football is the only ACTIVE sport for now — the
     * others are seeded as inactive scaffolding so the backend can support them
     * later without breaking the current football-only experience.
     */
    public function run(): void
    {
        // Football is playable now; the rest are visible "Coming soon" so players
        // can see the roadmap and get excited, but cannot select them yet.
        $active     = Sport::STATUS_ACTIVE;
        $comingSoon = Sport::STATUS_COMING_SOON;

        $sports = [
            ['slug' => 'football',          'name' => 'Football',         'emoji' => '⚽', 'object_name' => 'ball', 'primary_color' => '#00c853', 'status' => $active],
            ['slug' => 'golf',              'name' => 'Golf',             'emoji' => '⛳', 'object_name' => 'ball', 'primary_color' => '#4caf50', 'status' => $comingSoon],
            ['slug' => 'tennis',            'name' => 'Tennis',           'emoji' => '🎾', 'object_name' => 'ball', 'primary_color' => '#cddc39', 'status' => $comingSoon],
            ['slug' => 'hockey',            'name' => 'Hockey',           'emoji' => '🏒', 'object_name' => 'puck', 'primary_color' => '#03a9f4', 'status' => $comingSoon],
            ['slug' => 'cricket',           'name' => 'Cricket',          'emoji' => '🏏', 'object_name' => 'ball', 'primary_color' => '#f44336', 'status' => $comingSoon],
            ['slug' => 'american_football', 'name' => 'American Football', 'emoji' => '🏈', 'object_name' => 'ball', 'primary_color' => '#795548', 'status' => $comingSoon],
            ['slug' => 'basketball',        'name' => 'Basketball',       'emoji' => '🏀', 'object_name' => 'ball', 'primary_color' => '#ff9800', 'status' => $comingSoon],
        ];

        foreach ($sports as $i => $sport) {
            Sport::updateOrCreate(
                ['slug' => $sport['slug']],
                array_merge($sport, ['sort_order' => $i + 1, 'scoring_profile' => 'default']),
            );
        }
    }
}
