<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FriendsTest extends TestCase
{
    use RefreshDatabase;

    private function auth(array $attrs = []): array
    {
        $user = User::factory()->create($attrs);
        return [$user, $user->createToken('test')->plainTextToken];
    }

    public function test_friend_code_is_generated_on_user_creation(): void
    {
        $user = User::factory()->create();

        $this->assertNotEmpty($user->friend_code);
        $this->assertSame(8, strlen($user->friend_code));
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $user->friend_code);
    }

    public function test_friend_codes_are_unique_across_users(): void
    {
        $codes = User::factory()->count(25)->create()->pluck('friend_code');

        $this->assertCount(25, $codes->unique());
    }

    public function test_all_friend_endpoints_require_auth(): void
    {
        $this->getJson('/api/friends')->assertUnauthorized();
        $this->getJson('/api/friends/requests')->assertUnauthorized();
        $this->postJson('/api/friends/requests', ['friend_code' => 'ABCDEFGH'])->assertUnauthorized();
        $this->postJson('/api/friends/requests/1/accept')->assertUnauthorized();
        $this->postJson('/api/friends/requests/1/reject')->assertUnauthorized();
        $this->deleteJson('/api/friends/1')->assertUnauthorized();
        $this->getJson('/api/me/friend-code')->assertUnauthorized();
    }

    public function test_user_can_read_own_friend_code(): void
    {
        [$user, $token] = $this->auth();

        $this->actingWithToken($token)->getJson('/api/me/friend-code')
            ->assertOk()
            ->assertJsonPath('friend_code', $user->friend_code);
    }

    public function test_can_send_friend_request_by_friend_code(): void
    {
        [, $token] = $this->auth();
        $target = User::factory()->create();

        $this->actingWithToken($token)
            ->postJson('/api/friends/requests', ['friend_code' => $target->friend_code])
            ->assertCreated()
            ->assertJsonPath('data.user.id', $target->id);

        $this->assertDatabaseHas('friend_requests', [
            'recipient_id' => $target->id,
            'status'       => 'pending',
        ]);
    }

    public function test_can_send_friend_request_by_user_id(): void
    {
        [$me, $token] = $this->auth();
        $target = User::factory()->create();

        $this->actingWithToken($token)
            ->postJson('/api/friends/requests', ['user_id' => $target->id])
            ->assertCreated()
            ->assertJsonPath('data.user.id', $target->id);

        $this->assertDatabaseHas('friend_requests', [
            'requester_id' => $me->id,
            'recipient_id' => $target->id,
            'status'       => 'pending',
        ]);
    }

    public function test_cannot_send_friend_request_to_anonymized_user_by_id(): void
    {
        [, $token] = $this->auth();
        $target = User::factory()->create();
        $target->forceFill(['anonymized_at' => now(), 'friend_code' => null])->save();

        $this->actingWithToken($token)
            ->postJson('/api/friends/requests', ['user_id' => $target->id])
            ->assertNotFound();
    }

    public function test_request_needs_exactly_one_of_code_or_user_id(): void
    {
        [, $token] = $this->auth();
        $target = User::factory()->create();

        $this->actingWithToken($token)
            ->postJson('/api/friends/requests', [])
            ->assertStatus(422);

        $this->actingWithToken($token)
            ->postJson('/api/friends/requests', [
                'friend_code' => $target->friend_code,
                'user_id'     => $target->id,
            ])
            ->assertStatus(422);
    }

    public function test_friend_code_lookup_is_case_insensitive(): void
    {
        [, $token] = $this->auth();
        $target = User::factory()->create();

        $this->actingWithToken($token)
            ->postJson('/api/friends/requests', ['friend_code' => strtolower($target->friend_code)])
            ->assertCreated();
    }

    public function test_unknown_friend_code_returns_404(): void
    {
        [, $token] = $this->auth();

        $this->actingWithToken($token)
            ->postJson('/api/friends/requests', ['friend_code' => 'ZZZZZZZZ'])
            ->assertNotFound();
    }

    public function test_cannot_send_friend_request_to_self(): void
    {
        [$user, $token] = $this->auth();

        $this->actingWithToken($token)
            ->postJson('/api/friends/requests', ['friend_code' => $user->friend_code])
            ->assertStatus(422);

        $this->assertDatabaseCount('friend_requests', 0);
    }

    public function test_cannot_send_duplicate_pending_request(): void
    {
        [, $token] = $this->auth();
        $target = User::factory()->create();

        $this->actingWithToken($token)->postJson('/api/friends/requests', ['friend_code' => $target->friend_code])->assertCreated();
        $this->actingWithToken($token)->postJson('/api/friends/requests', ['friend_code' => $target->friend_code])->assertStatus(422);

        $this->assertDatabaseCount('friend_requests', 1);
    }

    public function test_cannot_request_someone_who_is_already_a_friend(): void
    {
        [$user, $token] = $this->auth();
        $friend = User::factory()->create();
        \App\Models\Friendship::create(['user_id' => $user->id, 'friend_id' => $friend->id]);
        \App\Models\Friendship::create(['user_id' => $friend->id, 'friend_id' => $user->id]);

        $this->actingWithToken($token)
            ->postJson('/api/friends/requests', ['friend_code' => $friend->friend_code])
            ->assertStatus(422);
    }

    public function test_accepting_a_request_creates_a_two_way_friendship(): void
    {
        [$requester, $requesterToken] = $this->auth();
        [$recipient, $recipientToken] = $this->auth();

        $id = $this->actingWithToken($requesterToken)
            ->postJson('/api/friends/requests', ['friend_code' => $recipient->friend_code])
            ->assertCreated()->json('data.id');

        $this->actingWithToken($recipientToken)->postJson("/api/friends/requests/{$id}/accept")->assertOk();

        $this->assertDatabaseHas('friendships', ['user_id' => $requester->id, 'friend_id' => $recipient->id]);
        $this->assertDatabaseHas('friendships', ['user_id' => $recipient->id, 'friend_id' => $requester->id]);
        $this->assertDatabaseHas('friend_requests', ['id' => $id, 'status' => 'accepted']);

        $this->actingWithToken($requesterToken)->getJson('/api/friends')
            ->assertOk()->assertJsonPath('data.0.id', $recipient->id);
    }

    public function test_only_the_recipient_can_accept_a_request(): void
    {
        [, $requesterToken] = $this->auth();
        $recipient = User::factory()->create();
        $stranger  = User::factory()->create();

        $id = $this->actingWithToken($requesterToken)
            ->postJson('/api/friends/requests', ['friend_code' => $recipient->friend_code])
            ->json('data.id');

        $this->actingWithToken($stranger->createToken('t')->plainTextToken)
            ->postJson("/api/friends/requests/{$id}/accept")
            ->assertForbidden();

        $this->assertDatabaseCount('friendships', 0);
    }

    public function test_rejecting_a_request_does_not_create_a_friendship(): void
    {
        [, $requesterToken] = $this->auth();
        [$recipient, $recipientToken] = $this->auth();

        $id = $this->actingWithToken($requesterToken)
            ->postJson('/api/friends/requests', ['friend_code' => $recipient->friend_code])
            ->json('data.id');

        $this->actingWithToken($recipientToken)->postJson("/api/friends/requests/{$id}/reject")->assertOk();

        $this->assertDatabaseCount('friendships', 0);
        $this->assertDatabaseHas('friend_requests', ['id' => $id, 'status' => 'rejected']);
    }

    public function test_removing_a_friend_deletes_both_directions(): void
    {
        [$user, $token] = $this->auth();
        $friend = User::factory()->create();
        \App\Models\Friendship::create(['user_id' => $user->id, 'friend_id' => $friend->id]);
        \App\Models\Friendship::create(['user_id' => $friend->id, 'friend_id' => $user->id]);

        $this->actingWithToken($token)->deleteJson("/api/friends/{$friend->id}")->assertNoContent();

        $this->assertDatabaseCount('friendships', 0);
    }

    public function test_removing_a_non_friend_returns_404(): void
    {
        [, $token] = $this->auth();
        $stranger = User::factory()->create();

        $this->actingWithToken($token)->deleteJson("/api/friends/{$stranger->id}")->assertNotFound();
    }

    public function test_requests_endpoint_separates_incoming_and_outgoing(): void
    {
        [$user, $token] = $this->auth();
        $target = User::factory()->create();
        $sender = User::factory()->create();

        $this->actingWithToken($token)->postJson('/api/friends/requests', ['friend_code' => $target->friend_code])->assertCreated();
        \App\Models\FriendRequest::create([
            'requester_id' => $sender->id, 'recipient_id' => $user->id, 'status' => 'pending',
        ]);

        $res = $this->actingWithToken($token)->getJson('/api/friends/requests')->assertOk();

        $res->assertJsonPath('incoming.0.user.id', $sender->id);
        $res->assertJsonPath('outgoing.0.user.id', $target->id);
    }
}
