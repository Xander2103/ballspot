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
            'terms_accepted' => true, 'age_confirmed' => true,
        ]);
        $response->assertStatus(201)->assertJsonStructure(['user', 'token']);
    }

    public function test_verified_user_login_returns_token(): void
    {
        // Default flow: a verified email logs in with email + password only.
        $user = User::factory()->create(['password' => bcrypt('password123')]);
        $response = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123']);

        $response->assertOk()->assertJsonStructure(['user', 'token']);
    }

    public function test_invalid_login_fails(): void
    {
        $this->postJson('/api/login', ['email' => 'x@x.com', 'password' => 'wrong'])
            ->assertStatus(422);
    }
}
