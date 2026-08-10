<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\FriendRequest;
use App\Models\Friendship;
use App\Models\League;
use App\Models\LeagueMember;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FriendSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    private function makeLeague(User $owner): League
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);

        return League::create([
            'name'           => 'Test League ' . uniqid(),
            'join_code'      => strtoupper(substr(uniqid(), -6)),
            'owner_user_id'  => $owner->id,
            'sport_id'       => $sport->id,
            'duration_days'  => 3,
            'rounds_per_day' => 1,
            'status'         => 'lobby',
        ]);
    }

    private function shareTournament(User $a, User $b): void
    {
        $league = $this->makeLeague($a);
        foreach ([$a, $b] as $u) {
            LeagueMember::create(['league_id' => $league->id, 'user_id' => $u->id, 'joined_at' => now()]);
        }
    }

    private function makeActiveDailyFor(string $date): DailyChallenge
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $challenge = Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => 'Suggestion Daily ' . $date,
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/test.jpg',
        ]);

        return DailyChallenge::create([
            'challenge_id'   => $challenge->id,
            'challenge_date' => $date,
            'status'         => 'active',
        ]);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/friends/suggestions')->assertUnauthorized();
    }

    public function test_suggests_tournament_peer_with_reason(): void
    {
        $me = User::factory()->create();
        $peer = User::factory()->create();
        $this->shareTournament($me, $peer);

        $this->withToken($me->createToken('t')->plainTextToken)
            ->getJson('/api/friends/suggestions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $peer->id)
            ->assertJsonPath('data.0.reason', 'same_tournament');
    }

    public function test_excludes_self_friends_pending_and_anonymized(): void
    {
        $me = User::factory()->create();

        $friend = User::factory()->create();
        Friendship::create(['user_id' => $me->id, 'friend_id' => $friend->id]);
        Friendship::create(['user_id' => $friend->id, 'friend_id' => $me->id]);

        $pendingOut = User::factory()->create();
        FriendRequest::create(['requester_id' => $me->id, 'recipient_id' => $pendingOut->id, 'status' => 'pending']);

        $pendingIn = User::factory()->create();
        FriendRequest::create(['requester_id' => $pendingIn->id, 'recipient_id' => $me->id, 'status' => 'pending']);

        $ghost = User::factory()->create();
        $ghost->forceFill(['anonymized_at' => now(), 'friend_code' => null])->save();

        foreach ([$friend, $pendingOut, $pendingIn, $ghost] as $u) {
            $this->shareTournament($me, $u);
        }

        $ids = collect(
            $this->withToken($me->createToken('t')->plainTextToken)
                ->getJson('/api/friends/suggestions')
                ->assertOk()
                ->json('data')
        )->pluck('id');

        $this->assertNotContains($me->id, $ids);
        $this->assertNotContains($friend->id, $ids);
        $this->assertNotContains($pendingOut->id, $ids);
        $this->assertNotContains($pendingIn->id, $ids);
        $this->assertNotContains($ghost->id, $ids);
    }

    public function test_recently_rejected_pair_is_not_suggested(): void
    {
        $me = User::factory()->create();
        $rejector = User::factory()->create();
        FriendRequest::create([
            'requester_id' => $me->id,
            'recipient_id' => $rejector->id,
            'status'       => 'rejected',
        ]);
        $this->shareTournament($me, $rejector);

        $ids = collect(
            $this->withToken($me->createToken('t')->plainTextToken)
                ->getJson('/api/friends/suggestions')
                ->assertOk()
                ->json('data')
        )->pluck('id');

        $this->assertNotContains($rejector->id, $ids);
    }

    public function test_falls_back_to_recently_active_players(): void
    {
        $me = User::factory()->create();
        $active = User::factory()->create();

        $daily = $this->makeActiveDailyFor(now()->subDay()->toDateString());
        $daily->guesses()->create([
            'user_id'       => $active->id,
            'guess_x_ratio' => 0.4,
            'guess_y_ratio' => 0.4,
            'distance'      => 0.1,
            'score'         => 80,
            'submitted_at'  => now()->subDay(),
        ]);

        $this->withToken($me->createToken('t')->plainTextToken)
            ->getJson('/api/friends/suggestions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonPath('data.0.reason', 'active_player');
    }

    public function test_never_exposes_email_or_friend_code(): void
    {
        $me = User::factory()->create();
        $peer = User::factory()->create();
        $this->shareTournament($me, $peer);

        $row = $this->withToken($me->createToken('t')->plainTextToken)
            ->getJson('/api/friends/suggestions')
            ->assertOk()
            ->json('data.0');

        $this->assertArrayNotHasKey('email', $row);
        $this->assertArrayNotHasKey('friend_code', $row);
        $this->assertArrayNotHasKey('is_admin', $row);
    }

    public function test_caps_at_ten(): void
    {
        $me = User::factory()->create();
        foreach (User::factory()->count(14)->create() as $peer) {
            $this->shareTournament($me, $peer);
        }

        $this->withToken($me->createToken('t')->plainTextToken)
            ->getJson('/api/friends/suggestions')
            ->assertOk()
            ->assertJsonCount(10, 'data');
    }
}
