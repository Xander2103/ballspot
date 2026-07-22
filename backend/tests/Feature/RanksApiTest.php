<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RanksApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_ranks_endpoint_returns_all_configured_ranks(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/ranks');

        $response->assertOk();
        $expected = count(config('ballspot.ranks'));
        $this->assertCount($expected, $response->json('data'));
        $response->assertJsonStructure(['data' => [['name', 'level', 'min_xp']]]);

        // Matches the progression source of truth exactly.
        $this->assertSame('Rookie', $response->json('data.0.name'));
        $this->assertSame(0, $response->json('data.0.min_xp'));
    }

    public function test_ranks_are_ordered_by_minimum_xp_ascending(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $data = $this->withToken($token)->getJson('/api/ranks')->json('data');

        $xps = array_column($data, 'min_xp');
        $sorted = $xps;
        sort($sorted);
        $this->assertSame($sorted, $xps);
    }

    public function test_ranks_endpoint_requires_authentication(): void
    {
        // Mirrors the existing auth policy: authenticated but no verified-email gate.
        $this->getJson('/api/ranks')->assertUnauthorized();
    }
}
