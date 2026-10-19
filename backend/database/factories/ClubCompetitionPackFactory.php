<?php

namespace Database\Factories;

use App\Models\ChallengePack;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionPack;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Prefer CreatesClubs::makeCompetition(), which keeps pack.club_id equal to
 * competition.club_id; this factory does not enforce that invariant.
 *
 * @extends Factory<ClubCompetitionPack>
 */
class ClubCompetitionPackFactory extends Factory
{
    protected $model = ClubCompetitionPack::class;

    public function definition(): array
    {
        return [
            'club_competition_id' => ClubCompetition::factory(),
            'challenge_pack_id'   => fn () => ChallengePack::create([
                'name'   => 'Pack ' . fake()->unique()->numberBetween(1, 999999),
                'slug'   => 'pack-' . fake()->unique()->numberBetween(1, 999999),
                'status' => ChallengePack::STATUS_ACTIVE,
            ])->id,
            'sort_order'          => 0,
            'challenge_ids'       => null,
        ];
    }
}
