<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Support\AppLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * The reset email links to {FRONTEND_URL}/reset-password. In production that
 * is the backend domain, which had no such page (404). These tests pin the
 * web fallback reset page + the events it logs.
 */
class PasswordResetWebTest extends TestCase
{
    use RefreshDatabase;

    private TestHandler $records;

    protected function setUp(): void
    {
        parent::setUp();
        $this->records = new TestHandler();
        Log::channel(AppLog::CHANNEL)->getLogger()->setHandlers([$this->records]);
    }

    private function makeUser(): User
    {
        return User::create([
            'name'              => 'Web Reset',
            'username'          => 'webreset',
            'email'             => 'webreset@example.com',
            'password'          => Hash::make('oldpassword123'),
            'email_verified_at' => now(),
        ]);
    }

    private function logged(string $message): array
    {
        return array_values(array_filter($this->records->getRecords(), fn ($r) => $r->message === $message));
    }

    public function test_reset_link_from_the_email_opens_a_working_form(): void
    {
        $user  = $this->makeUser();
        $token = Password::broker()->createToken($user);
        $url   = (new ResetPasswordNotification($token))->toMail($user)->actionUrl;

        // The link the user actually receives resolves on this app.
        $path = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);

        $this->get($path)
            ->assertOk()
            ->assertSee('Choose a new password')
            ->assertSee('webreset@example.com')
            ->assertSee('name="token"', false)
            ->assertSee('name="password_confirmation"', false);
    }

    public function test_reset_form_without_a_token_asks_for_a_new_link(): void
    {
        $this->get('/reset-password')
            ->assertOk()
            ->assertSee('Request a new link');
    }

    public function test_submitting_the_web_form_resets_the_password_and_revokes_tokens(): void
    {
        $user = $this->makeUser();
        $user->createToken('mobile');
        $token = Password::broker()->createToken($user);

        $res = $this->post('/reset-password', [
            'token'                 => $token,
            'email'                 => 'webreset@example.com',
            'password'              => 'brandnewpass123',
            'password_confirmation' => 'brandnewpass123',
        ]);

        $res->assertOk()->assertSee('Password updated');
        $this->assertTrue(Hash::check('brandnewpass123', $user->fresh()->password));
        $this->assertSame(0, $user->fresh()->tokens()->count());
        $this->assertNotEmpty($this->logged('password.reset_completed'));
        $this->assertSame('web', $this->logged('password.reset_completed')[0]->context['channel']);
    }

    public function test_expired_or_invalid_token_shows_a_friendly_error_with_a_way_out(): void
    {
        $this->makeUser();

        $res = $this->post('/reset-password', [
            'token'                 => 'not-a-real-token',
            'email'                 => 'webreset@example.com',
            'password'              => 'brandnewpass123',
            'password_confirmation' => 'brandnewpass123',
        ]);

        $res->assertStatus(422)
            ->assertSee('invalid or has expired')
            ->assertSee('Request a new link')
            ->assertDontSee('Exception');
        $this->assertTrue(Hash::check('oldpassword123', User::first()->password));

        $failed = $this->logged('password.reset_failed');
        $this->assertNotEmpty($failed);
        $this->assertSame('invalid_token', $failed[0]->context['reason']);
        $this->assertArrayNotHasKey('token', $failed[0]->context);
        $this->assertArrayNotHasKey('email', $failed[0]->context);
    }

    public function test_web_form_validates_the_password_without_exposing_the_token(): void
    {
        $user  = $this->makeUser();
        $token = Password::broker()->createToken($user);

        $res = $this->from('/reset-password')->post('/reset-password', [
            'token'                 => $token,
            'email'                 => 'webreset@example.com',
            'password'              => 'short',
            'password_confirmation' => 'short',
        ]);

        $res->assertRedirect('/reset-password')->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('oldpassword123', $user->fresh()->password));
    }

    public function test_web_forgot_page_sends_a_link_and_never_enumerates(): void
    {
        Notification::fake();
        $this->makeUser();

        $known   = $this->post('/forgot-password', ['email' => 'webreset@example.com']);
        $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.com']);

        $known->assertOk()->assertSee('Check your email');
        $unknown->assertOk()->assertSee('Check your email');
        Notification::assertSentTimes(ResetPasswordNotification::class, 1);

        // Both requests are logged by category; neither carries the address.
        $requested = $this->logged('password.reset_requested');
        $this->assertCount(2, $requested);
        foreach ($requested as $r) {
            $this->assertArrayNotHasKey('email', $r->context);
        }
        $this->assertSame('sent', $requested[0]->context['outcome']);
        $this->assertSame('no_account', $requested[1]->context['outcome']);
    }

    public function test_api_forgot_and_reset_log_events_without_secrets(): void
    {
        Notification::fake();
        $user = $this->makeUser();

        $this->postJson('/api/forgot-password', ['email' => 'webreset@example.com'])->assertOk();
        $token = Password::broker()->createToken($user);
        $this->postJson('/api/reset-password', [
            'token' => 'bad-token', 'email' => 'webreset@example.com',
            'password' => 'brandnewpass123', 'password_confirmation' => 'brandnewpass123',
        ])->assertStatus(422);
        $this->postJson('/api/reset-password', [
            'token' => $token, 'email' => 'webreset@example.com',
            'password' => 'brandnewpass123', 'password_confirmation' => 'brandnewpass123',
        ])->assertOk();

        $this->assertNotEmpty($this->logged('password.reset_requested'));
        $this->assertNotEmpty($this->logged('password.reset_failed'));
        $this->assertNotEmpty($this->logged('password.reset_completed'));
        $this->assertSame('api', $this->logged('password.reset_completed')[0]->context['channel']);

        $dump = json_encode(array_map(fn ($r) => $r->context, $this->records->getRecords()));
        $this->assertStringNotContainsString($token, $dump);
        $this->assertStringNotContainsString('brandnewpass123', $dump);
        $this->assertStringNotContainsString('webreset@example.com', $dump);
    }

    public function test_reset_email_link_offers_the_app_deep_link_on_the_web_page(): void
    {
        $user  = $this->makeUser();
        $token = Password::broker()->createToken($user);

        $this->get('/reset-password?token=' . $token . '&email=webreset%40example.com')
            ->assertOk()
            ->assertSee('ballpicker://reset-password?token=' . $token . '&amp;email=webreset%40example.com', false);
    }

    public function test_reset_pages_are_never_cached(): void
    {
        // The global SecurityHeaders middleware owns Referrer-Policy
        // (strict-origin-when-cross-origin keeps the token off other origins).
        $this->get('/reset-password?token=abc&email=x%40y.z')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}
