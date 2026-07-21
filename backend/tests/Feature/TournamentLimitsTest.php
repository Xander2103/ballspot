<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\LeagueMember;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TournamentLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): array
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        return [$user, ['Authorization' => "Bearer $token"]];
    }

    private function sport(): Sport
    {
        return Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
    }

    private function createLeague(int $ownerId, int $sportId, string $status = 'lobby', string $code = null): League
    {
        return League::create([
            'name'           => 'L-' . uniqid(),
            'join_code'      => $code ?? strtoupper(substr(md5(uniqid()), 0, 6)),
            'owner_user_id'  => $ownerId,
            'sport_id'       => $sportId,
            'duration_days'  => 1,
            'rounds_per_day' => 1,
            'status'         => $status,
        ]);
    }

    public function test_user_cannot_exceed_free_created_tournament_limit(): void
    {
        config(['ballspot.tournaments.max_created_per_user' => 3]);
        $sport = $this->sport();
        [$user, $headers] = $this->actingAsUser();

        // 3 active/lobby tournaments already owned.
        $this->createLeague($user->id, $sport->id, 'lobby');
        $this->createLeague($user->id, $sport->id, 'active');
        $this->createLeague($user->id, $sport->id, 'lobby');

        $response = $this->postJson('/api/leagues', [
            'name' => 'Fourth', 'duration_days' => 1, 'rounds_per_day' => 1,
        ], $headers);

        $response->assertStatus(422)->assertJsonFragment([
            'message' => 'You have reached the free tournament limit. Finish or cancel an existing tournament to create a new one.',
        ]);
    }

    public function test_archived_and_cancelled_tournaments_do_not_count(): void
    {
        config(['ballspot.tournaments.max_created_per_user' => 3]);
        $sport = $this->sport();
        [$user, $headers] = $this->actingAsUser();

        // These should NOT count against the limit.
        $this->createLeague($user->id, $sport->id, 'cancelled');
        $this->createLeague($user->id, $sport->id, 'completed');
        $this->createLeague($user->id, $sport->id, 'finished');

        $response = $this->postJson('/api/leagues', [
            'name' => 'Fresh', 'duration_days' => 1, 'rounds_per_day' => 1,
        ], $headers);

        $response->assertStatus(201);
    }

    public function test_full_tournament_blocks_new_joins(): void
    {
        config(['ballspot.tournaments.max_players_per_tournament' => 2]);
        $sport = $this->sport();
        [$owner] = $this->actingAsUser();
        $league = $this->createLeague($owner->id, $sport->id, 'lobby', 'FULL01');

        // Fill to capacity (owner + 1 other = 2).
        LeagueMember::create(['league_id' => $league->id, 'user_id' => $owner->id, 'joined_at' => now()]);
        LeagueMember::create(['league_id' => $league->id, 'user_id' => User::factory()->create()->id, 'joined_at' => now()]);

        [$latecomer, $headers] = $this->actingAsUser();
        $response = $this->postJson('/api/leagues/join', ['join_code' => 'FULL01'], $headers);

        $response->assertStatus(422)->assertJsonFragment(['message' => 'This tournament is full.']);
    }

    public function test_member_can_still_access_when_under_limit(): void
    {
        config(['ballspot.tournaments.max_players_per_tournament' => 8]);
        $sport = $this->sport();
        [$owner] = $this->actingAsUser();
        $league = $this->createLeague($owner->id, $sport->id, 'lobby', 'OPEN01');
        LeagueMember::create(['league_id' => $league->id, 'user_id' => $owner->id, 'joined_at' => now()]);

        [$joiner, $headers] = $this->actingAsUser();
        $this->postJson('/api/leagues/join', ['join_code' => 'OPEN01'], $headers)->assertOk();

        $this->assertSame(2, $league->fresh()->members()->count());
    }
}
