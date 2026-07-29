<?php
namespace Tests\Feature;

use App\Models\PushToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PushTokenPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function register(User $user, string $token): void
    {
        $this->actingAs($user, 'sanctum')->postJson('/api/me/push-tokens', [
            'token'    => $token,
            'platform' => 'android',
        ])->assertStatus(201);
    }

    public function test_register_response_never_echoes_the_token(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/me/push-tokens', [
            'token'    => 'ExponentPushToken[secret-value]',
            'platform' => 'ios',
        ]);

        $response->assertStatus(201);
        $this->assertStringNotContainsString('secret-value', $response->getContent());
    }

    public function test_delete_with_token_removes_only_that_own_row(): void
    {
        $user = User::factory()->create();
        $this->register($user, 'ExponentPushToken[device-a]');
        $this->register($user, 'ExponentPushToken[device-b]');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/me/push-tokens', ['token' => 'ExponentPushToken[device-a]'])
            ->assertOk()
            ->assertJson(['status' => 'removed']);

        $this->assertDatabaseMissing('push_tokens', ['token' => 'ExponentPushToken[device-a]']);
        $this->assertDatabaseHas('push_tokens', ['token' => 'ExponentPushToken[device-b]']);
    }

    public function test_delete_cannot_remove_another_users_token(): void
    {
        $owner    = User::factory()->create();
        $attacker = User::factory()->create();
        $this->register($owner, 'ExponentPushToken[owned]');

        $this->actingAs($attacker, 'sanctum')
            ->deleteJson('/api/me/push-tokens', ['token' => 'ExponentPushToken[owned]'])
            ->assertOk();

        $this->assertDatabaseHas('push_tokens', [
            'token'   => 'ExponentPushToken[owned]',
            'user_id' => $owner->id,
        ]);
    }

    public function test_delete_without_token_removes_all_own_rows(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();
        $this->register($user, 'ExponentPushToken[one]');
        $this->register($user, 'ExponentPushToken[two]');
        $this->register($other, 'ExponentPushToken[keep]');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/me/push-tokens')
            ->assertOk();

        $this->assertDatabaseMissing('push_tokens', ['user_id' => $user->id]);
        $this->assertDatabaseHas('push_tokens', ['user_id' => $other->id]);
    }

    public function test_cleanup_command_prunes_stale_push_tokens(): void
    {
        $user = User::factory()->create();
        PushToken::create([
            'user_id'      => $user->id,
            'token'        => 'ExponentPushToken[stale]',
            'platform'     => 'android',
            'last_seen_at' => now()->subDays(91),
        ]);
        PushToken::create([
            'user_id'      => $user->id,
            'token'        => 'ExponentPushToken[fresh]',
            'platform'     => 'android',
            'last_seen_at' => now()->subDays(89),
        ]);

        $this->artisan('ballspot:cleanup-login-codes')->assertExitCode(0);

        $this->assertDatabaseMissing('push_tokens', ['token' => 'ExponentPushToken[stale]']);
        $this->assertDatabaseHas('push_tokens', ['token' => 'ExponentPushToken[fresh]']);
    }

    public function test_user_json_never_exposes_is_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->assertArrayNotHasKey('is_admin', $admin->toArray());
        $this->assertStringNotContainsString('is_admin', $admin->toJson());
    }
}
