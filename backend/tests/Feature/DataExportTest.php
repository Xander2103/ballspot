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
}
