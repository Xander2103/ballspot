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
}
