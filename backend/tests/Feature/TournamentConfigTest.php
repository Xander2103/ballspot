<?php

namespace Tests\Feature;

use Tests\TestCase;

class TournamentConfigTest extends TestCase
{
    public function test_tournament_config_resolves_all_keys(): void
    {
        $cfg = config('ballspot.tournaments');

        // min_players_for_rewards was dead due to a duplicate 'tournaments'
        // key in config/ballspot.php — this asserts the blocks are merged.
        $this->assertSame(2, $cfg['min_players_for_rewards']);
        $this->assertSame(1, $cfg['max_created_per_user']);
        $this->assertSame(2, $cfg['max_active_memberships_per_user']);
        $this->assertSame(8, $cfg['max_players_per_tournament']);
    }
}
