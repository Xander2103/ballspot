<?php

namespace Tests\Feature;

use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SportsApiTest extends TestCase
{
    use RefreshDatabase;

    private function auth(): string
    {
        return User::factory()->create()->createToken('test')->plainTextToken;
    }

    private function seedSports(): void
    {
        Sport::create(['name' => 'Football', 'slug' => 'football', 'emoji' => '⚽',
            'object_name' => 'ball', 'primary_color' => '#00c853', 'is_active' => true, 'sort_order' => 1]);
        Sport::create(['name' => 'Tennis', 'slug' => 'tennis', 'emoji' => '🎾',
            'object_name' => 'ball', 'primary_color' => '#cddc39', 'is_active' => false, 'sort_order' => 2]);
    }

    public function test_sports_requires_auth(): void
    {
        $this->getJson('/api/sports')->assertUnauthorized();
    }

    public function test_returns_only_active_sports_by_default(): void
    {
        $token = $this->auth();
        $this->seedSports();

        $res = $this->withToken($token)->getJson('/api/sports');

        $res->assertOk();
        $res->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.slug', 'football');
        $res->assertJsonStructure([
            'data' => [['id', 'name', 'slug', 'emoji', 'object_name', 'primary_color', 'is_active']],
        ]);
    }

    public function test_can_include_inactive_sports(): void
    {
        $token = $this->auth();
        $this->seedSports();

        $res = $this->withToken($token)->getJson('/api/sports?include_inactive=1');

        $res->assertOk();
        $res->assertJsonCount(2, 'data');
    }
}
