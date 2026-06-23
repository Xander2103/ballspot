<?php
namespace Tests\Feature;

use App\Models\League;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeagueMemberTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserWithToken(): array
    {
        $user = User::factory()->create();
        return [$user, $user->createToken('test')->plainTextToken];
    }

    private function makeLobbyLeague(User $owner): League
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $league = League::create([
            'name' => 'Test', 'join_code' => strtoupper(\Illuminate\Support\Str::random(6)),
            'owner_user_id' => $owner->id, 'sport_id' => $sport->id,
            'duration_days' => 1, 'rounds_per_day' => 1, 'status' => 'lobby',
        ]);
        $league->members()->attach($owner->id, ['joined_at' => now()]);
        return $league;
    }

    public function test_league_detail_includes_members_with_is_owner_and_joined_at(): void
    {
        [$owner, $token] = $this->makeUserWithToken();
        $league = $this->makeLobbyLeague($owner);

        $res = $this->withToken($token)->getJson("/api/leagues/{$league->id}");

        $res->assertOk();
        $res->assertJsonStructure(['data' => ['members' => [['id', 'name', 'username', 'is_owner', 'joined_at']]]]);
        $res->assertJsonPath('data.members.0.is_owner', true);
        $this->assertNotNull($res->json('data.members.0.joined_at'));
    }

    public function test_owner_can_remove_lobby_member(): void
    {
        [$owner, $ownerToken] = $this->makeUserWithToken();
        [$member, ] = $this->makeUserWithToken();
        $league = $this->makeLobbyLeague($owner);
        $league->members()->attach($member->id, ['joined_at' => now()]);

        $res = $this->withToken($ownerToken)->deleteJson("/api/leagues/{$league->id}/members/{$member->id}");

        $res->assertNoContent();
        $this->assertDatabaseMissing('league_members', ['league_id' => $league->id, 'user_id' => $member->id]);
    }

    public function test_non_owner_cannot_remove_member(): void
    {
        [$owner,] = $this->makeUserWithToken();
        [$member, $memberToken] = $this->makeUserWithToken();
        [$other,] = $this->makeUserWithToken();
        $league = $this->makeLobbyLeague($owner);
        $league->members()->attach($member->id, ['joined_at' => now()]);
        $league->members()->attach($other->id, ['joined_at' => now()]);

        $res = $this->withToken($memberToken)->deleteJson("/api/leagues/{$league->id}/members/{$other->id}");
        $res->assertStatus(403);
        $res->assertJsonPath('message', 'Only the owner can remove players.');
    }

    public function test_owner_cannot_remove_themselves(): void
    {
        [$owner, $token] = $this->makeUserWithToken();
        $league = $this->makeLobbyLeague($owner);

        $res = $this->withToken($token)->deleteJson("/api/leagues/{$league->id}/members/{$owner->id}");
        $res->assertStatus(422);
        $res->assertJsonPath('message', 'The owner cannot be removed.');
    }

    public function test_cannot_remove_member_after_tournament_starts(): void
    {
        [$owner, $ownerToken] = $this->makeUserWithToken();
        [$member,] = $this->makeUserWithToken();
        $league = $this->makeLobbyLeague($owner);
        $league->members()->attach($member->id, ['joined_at' => now()]);
        $league->update(['status' => 'active']);

        $res = $this->withToken($ownerToken)->deleteJson("/api/leagues/{$league->id}/members/{$member->id}");
        $res->assertStatus(422);
        $res->assertJsonPath('message', 'Players can only be removed while the tournament is in lobby.');
    }

    public function test_removed_member_cannot_access_league(): void
    {
        [$owner,] = $this->makeUserWithToken();
        [$member, $memberToken] = $this->makeUserWithToken();
        $league = $this->makeLobbyLeague($owner);
        $league->members()->attach($member->id, ['joined_at' => now()]);

        // Remove via direct DB detach to simulate the endpoint result
        $league->members()->detach($member->id);

        $res = $this->withToken($memberToken)->getJson("/api/leagues/{$league->id}");
        $res->assertStatus(403);
    }
}
