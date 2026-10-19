<?php

namespace Database\Factories;

use App\Models\ChallengePack;
use App\Models\ClubPackRewardClaim;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClubPackRewardClaim>
 */
class ClubPackRewardClaimFactory extends Factory
{
    protected $model = ClubPackRewardClaim::class;

    public function definition(): array
    {
        return [
            'user_id'           => User::factory(),
            'challenge_pack_id' => fn () => ChallengePack::create([
                'name'   => 'Pack ' . fake()->unique()->numberBetween(1, 999999),
                'slug'   => 'pack-' . fake()->unique()->numberBetween(1, 999999),
                'status' => ChallengePack::STATUS_ACTIVE,
            ])->id,
            'source_type'       => ClubPackRewardClaim::SOURCE_PACK_ATTEMPT,
            'source_id'         => fake()->numberBetween(1, 999999),
            'claimed_at'        => now(),
        ];
    }
}
