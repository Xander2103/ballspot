<?php

namespace Database\Factories;

use App\Models\ClubCompetitionPack;
use App\Models\ClubCompetitionPackResult;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClubCompetitionPackResult>
 */
class ClubCompetitionPackResultFactory extends Factory
{
    protected $model = ClubCompetitionPackResult::class;

    public function definition(): array
    {
        return [
            'club_competition_id' => fn () => ClubCompetitionPack::factory()->create()->club_competition_id,
            'challenge_pack_id'   => fn (array $a) => ClubCompetitionPack::where('club_competition_id', $a['club_competition_id'])->value('challenge_pack_id'),
            'user_id'             => User::factory(),
            'attempts_used'       => 1,
            'counted_score'       => 0,
            'completed'           => false,
        ];
    }
}
