<?php
namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\League;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeagueTournamentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserWithToken(): array
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        return [$user, $token];
    }

    private function makeFootballWithChallenge(): Sport
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => 'Test Challenge',
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/test.jpg',
        ]);
        return $sport;
    }

    public function test_create_league_starts_in_lobby_with_no_rounds(): void
    {
        [$user, $token] = $this->makeUserWithToken();
        $this->makeFootballWithChallenge();

        $res = $this->withToken($token)->postJson('/api/leagues', [
            'name'           => 'Test Tournament',
            'duration_days'  => 1,
            'rounds_per_day' => 1,
        ]);

        $res->assertStatus(201);
        $res->assertJsonPath('data.status', 'lobby');
        $leagueId = $res->json('data.id');
        $this->assertDatabaseHas('leagues', ['id' => $leagueId, 'status' => 'lobby']);
        $this->assertDatabaseCount('league_rounds', 0);
    }

    public function test_owner_can_start_tournament(): void
    {
        [$owner, $token] = $this->makeUserWithToken();
        $this->makeFootballWithChallenge();

        $createRes = $this->withToken($token)->postJson('/api/leagues', [
            'name' => 'Test', 'duration_days' => 1, 'rounds_per_day' => 1,
        ]);
        $leagueId = $createRes->json('data.id');

        $res = $this->withToken($token)->postJson("/api/leagues/{$leagueId}/start");

        $res->assertOk();
        $res->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('leagues', ['id' => $leagueId, 'status' => 'active']);
        $this->assertDatabaseCount('league_rounds', 1);
    }

    public function test_non_owner_cannot_start_tournament(): void
    {
        [$owner, $ownerToken] = $this->makeUserWithToken();
        [$other, $otherToken] = $this->makeUserWithToken();
        $this->makeFootballWithChallenge();

        $createRes = $this->withToken($ownerToken)->postJson('/api/leagues', [
            'name' => 'Test', 'duration_days' => 1, 'rounds_per_day' => 1,
        ]);
        $leagueId = $createRes->json('data.id');

        League::find($leagueId)->members()->attach($other->id, ['joined_at' => now()]);

        $res = $this->actingAs($other, 'sanctum')->postJson("/api/leagues/{$leagueId}/start");
        $res->assertStatus(403);
    }

    public function test_start_fails_when_no_active_challenges(): void
    {
        [$owner, $token] = $this->makeUserWithToken();
        Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        // No challenges

        $createRes = $this->withToken($token)->postJson('/api/leagues', [
            'name' => 'Test', 'duration_days' => 1, 'rounds_per_day' => 1,
        ]);
        $leagueId = $createRes->json('data.id');

        $res = $this->withToken($token)->postJson("/api/leagues/{$leagueId}/start");
        $res->assertStatus(422);
        $res->assertJsonPath('message', 'No active Football challenges available. Add challenges in admin.');
    }

    public function test_users_can_join_lobby_tournament(): void
    {
        [$owner, $ownerToken] = $this->makeUserWithToken();
        [$joiner, $joinerToken] = $this->makeUserWithToken();
        $this->makeFootballWithChallenge();

        $createRes = $this->withToken($ownerToken)->postJson('/api/leagues', [
            'name' => 'Test', 'duration_days' => 1, 'rounds_per_day' => 1,
        ]);
        $joinCode = $createRes->json('data.join_code');

        $res = $this->withToken($joinerToken)->postJson('/api/leagues/join', ['join_code' => $joinCode]);
        $res->assertOk();
    }

    public function test_users_cannot_join_active_tournament(): void
    {
        [$owner, $ownerToken] = $this->makeUserWithToken();
        [$joiner, $joinerToken] = $this->makeUserWithToken();
        $this->makeFootballWithChallenge();

        $createRes = $this->withToken($ownerToken)->postJson('/api/leagues', [
            'name' => 'Test', 'duration_days' => 1, 'rounds_per_day' => 1,
        ]);
        $leagueId = $createRes->json('data.id');
        $joinCode  = $createRes->json('data.join_code');

        $this->withToken($ownerToken)->postJson("/api/leagues/{$leagueId}/start");

        $res = $this->withToken($joinerToken)->postJson('/api/leagues/join', ['join_code' => $joinCode]);
        $res->assertStatus(422);
    }

    public function test_owner_can_cancel_tournament(): void
    {
        [$owner, $token] = $this->makeUserWithToken();
        $this->makeFootballWithChallenge();

        $createRes = $this->withToken($token)->postJson('/api/leagues', [
            'name' => 'Test', 'duration_days' => 1, 'rounds_per_day' => 1,
        ]);
        $leagueId = $createRes->json('data.id');

        $res = $this->withToken($token)->deleteJson("/api/leagues/{$leagueId}");
        $res->assertNoContent();
        $this->assertDatabaseHas('leagues', ['id' => $leagueId, 'status' => 'cancelled']);
    }

    public function test_non_owner_cannot_cancel_tournament(): void
    {
        [$owner, $ownerToken] = $this->makeUserWithToken();
        [$other, $otherToken] = $this->makeUserWithToken();
        $this->makeFootballWithChallenge();

        $createRes = $this->withToken($ownerToken)->postJson('/api/leagues', [
            'name' => 'Test', 'duration_days' => 1, 'rounds_per_day' => 1,
        ]);
        $leagueId = $createRes->json('data.id');
        League::find($leagueId)->members()->attach($other->id, ['joined_at' => now()]);

        $res = $this->actingAs($other, 'sanctum')->deleteJson("/api/leagues/{$leagueId}");
        $res->assertStatus(403);
    }

    public function test_cancelled_leagues_not_in_index(): void
    {
        [$user, $token] = $this->makeUserWithToken();
        $this->makeFootballWithChallenge();

        $createRes = $this->withToken($token)->postJson('/api/leagues', [
            'name' => 'Test', 'duration_days' => 1, 'rounds_per_day' => 1,
        ]);
        $leagueId = $createRes->json('data.id');
        $this->withToken($token)->deleteJson("/api/leagues/{$leagueId}");

        $listRes = $this->withToken($token)->getJson('/api/leagues');
        $listRes->assertOk();
        $ids = collect($listRes->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($leagueId, $ids);
    }

    public function test_league_resource_includes_enriched_fields(): void
    {
        [$user, $token] = $this->makeUserWithToken();
        $this->makeFootballWithChallenge();

        $res = $this->withToken($token)->postJson('/api/leagues', [
            'name' => 'Test', 'duration_days' => 1, 'rounds_per_day' => 1,
        ]);

        $res->assertJsonStructure(['data' => [
            'id', 'name', 'status', 'owner_user_id', 'is_owner',
            'rounds_count', 'completed_rounds_count', 'remaining_rounds_count',
            'progress_pct', 'starts_at', 'ends_at',
        ]]);
        $res->assertJsonPath('data.is_owner', true);
        $res->assertJsonPath('data.status', 'lobby');
        $res->assertJsonPath('data.rounds_count', 0);
    }
}
