<?php
namespace Tests\Feature;
use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User', 'username' => 'testuser',
            'email' => 'test@example.com', 'password' => 'password123',
        ]);
        $response->assertStatus(201)->assertJsonStructure(['user', 'token']);
    }

    public function test_valid_login_requires_2fa_and_returns_no_token(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password123')]);
        $response = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123']);

        // Step 1 no longer issues a token — it starts email 2FA.
        $response->assertOk()
            ->assertJsonPath('requires_2fa', true)
            ->assertJsonStructure(['requires_2fa', 'verification_id', 'message']);
        $this->assertArrayNotHasKey('token', $response->json());
    }

    public function test_invalid_login_fails(): void
    {
        $this->postJson('/api/login', ['email' => 'x@x.com', 'password' => 'wrong'])
            ->assertStatus(422);
    }
}
