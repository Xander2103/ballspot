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
            'object_name' => 'ball', 'primary_color' => '#00c853', 'status' => Sport::STATUS_ACTIVE, 'sort_order' => 1]);
        Sport::create(['name' => 'Tennis', 'slug' => 'tennis', 'emoji' => '🎾',
            'object_name' => 'ball', 'primary_color' => '#cddc39', 'status' => Sport::STATUS_COMING_SOON, 'sort_order' => 2]);
        Sport::create(['name' => 'Cricket', 'slug' => 'cricket', 'emoji' => '🏏',
            'object_name' => 'ball', 'primary_color' => '#f44336', 'status' => Sport::STATUS_HIDDEN, 'sort_order' => 3]);
    }

    public function test_sports_requires_auth(): void
    {
        $this->getJson('/api/sports')->assertUnauthorized();
    }

    public function test_returns_active_and_coming_soon_but_not_hidden(): void
    {
        $token = $this->auth();
        $this->seedSports();

        $res = $this->withToken($token)->getJson('/api/sports');

        $res->assertOk();
        $res->assertJsonCount(2, 'data'); // football + tennis, cricket (hidden) excluded

        $slugs = collect($res->json('data'))->pluck('slug');
        $this->assertTrue($slugs->contains('football'));
        $this->assertTrue($slugs->contains('tennis'));
        $this->assertFalse($slugs->contains('cricket'));

        $res->assertJsonStructure([
            'data' => [['id', 'name', 'slug', 'emoji', 'object_name', 'primary_color', 'status', 'is_playable', 'is_coming_soon']],
        ]);
    }

    public function test_exposes_status_flags(): void
    {
        $token = $this->auth();
        $this->seedSports();

        $data = collect($this->withToken($token)->getJson('/api/sports')->json('data'))->keyBy('slug');

        $this->assertSame('active', $data['football']['status']);
        $this->assertTrue($data['football']['is_playable']);
        $this->assertFalse($data['football']['is_coming_soon']);

        $this->assertSame('coming_soon', $data['tennis']['status']);
        $this->assertFalse($data['tennis']['is_playable']);
        $this->assertTrue($data['tennis']['is_coming_soon']);
    }

    public function test_unverified_user_can_load_sports_for_onboarding(): void
    {
        // Regression: sport selection must work during sign-up, before the
        // email is verified — the sports list is not behind the verified gate.
        $token = User::factory()->unverified()->create()->createToken('test')->plainTextToken;
        $this->seedSports();

        $res = $this->withToken($token)->getJson('/api/sports');

        $res->assertOk();
        $res->assertJsonCount(2, 'data');
    }
}
