<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    /** Find a registered route by its URI (routes here are unnamed). */
    private function routeMiddleware(string $uri, string $method = 'POST'): array
    {
        foreach (app('router')->getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                return $route->gatherMiddleware();
            }
        }
        $this->fail("Route {$method} {$uri} not found.");
    }

    public function test_first_register_attempt_is_not_throttled(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Test', 'username' => 'tester',
            'email' => 'tester@example.com', 'password' => 'password123',
            'terms_accepted' => true, 'age_confirmed' => true,
        ])->assertStatus(201);
    }

    public function test_register_is_throttled_per_ip_with_clean_429_json(): void
    {
        for ($i = 0; $i < 5; $i++) {
            // Invalid payloads still count against the limiter.
            $this->postJson('/api/register', ['email' => "u{$i}@example.com"]);
        }

        $response = $this->postJson('/api/register', [
            'name' => 'Late', 'username' => 'late',
            'email' => 'late@example.com', 'password' => 'password123',
            'terms_accepted' => true, 'age_confirmed' => true,
        ]);

        $response->assertStatus(429)
            ->assertJsonStructure(['message', 'retry_after']);
        $this->assertIsInt($response->json('retry_after'));
    }

    public function test_forgot_password_is_throttled_per_email(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/forgot-password', ['email' => 'victim@example.com']);
        }

        $this->postJson('/api/forgot-password', ['email' => 'victim@example.com'])
            ->assertStatus(429)
            ->assertJsonStructure(['message', 'retry_after']);
    }

    public function test_reset_password_is_throttled_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/reset-password', [
                'email' => 'victim@example.com',
                'token' => 'guess-' . $i,
                'password' => 'password123',
            ]);
        }

        $this->postJson('/api/reset-password', [
            'email' => 'victim@example.com',
            'token' => 'guess-final',
            'password' => 'password123',
        ])->assertStatus(429);
    }

    public function test_email_verification_submit_is_throttled_per_user(): void
    {
        $user = User::factory()->unverified()->create();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user, 'sanctum')->postJson('/api/email/verify', ['code' => '000000']);
        }

        $this->actingAs($user, 'sanctum')->postJson('/api/email/verify', ['code' => '000000'])
            ->assertStatus(429);
    }

    public function test_email_verification_resend_is_throttled_per_user(): void
    {
        $user = User::factory()->unverified()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user, 'sanctum')->postJson('/api/email/verification-notification');
        }

        $this->actingAs($user, 'sanctum')->postJson('/api/email/verification-notification')
            ->assertStatus(429);
    }

    public function test_admin_login_is_throttled_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/login', ['email' => 'admin@example.com', 'password' => 'wrong']);
        }

        $this->post('/admin/login', ['email' => 'admin@example.com', 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_gameplay_and_read_routes_carry_expected_throttles(): void
    {
        // Guess endpoints share the gameplay limiter.
        foreach ([
            'api/daily/{dailyChallenge}/guess',
            'api/rounds/{round}/guess',
            'api/pack-attempts/{attempt}/guess',
        ] as $uri) {
            $this->assertContains('throttle:gameplay', $this->routeMiddleware($uri),
                "{$uri} should be throttled as gameplay");
        }

        $this->assertContains('throttle:push-tokens', $this->routeMiddleware('api/me/push-tokens'));

        // Global API fallback covers every routes/api.php endpoint
        // (leaderboards, packs, ranks, stats, xp events, settings, ...):
        // throttleApi() appends the throttle to the 'api' middleware GROUP.
        $apiGroup = app('router')->getMiddlewareGroups()['api'] ?? [];
        $this->assertTrue(
            collect($apiGroup)->contains(fn (string $m) => str_ends_with($m, ':api')),
            "The 'api' middleware group should contain the global api throttle"
        );

        // And read-heavy routes are in that group.
        foreach ([
            ['api/daily/leaderboard/weekly', 'GET'],
            ['api/packs', 'GET'],
            ['api/ranks', 'GET'],
            ['api/profile/stats', 'GET'],
            ['api/me/xp-events', 'GET'],
            ['api/me/notification-settings', 'PUT'],
        ] as [$uri, $method]) {
            $this->assertContains('api', $this->routeMiddleware($uri, $method),
                "{$uri} should be in the api middleware group");
        }
    }

    public function test_admin_notification_send_is_throttled(): void
    {
        $this->assertContains('throttle:admin-send',
            $this->routeMiddleware('admin/notifications/{adminNotification}/send'));
    }
}
