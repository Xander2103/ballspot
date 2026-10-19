<?php

namespace Database\Factories;

use App\Models\ClubCompetition;
use App\Models\ClubCompetitionFinish;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClubCompetitionFinish>
 */
class ClubCompetitionFinishFactory extends Factory
{
    protected $model = ClubCompetitionFinish::class;

    public function definition(): array
    {
        return [
            'club_competition_id' => ClubCompetition::factory()->finalized(),
            'user_id'             => User::factory(),
            'placement'           => 1,
            'total_score'         => 0,
            'total_players'       => 1,
            'awarded_at'          => now(),
        ];
    }
}
