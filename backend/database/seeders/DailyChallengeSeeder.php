<?php

namespace Database\Seeders;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use Illuminate\Database\Seeder;

class DailyChallengeSeeder extends Seeder
{
    public function run(): void
    {
        $challenge = Challenge::where('status', 'active')->first();
        if (!$challenge) return;

        DailyChallenge::firstOrCreate(
            ['challenge_date' => today()->toDateString()],
            ['challenge_id' => $challenge->id, 'status' => 'active']
        );
    }
}
