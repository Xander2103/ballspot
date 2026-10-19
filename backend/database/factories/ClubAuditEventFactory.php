<?php

namespace Database\Factories;

use App\Models\Club;
use App\Models\ClubAuditEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Raw rows for list/filter tests. Production code writes audit rows only via
 * ClubAuditLogger.
 *
 * @extends Factory<ClubAuditEvent>
 */
class ClubAuditEventFactory extends Factory
{
    protected $model = ClubAuditEvent::class;

    public function definition(): array
    {
        return [
            'club_id'    => Club::factory(),
            'actor_kind' => ClubAuditEvent::ACTOR_SYSTEM,
            'action'     => 'club.updated',
            'metadata'   => [],
            'created_at' => now(),
        ];
    }
}
