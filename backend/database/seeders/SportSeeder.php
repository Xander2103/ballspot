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
        $sports = [
            ['slug' => 'football',          'name' => 'Football',         'emoji' => '⚽', 'object_name' => 'ball', 'primary_color' => '#00c853', 'is_active' => true],
            ['slug' => 'golf',              'name' => 'Golf',             'emoji' => '⛳', 'object_name' => 'ball', 'primary_color' => '#4caf50', 'is_active' => false],
            ['slug' => 'tennis',            'name' => 'Tennis',           'emoji' => '🎾', 'object_name' => 'ball', 'primary_color' => '#cddc39', 'is_active' => false],
            ['slug' => 'hockey',            'name' => 'Hockey',           'emoji' => '🏒', 'object_name' => 'puck', 'primary_color' => '#03a9f4', 'is_active' => false],
            ['slug' => 'cricket',           'name' => 'Cricket',          'emoji' => '🏏', 'object_name' => 'ball', 'primary_color' => '#f44336', 'is_active' => false],
            ['slug' => 'american_football', 'name' => 'American Football', 'emoji' => '🏈', 'object_name' => 'ball', 'primary_color' => '#795548', 'is_active' => false],
            ['slug' => 'basketball',        'name' => 'Basketball',       'emoji' => '🏀', 'object_name' => 'ball', 'primary_color' => '#ff9800', 'is_active' => false],
        ];

        foreach ($sports as $i => $sport) {
            Sport::updateOrCreate(
                ['slug' => $sport['slug']],
                array_merge($sport, ['sort_order' => $i + 1, 'scoring_profile' => 'default']),
            );
        }
    }
}
