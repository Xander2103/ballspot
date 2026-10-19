<?php

namespace Database\Factories;

use App\Enums\ClubStatus;
use App\Enums\ClubType;
use App\Models\Club;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Club>
 */
class ClubFactory extends Factory
{
    protected $model = Club::class;

    public function definition(): array
    {
        $name = 'Club ' . Str::title(fake()->unique()->word()) . ' ' . fake()->unique()->numberBetween(1, 999999);

        return [
            'name'         => Str::limit($name, 80, ''),
            'slug'         => Str::slug($name) . '-' . Str::lower(Str::random(4)),
            'description'  => null,
            'club_type'    => ClubType::SportsClub,
            'city'         => fake()->city(),
            'country_code' => 'BE',
            'timezone'     => 'Europe/Brussels',
            'status'       => ClubStatus::Active,
            'join_enabled' => true,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => ClubStatus::Suspended, 'suspended_at' => now(), 'suspension_reason' => 'Test']);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => ClubStatus::Archived, 'archived_at' => now(), 'archived_reason' => 'Test']);
    }

    public function joinDisabled(): static
    {
        return $this->state(fn () => ['join_enabled' => false]);
    }
}
