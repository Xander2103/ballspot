<?php

namespace Database\Factories;

use App\Enums\CompetitionAttemptStatus;
use App\Models\ClubCompetitionAttempt;
use App\Models\ClubCompetitionPack;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClubCompetitionAttempt>
 */
class ClubCompetitionAttemptFactory extends Factory
{
    protected $model = ClubCompetitionAttempt::class;

    public function definition(): array
    {
        return [
            'club_competition_id' => fn () => ClubCompetitionPack::factory()->create()->club_competition_id,
            'challenge_pack_id'   => fn (array $a) => ClubCompetitionPack::where('club_competition_id', $a['club_competition_id'])->value('challenge_pack_id'),
            'user_id'             => User::factory(),
            'attempt_number'      => 1,
            'status'              => CompetitionAttemptStatus::Active,
            'active_slot'         => ClubCompetitionAttempt::ACTIVE_SLOT,
            'challenge_ids'       => [],
            'started_at'          => now(),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status'       => CompetitionAttemptStatus::Completed,
            'active_slot'  => null,
            'completed_at' => now(),
        ]);
    }

    public function voided(): static
    {
        return $this->state(fn () => [
            'status'      => CompetitionAttemptStatus::Voided,
            'active_slot' => null,
            'voided_at'   => now(),
            'void_reason' => 'Test',
        ]);
    }
}
