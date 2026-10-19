<?php

namespace Database\Factories;

use App\Models\ClubCompetition;
use App\Models\ClubCompetitionEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClubCompetitionEntry>
 */
class ClubCompetitionEntryFactory extends Factory
{
    protected $model = ClubCompetitionEntry::class;

    public function definition(): array
    {
        return [
            'club_competition_id' => ClubCompetition::factory(),
            'user_id'             => User::factory(),
            'total_score'         => 0,
            'packs_counted'       => 0,
            'packs_completed'     => 0,
            'attempts_used'       => 0,
            'score_reached_at'    => now(),
        ];
    }
}
