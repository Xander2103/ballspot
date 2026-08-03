<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeagueHideTest extends TestCase
{
    use RefreshDatabase;

    private function completedLeagueWithMember(User $user): League
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);

        $league = League::create([
            'name'           => 'Finished Cup',
            'join_code'      => 'ABC123',
            'owner_user_id'  => $user->id,
            'sport_id'       => $sport->id,
            'duration_days'  => 3,
            'rounds_per_day' => 1,
            'status'         => 'completed',
        ]);
        $league->members()->attach($user->id, ['joined_at' => now()]);

        return $league;
    }

    public function test_hide_requires_auth(): void
    {
        $user   = User::factory()->create();
        $league = $this->completedLeagueWithMember($user);

        $this->postJson("/api/leagues/{$league->id}/hide")->assertUnauthorized();
    }

    public function test_member_can_hide_a_completed_tournament(): void
    {
        $user   = User::factory()->create();
        $token  = $user->createToken('t')->plainTextToken;
        $league = $this->completedLeagueWithMember($user);

        $this->actingWithToken($token)->postJson("/api/leagues/{$league->id}/hide")->assertNoContent();

        $this->assertDatabaseMissing('league_members', [
            'league_id' => $league->id, 'user_id' => $user->id, 'hidden_at' => null,
        ]);
    }

    public function test_hidden_tournament_disappears_from_the_list(): void
    {
        $user   = User::factory()->create();
        $token  = $user->createToken('t')->plainTextToken;
        $league = $this->completedLeagueWithMember($user);

        $this->actingWithToken($token)->getJson('/api/leagues')->assertOk()->assertJsonCount(1, 'data');
        $this->actingWithToken($token)->postJson("/api/leagues/{$league->id}/hide")->assertNoContent();
        $this->actingWithToken($token)->getJson('/api/leagues')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_hiding_does_not_delete_the_league_or_membership_for_others(): void
    {
        $user   = User::factory()->create();
        $other  = User::factory()->create();
        $token  = $user->createToken('t')->plainTextToken;
        $league = $this->completedLeagueWithMember($user);
        $league->members()->attach($other->id, ['joined_at' => now()]);

        $this->actingWithToken($token)->postJson("/api/leagues/{$league->id}/hide")->assertNoContent();

        $this->assertDatabaseHas('leagues', ['id' => $league->id, 'status' => 'completed']);
        $this->actingWithToken($other->createToken('t')->plainTextToken)
            ->getJson('/api/leagues')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_cannot_hide_a_tournament_you_are_not_part_of(): void
    {
        $owner    = User::factory()->create();
        $stranger = User::factory()->create();
        $league   = $this->completedLeagueWithMember($owner);

        $this->actingWithToken($stranger->createToken('t')->plainTextToken)
            ->postJson("/api/leagues/{$league->id}/hide")
            ->assertForbidden();
    }

    public function test_cannot_hide_an_active_or_lobby_tournament(): void
    {
        $user   = User::factory()->create();
        $token  = $user->createToken('t')->plainTextToken;
        $league = $this->completedLeagueWithMember($user);
        $league->update(['status' => 'active']);

        $this->actingWithToken($token)->postJson("/api/leagues/{$league->id}/hide")->assertStatus(422);

        $league->update(['status' => 'lobby']);
        $this->actingWithToken($token)->postJson("/api/leagues/{$league->id}/hide")->assertStatus(422);
    }

    public function test_hiding_is_idempotent(): void
    {
        $user   = User::factory()->create();
        $token  = $user->createToken('t')->plainTextToken;
        $league = $this->completedLeagueWithMember($user);

        $this->actingWithToken($token)->postJson("/api/leagues/{$league->id}/hide")->assertNoContent();
        $this->actingWithToken($token)->postJson("/api/leagues/{$league->id}/hide")->assertNoContent();
    }

    public function test_hidden_tournament_still_appears_in_profile_history(): void
    {
        $user   = User::factory()->create();
        $token  = $user->createToken('t')->plainTextToken;
        $league = $this->completedLeagueWithMember($user);

        \App\Models\TournamentFinish::create([
            'league_id'     => $league->id,
            'user_id'       => $user->id,
            'placement'     => 1,
            'total_score'   => 240,
            'rounds_played' => 3,
            'metadata'      => ['total_players' => 4],
        ]);

        $this->actingWithToken($token)->postJson("/api/leagues/{$league->id}/hide")->assertNoContent();

        $this->actingWithToken($token)->getJson('/api/me/tournament-finishes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.league.name', 'Finished Cup');
    }
}
