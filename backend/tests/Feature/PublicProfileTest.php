<?php

namespace Tests\Feature;

use App\Models\Friendship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_profile_requires_auth(): void
    {
        $user = User::factory()->create();

        $this->getJson("/api/users/{$user->id}/public-profile")->assertUnauthorized();
    }

    public function test_public_profile_returns_safe_fields(): void
    {
        $viewer = User::factory()->create();
        $target = User::factory()->create(['username' => 'targetplayer']);

        $res = $this->withToken($viewer->createToken('t')->plainTextToken)
            ->getJson("/api/users/{$target->id}/public-profile")
            ->assertOk();

        $res->assertJsonPath('data.id', $target->id);
        $res->assertJsonPath('data.username', 'targetplayer');
        $res->assertJsonStructure([
            'data' => [
                'id', 'name', 'username', 'avatar_url', 'total_xp',
                'rank' => ['name', 'level', 'total_xp'],
                'stats' => ['tournaments_played', 'guesses_count', 'total_score', 'average_score', 'daily_challenges_played', 'best_daily_score'],
                'badges' => ['earned_count', 'total_count'],
                'is_friend', 'has_pending_request',
            ],
        ]);
    }

    public function test_public_profile_never_exposes_private_data(): void
    {
        $viewer = User::factory()->create();
        $target = User::factory()->create();

        $payload = $this->withToken($viewer->createToken('t')->plainTextToken)
            ->getJson("/api/users/{$target->id}/public-profile")
            ->assertOk()
            ->json('data');

        foreach (['email', 'password', 'remember_token', 'is_admin', 'friend_code', 'email_verified_at'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $payload, "{$forbidden} must not appear in a public profile");
        }

        $this->assertStringNotContainsString($target->email, json_encode($payload));
        $this->assertStringNotContainsString($target->friend_code, json_encode($payload));
    }

    public function test_public_profile_reports_friendship_state(): void
    {
        $viewer = User::factory()->create();
        $friend = User::factory()->create();
        Friendship::create(['user_id' => $viewer->id, 'friend_id' => $friend->id]);
        Friendship::create(['user_id' => $friend->id, 'friend_id' => $viewer->id]);

        $this->withToken($viewer->createToken('t')->plainTextToken)
            ->getJson("/api/users/{$friend->id}/public-profile")
            ->assertOk()
            ->assertJsonPath('data.is_friend', true);
    }
}
