<?php

namespace Database\Factories;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Models\Club;
use App\Models\ClubMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Raw rows for tests. Production code changes memberships only through
 * ClubMembershipService (counters + audit); call refreshCounters() after
 * seeding rows with this factory when a test depends on members_count.
 *
 * @extends Factory<ClubMember>
 */
class ClubMemberFactory extends Factory
{
    protected $model = ClubMember::class;

    public function definition(): array
    {
        return [
            'club_id'      => Club::factory(),
            'user_id'      => User::factory(),
            'role'         => ClubRole::Member,
            'status'       => ClubMemberStatus::Active,
            'joined_at'    => now(),
            'last_seen_at' => now(),
        ];
    }

    public function owner(): static
    {
        return $this->state(fn () => ['role' => ClubRole::Owner]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => ClubRole::Admin]);
    }

    public function status(ClubMemberStatus $status): static
    {
        return $this->state(fn () => [
            'status'  => $status,
            'left_at' => $status === ClubMemberStatus::Active ? null : now(),
        ]);
    }
}
