<?php

namespace Tests\Feature;

use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use App\Notifications\LoginVerificationCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private array $registerPayload = [
        'name' => 'New Player', 'username' => 'newplayer',
        'email' => 'newplayer@example.com', 'password' => 'password123',
    ];

    /** Register and return [token, plainCode], capturing the emailed code. */
    private function register(): array
    {
        Notification::fake();
        $res = $this->postJson('/api/register', $this->registerPayload);
        $res->assertStatus(201)->assertJsonPath('email_verified', false);

        $user = User::where('email', $this->registerPayload['email'])->firstOrFail();
        $code = null;
        Notification::assertSentTo($user, EmailVerificationCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;
            return true;
        });

        return [$res->json('token'), $code];
    }

    public function test_registration_creates_unverified_user_and_sends_code(): void
    {
        [$token, $code] = $this->register();

        $user = User::where('email', $this->registerPayload['email'])->first();
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertNull($user->email_verified_at);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertIsString($token);
    }

    public function test_verification_code_is_stored_hashed(): void
    {
        [, $code] = $this->register();
        $record = EmailVerificationCode::first();
        $this->assertNotNull($record);
        $this->assertNotSame($code, $record->code_hash);
        $this->assertTrue(Hash::check($code, $record->code_hash));
    }

    public function test_unverified_user_cannot_access_protected_endpoints(): void
    {
        $user  = User::factory()->unverified()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        // Protected app endpoints are blocked...
        $this->withToken($token)->getJson('/api/profile/stats')->assertStatus(403);
        $this->withToken($token)->getJson('/api/leagues')->assertStatus(403);

        // ...but /me still works so the app can read verification status...
        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('data.email_verified', false);

        // ...and the sports list is available for onboarding sport selection.
        $this->withToken($token)->getJson('/api/sports')->assertOk();
    }

    public function test_verify_with_valid_code_grants_access(): void
    {
        [$token, $code] = $this->register();

        // Blocked before verifying.
        $this->withToken($token)->getJson('/api/profile/stats')->assertStatus(403);

        $this->withToken($token)->postJson('/api/email/verify', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('email_verified', true);

        // Now allowed.
        $this->withToken($token)->getJson('/api/profile/stats')->assertOk();

        $user = User::where('email', $this->registerPayload['email'])->first();
        $this->assertTrue($user->hasVerifiedEmail());
    }

    public function test_verify_with_wrong_code_fails(): void
    {
        [$token, $code] = $this->register();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->withToken($token)->postJson('/api/email/verify', ['code' => $wrong])
            ->assertStatus(422);

        $this->assertFalse(User::where('email', $this->registerPayload['email'])->first()->hasVerifiedEmail());
    }

    public function test_verify_requires_authentication(): void
    {
        $this->postJson('/api/email/verify', ['code' => '123456'])->assertStatus(401);
        $this->postJson('/api/email/verification-notification')->assertStatus(401);
    }

    public function test_resend_verification_sends_new_code(): void
    {
        [$token] = $this->register();

        // Immediate resend is cooldown-limited.
        $this->withToken($token)->postJson('/api/email/verification-notification')->assertStatus(422);

        // After the cooldown, a fresh code is sent and works.
        $this->travel(61)->seconds();
        Notification::fake();
        $this->withToken($token)->postJson('/api/email/verification-notification')
            ->assertOk()->assertJsonPath('email_verified', false);

        $user = User::where('email', $this->registerPayload['email'])->first();
        $newCode = null;
        Notification::assertSentTo($user, EmailVerificationCodeNotification::class, function ($n) use (&$newCode) {
            $newCode = $n->code;
            return true;
        });

        $this->withToken($token)->postJson('/api/email/verify', ['code' => $newCode])->assertOk();
        $this->travelBack();
    }

    public function test_login_with_unverified_email_requires_verification(): void
    {
        $user = User::factory()->unverified()->create(['password' => bcrypt('password123')]);

        Notification::fake();
        $res = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123']);

        $res->assertOk()
            ->assertJsonPath('requires_email_verification', true)
            ->assertJsonPath('email_verified', false)
            ->assertJsonStructure(['token', 'user']);

        // A code is (re)sent so the app can go straight to the verify screen.
        Notification::assertSentTo($user, EmailVerificationCodeNotification::class);
    }

    public function test_login_with_verified_email_returns_token_without_2fa(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password123')]); // verified by factory

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertOk()
            ->assertJsonStructure(['user', 'token'])
            ->assertJsonMissingPath('requires_2fa');
    }

    public function test_forced_login_2fa_still_works_when_enabled(): void
    {
        config(['ballspot.auth.force_login_2fa' => true]);
        $user = User::factory()->create(['password' => bcrypt('password123')]);

        Notification::fake();
        $res = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123']);

        $res->assertOk()->assertJsonPath('requires_2fa', true)->assertJsonStructure(['verification_id']);
        Notification::assertSentTo($user, LoginVerificationCodeNotification::class);
    }

    public function test_admin_login_always_requires_2fa(): void
    {
        // Even with forced 2FA off, admins get the second factor.
        $admin = User::factory()->create(['password' => bcrypt('password123'), 'is_admin' => true]);

        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password123'])
            ->assertOk()->assertJsonPath('requires_2fa', true);
    }
}
