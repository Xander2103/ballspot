<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_stats_returns_expected_fields(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $res = $this->withToken($token)->getJson('/api/profile/stats');

        $res->assertOk();
        $res->assertJsonStructure([
            'tournaments_count',
            'completed_tournaments_count',
            'guesses_count',
            'total_score',
            'average_score',
        ]);
        $res->assertJsonPath('tournaments_count', 0);
        $res->assertJsonPath('guesses_count', 0);
        $res->assertJsonPath('average_score', 0);
    }

    public function test_profile_stats_requires_auth(): void
    {
        $this->getJson('/api/profile/stats')->assertStatus(401);
    }
}
