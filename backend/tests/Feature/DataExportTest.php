<?php
namespace Tests\Feature;

use App\Models\PushToken;
use App\Models\User;
use App\Models\XpEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_requires_authentication(): void
    {
        $this->getJson('/api/me/export')->assertStatus(401);
    }

    public function test_export_returns_account_and_activity_data(): void
    {
        $user = User::factory()->create();
        XpEvent::create([
            'user_id'     => $user->id,
            'source_type' => XpEvent::SOURCE_DAILY_GUESS,
            'source_id'   => 1,
            'amount'      => 25,
            'reason'      => 'Daily challenge guess',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/me/export');

        $response->assertOk()
            ->assertJsonPath('account.email', $user->email)
            ->assertJsonPath('account.username', $user->username)
            ->assertJsonStructure([
                'exported_at',
                'account',
                'notification_settings',
                'push_tokens',
                'xp_events',
                'badges',
                'daily_guesses',
                'tournament_guesses_summary',
                'tournament_finishes',
                'competition_finishes',
                'pack_completions',
            ]);

        $this->assertSame(25, $response->json('xp_events.0.amount'));
    }

    public function test_export_never_contains_secrets(): void
    {
        $user = User::factory()->create();
        $plainToken = $user->createToken('mobile')->plainTextToken;
        PushToken::create([
            'user_id'      => $user->id,
            'token'        => 'ExponentPushToken[super-secret-push]',
            'platform'     => 'ios',
            'last_seen_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->getJson('/api/me/export');

        $response->assertOk();
        $raw = $response->getContent();

        $this->assertStringNotContainsString($user->password, $raw, 'password hash leaked');
        $this->assertStringNotContainsString('super-secret-push', $raw, 'push token value leaked');
        $this->assertStringNotContainsString(explode('|', $plainToken)[1] ?? $plainToken, $raw, 'api token leaked');
        // Platform metadata (not the token value) is fine and expected.
        $this->assertSame('ios', $response->json('push_tokens.0.platform'));
    }

    public function test_export_works_for_unverified_users(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/me/export')->assertOk();
    }

    public function test_export_includes_friends_data(): void
    {
        $user   = User::factory()->create(['username' => 'exporter']);
        $friend = User::factory()->create(['username' => 'buddy']);
        $sender = User::factory()->create(['username' => 'asker']);

        \App\Models\Friendship::create(['user_id' => $user->id,   'friend_id' => $friend->id]);
        \App\Models\Friendship::create(['user_id' => $friend->id, 'friend_id' => $user->id]);
        \App\Models\FriendRequest::create([
            'requester_id' => $sender->id, 'recipient_id' => $user->id, 'status' => 'pending',
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/me/export')->assertOk();

        // The subject's own share code is their data (Art. 15).
        $res->assertJsonPath('account.friend_code', $user->friend_code);
        $res->assertJsonPath('friends.0.username', 'buddy');
        $res->assertJsonPath('friend_requests.incoming.0.username', 'asker');
    }

    public function test_export_never_exposes_other_users_emails_or_friend_codes(): void
    {
        $user   = User::factory()->create();
        $friend = User::factory()->create(['email' => 'buddy-private@example.com']);
        \App\Models\Friendship::create(['user_id' => $user->id,   'friend_id' => $friend->id]);
        \App\Models\Friendship::create(['user_id' => $friend->id, 'friend_id' => $user->id]);

        $raw = $this->actingAs($user, 'sanctum')->getJson('/api/me/export')->assertOk()->getContent();

        $this->assertStringNotContainsString('buddy-private@example.com', $raw, "friend's email leaked");
        $this->assertStringNotContainsString($friend->friend_code, $raw, "friend's friend_code leaked");
    }
}
