<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Support\AppLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * v1.9.7 — the password reset must never 500. Every outcome is a friendly
 * page/JSON with a stable code; the reset is transactional; logs carry
 * categories only.
 */
class PasswordResetHardeningTest extends TestCase
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
            'name' => 'Reset Me', 'username' => 'resetme', 'email' => 'resetme@example.com',
            'password' => Hash::make('oldpassword123'), 'email_verified_at' => now(),
        ]);
    }

    private function logged(string $message): array
    {
        return array_values(array_filter($this->records->getRecords(), fn ($r) => $r->message === $message));
    }

    private function logDump(): string
    {
        return json_encode(array_map(fn ($r) => [$r->message, $r->context], $this->records->getRecords()));
    }

    public function test_forgot_password_creates_a_token_and_sends_the_email(): void
    {
        Notification::fake();
        $user = $this->makeUser();

        $this->postJson('/api/forgot-password', ['email' => 'resetme@example.com'])->assertOk();

        Notification::assertSentTo($user, ResetPasswordNotification::class);
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', 'resetme@example.com')->count());
        $this->assertSame('sent', $this->logged('password_reset.requested')[0]->context['outcome']);
        $this->assertStringNotContainsString('resetme@example.com', $this->logDump());
    }

    public function test_reset_web_page_get_loads_with_a_valid_token_and_email(): void
    {
        $user  = $this->makeUser();
        $token = Password::broker()->createToken($user);

        $res = $this->get('/reset-password?token=' . $token . '&email=' . urlencode('resetme@example.com'));

        $res->assertOk()
            ->assertSee('name="token" value="' . $token . '"', false)
            ->assertSee('resetme@example.com')
            ->assertSee('password_confirmation')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_web_reset_post_succeeds_with_password_confirmation(): void
    {
        $user  = $this->makeUser();
        $token = Password::broker()->createToken($user);

        $res = $this->post('/reset-password', [
            'token' => $token, 'email' => 'resetme@example.com',
            'password' => 'brandnewpass123', 'password_confirmation' => 'brandnewpass123',
        ]);

        $res->assertOk()->assertSee('Password updated');
        $this->assertTrue(Hash::check('brandnewpass123', $user->fresh()->password));
    }

    public function test_api_reset_post_succeeds_with_password_confirmation(): void
    {
        $user  = $this->makeUser();
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token, 'email' => 'resetme@example.com',
            'password' => 'brandnewpass123', 'password_confirmation' => 'brandnewpass123',
        ])->assertOk()->assertJsonPath('message', 'Your password has been reset. Please log in.');

        $this->assertTrue(Hash::check('brandnewpass123', $user->fresh()->password));
        $this->assertNotEmpty($this->logged('password_reset.completed'));
    }

    public function test_reset_fails_friendly_for_an_invalid_token(): void
    {
        $this->makeUser();

        $api = $this->postJson('/api/reset-password', [
            'token' => 'not-a-real-token', 'email' => 'resetme@example.com',
            'password' => 'brandnewpass123', 'password_confirmation' => 'brandnewpass123',
        ]);
        $api->assertStatus(422)
            ->assertJsonPath('code', 'reset_token_invalid')
            ->assertJsonPath('message', 'This password reset link is invalid. Please request a new one.');
        $this->assertStringNotContainsStringIgnoringCase('exception', $api->getContent());

        $web = $this->post('/reset-password', [
            'token' => 'not-a-real-token', 'email' => 'resetme@example.com',
            'password' => 'brandnewpass123', 'password_confirmation' => 'brandnewpass123',
        ]);
        $web->assertStatus(422)->assertSee('Request a new link')->assertDontSee('Exception');

        $this->assertSame('invalid_token', $this->logged('password_reset.failed')[0]->context['reason']);
    }

    public function test_reset_fails_friendly_for_an_expired_token(): void
    {
        $user  = $this->makeUser();
        $token = Password::broker()->createToken($user);

        $this->travel((int) config('auth.passwords.users.expire', 60) + 1)->minutes();

        $api = $this->postJson('/api/reset-password', [
            'token' => $token, 'email' => 'resetme@example.com',
            'password' => 'brandnewpass123', 'password_confirmation' => 'brandnewpass123',
        ]);
        $api->assertStatus(422)
            ->assertJsonPath('code', 'reset_token_expired')
            ->assertJsonPath('reason', 'expired');

        $web = $this->post('/reset-password', [
            'token' => $token, 'email' => 'resetme@example.com',
            'password' => 'brandnewpass123', 'password_confirmation' => 'brandnewpass123',
        ]);
        $web->assertStatus(422)->assertSee('has expired')->assertSee('Request a new link');

        $this->assertTrue(Hash::check('oldpassword123', $user->fresh()->password));
        $this->assertSame('expired_token', $this->logged('password_reset.failed')[0]->context['reason']);
    }

    public function test_a_wrong_token_for_a_pending_reset_is_reported_as_invalid_not_expired(): void
    {
        $user = $this->makeUser();
        Password::broker()->createToken($user);
        $this->travel(90)->minutes();

        $this->postJson('/api/reset-password', [
            'token' => 'guessed-token-value', 'email' => 'resetme@example.com',
            'password' => 'brandnewpass123', 'password_confirmation' => 'brandnewpass123',
        ])->assertStatus(422)->assertJsonPath('code', 'reset_token_invalid');
    }

    public function test_reset_consumes_the_token(): void
    {
        $user  = $this->makeUser();
        $token = Password::broker()->createToken($user);
        $body  = [
            'token' => $token, 'email' => 'resetme@example.com',
            'password' => 'brandnewpass123', 'password_confirmation' => 'brandnewpass123',
        ];

        $this->postJson('/api/reset-password', $body)->assertOk();
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', 'resetme@example.com')->count());

        $this->postJson('/api/reset-password', $body + ['password' => 'yetanotherpass1', 'password_confirmation' => 'yetanotherpass1'])
            ->assertStatus(422)->assertJsonPath('code', 'reset_token_invalid');
        $this->assertTrue(Hash::check('brandnewpass123', $user->fresh()->password));
    }

    public function test_user_can_log_in_with_the_new_password_and_the_old_one_stops_working(): void
    {
        $user  = $this->makeUser();
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token, 'email' => 'resetme@example.com',
            'password' => 'brandnewpass123', 'password_confirmation' => 'brandnewpass123',
        ])->assertOk();

        $this->postJson('/api/login', ['email' => 'resetme@example.com', 'password' => 'oldpassword123'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');
        $this->postJson('/api/login', ['email' => 'resetme@example.com', 'password' => 'brandnewpass123'])
            ->assertOk()->assertJsonStructure(['token']);
    }

    public function test_reset_revokes_existing_api_tokens_and_sessions(): void
    {
        $user  = $this->makeUser();
        $old   = $user->createToken('phone')->plainTextToken;
        $token = Password::broker()->createToken($user);

        $this->withToken($old)->getJson('/api/me')->assertOk();

        $this->postJson('/api/reset-password', [
            'token' => $token, 'email' => 'resetme@example.com',
            'password' => 'brandnewpass123', 'password_confirmation' => 'brandnewpass123',
        ])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->actingWithToken($old)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_password_confirmation_is_required_on_both_channels(): void
    {
        $user  = $this->makeUser();
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token, 'email' => 'resetme@example.com',
            'password' => 'brandnewpass123', 'password_confirmation' => 'different123',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->from('/reset-password')->post('/reset-password', [
            'token' => $token, 'email' => 'resetme@example.com',
            'password' => 'brandnewpass123',
        ])->assertRedirect('/reset-password')->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('oldpassword123', $user->fresh()->password));
    }

    public function test_a_failure_during_the_reset_is_transactional_and_answers_friendly(): void
    {
        $user  = $this->makeUser();
        $token = Password::broker()->createToken($user);

        // Make consuming the token blow up AFTER the password was written
        // inside the same transaction: the whole reset must roll back.
        DB::unprepared('CREATE TRIGGER fail_reset BEFORE DELETE ON password_reset_tokens BEGIN SELECT RAISE(ABORT, "simulated failure"); END;');

        try {
            $api = $this->postJson('/api/reset-password', [
                'token' => $token, 'email' => 'resetme@example.com',
                'password' => 'brandnewpass123', 'password_confirmation' => 'brandnewpass123',
            ]);
            $api->assertStatus(500)
                ->assertJsonPath('code', 'reset_failed')
                ->assertJsonPath('message', 'We could not reset your password right now. Please try again in a moment.');
            $this->assertStringNotContainsStringIgnoringCase('simulated', $api->getContent());

            $web = $this->post('/reset-password', [
                'token' => $token, 'email' => 'resetme@example.com',
                'password' => 'brandnewpass123', 'password_confirmation' => 'brandnewpass123',
            ]);
            $web->assertStatus(500)->assertSee('Try again')->assertDontSee('simulated');
        } finally {
            DB::unprepared('DROP TRIGGER fail_reset');
        }

        $this->assertTrue(Hash::check('oldpassword123', $user->fresh()->password), 'password rolled back');
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', 'resetme@example.com')->count(), 'link still usable');

        $failed = $this->logged('password_reset.failed');
        $this->assertSame('exception', $failed[0]->context['reason']);
        $this->assertArrayNotHasKey('token', $failed[0]->context);

        // …and the retry then works.
        $this->postJson('/api/reset-password', [
            'token' => $token, 'email' => 'resetme@example.com',
            'password' => 'brandnewpass123', 'password_confirmation' => 'brandnewpass123',
        ])->assertOk();
    }

    public function test_logs_never_contain_the_token_password_or_email(): void
    {
        Notification::fake();
        $user  = $this->makeUser();
        $this->postJson('/api/forgot-password', ['email' => 'resetme@example.com'])->assertOk();
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => 'bogus', 'email' => 'resetme@example.com',
            'password' => 'brandnewpass123', 'password_confirmation' => 'brandnewpass123',
        ])->assertStatus(422);
        $this->postJson('/api/reset-password', [
            'token' => $token, 'email' => 'resetme@example.com',
            'password' => 'brandnewpass123', 'password_confirmation' => 'brandnewpass123',
        ])->assertOk();

        $dump = $this->logDump();
        $this->assertStringNotContainsString($token, $dump);
        $this->assertStringNotContainsString('bogus', $dump);
        $this->assertStringNotContainsString('brandnewpass123', $dump);
        $this->assertStringNotContainsString('resetme@example.com', $dump);
        $this->assertNotEmpty($this->logged('password_reset.completed'));
        $this->assertNotEmpty($this->logged('password_reset.failed'));
    }
}
