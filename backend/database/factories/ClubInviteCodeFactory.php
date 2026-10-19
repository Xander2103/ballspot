<?php

namespace Database\Factories;

use App\Enums\ClubRole;
use App\Models\Club;
use App\Models\ClubInviteCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClubInviteCode>
 */
class ClubInviteCodeFactory extends Factory
{
    protected $model = ClubInviteCode::class;

    public function definition(): array
    {
        $alphabet = (string) config('ballspot.clubs.codes.alphabet', 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789');
        $code = '';
        for ($i = 0; $i < (int) config('ballspot.clubs.codes.length', 8); $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return [
            'club_id'     => Club::factory(),
            'code'        => $code,
            'label'       => null,
            'grants_role' => ClubRole::Member,
            'max_uses'    => null,
            'uses_count'  => 0,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }

    public function exhausted(): static
    {
        return $this->state(fn () => ['max_uses' => 1, 'uses_count' => 1]);
    }
}
