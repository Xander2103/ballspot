<?php

namespace Tests\Feature;

use App\Models\LoginVerificationCode;
use App\Models\User;
use App\Notifications\LoginVerificationCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailTwoFactorLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // These tests exercise the forced-2FA login path, which is off by
        // default now that accounts are email-verified at registration.
        config(['ballspot.auth.force_login_2fa' => true]);
    }

    private function user(): User
    {
        // Verified so login reaches the 2FA path (not email verification).
        return User::factory()->create(['password' => bcrypt('password123')]);
    }

    /** Log in and return [verification_id, plainCode], capturing the emailed code. */
    private function login(User $user): array
    {
        Notification::fake();
        $res = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123']);
        $res->assertOk()->assertJsonPath('requires_2fa', true);

        $code = null;
        Notification::assertSentTo($user, LoginVerificationCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;
            return true;
        });

        return [$res->json('verification_id'), $code];
    }

    public function test_valid_login_starts_2fa_without_token(): void
    {
        [$verificationId, $code] = $this->login($this->user());

        $this->assertIsString($verificationId);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function test_invalid_credentials_do_not_send_code(): void
    {
        Notification::fake();
        $user = $this->user();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(422);

        Notification::assertNothingSent();
        $this->assertDatabaseCount('login_verification_codes', 0);
    }

    public function test_unknown_email_does_not_send_code_and_is_generic(): void
    {
        Notification::fake();

        $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'whatever123'])
            ->assertStatus(422);

        Notification::assertNothingSent();
    }

    public function test_login_code_is_stored_hashed_not_plain(): void
    {
        $user = $this->user();
        [, $code] = $this->login($user);

        $record = LoginVerificationCode::first();
        $this->assertNotNull($record);
        $this->assertNotSame($code, $record->code_hash);
        $this->assertTrue(Hash::check($code, $record->code_hash));
    }

    public function test_me_cannot_be_accessed_before_verification(): void
    {
        $this->login($this->user());
        // No token issued yet by step 1.
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_verify_with_valid_code_returns_token_and_grants_access(): void
    {
        $user = $this->user();
        [$verificationId, $code] = $this->login($user);

        $res = $this->postJson('/api/login/verify', ['verification_id' => $verificationId, 'code' => $code]);
        $res->assertOk()->assertJsonStructure(['user', 'token']);

        $token = $res->json('token');
        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);

        // Code is consumed after success.
        $this->assertNotNull(LoginVerificationCode::where('verification_id', $verificationId)->first()->consumed_at);
    }

    public function test_verify_with_wrong_code_fails_and_increments_attempts(): void
    {
        $user = $this->user();
        [$verificationId, $code] = $this->login($user);
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->postJson('/api/login/verify', ['verification_id' => $verificationId, 'code' => $wrong])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invalid or expired verification code.');

        $this->assertSame(1, LoginVerificationCode::where('verification_id', $verificationId)->first()->attempts);
    }

    public function test_expired_code_fails(): void
    {
        $user = $this->user();
        [$verificationId, $code] = $this->login($user);

        $this->travel(11)->minutes();

        $this->postJson('/api/login/verify', ['verification_id' => $verificationId, 'code' => $code])
            ->assertStatus(422);

        $this->travelBack();
    }

    public function test_consumed_code_cannot_be_reused(): void
    {
        $user = $this->user();
        [$verificationId, $code] = $this->login($user);

        $this->postJson('/api/login/verify', ['verification_id' => $verificationId, 'code' => $code])->assertOk();

        // Second use of the same (now consumed) code is rejected.
        $this->postJson('/api/login/verify', ['verification_id' => $verificationId, 'code' => $code])
            ->assertStatus(422);
    }

    public function test_max_attempts_locks_the_code(): void
    {
        $user = $this->user();
        [$verificationId, $code] = $this->login($user);
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login/verify', ['verification_id' => $verificationId, 'code' => $wrong])
                ->assertStatus(422);
        }

        $this->assertSame(5, LoginVerificationCode::where('verification_id', $verificationId)->first()->attempts);

        // Even the CORRECT code is now rejected — the code is locked.
        $this->postJson('/api/login/verify', ['verification_id' => $verificationId, 'code' => $code])
            ->assertStatus(422);
    }

    public function test_resend_creates_new_code_and_invalidates_old(): void
    {
        $user = $this->user();
        [$verificationId, $oldCode] = $this->login($user);

        // Move past the resend cooldown, then request a new code.
        $this->travel(61)->seconds();

        Notification::fake();
        $this->postJson('/api/login/resend-code', ['verification_id' => $verificationId])->assertOk();

        $newCode = null;
        Notification::assertSentTo($user, LoginVerificationCodeNotification::class, function ($n) use (&$newCode) {
            $newCode = $n->code;
            return true;
        });

        // Old code no longer works.
        $this->postJson('/api/login/verify', ['verification_id' => $verificationId, 'code' => $oldCode])
            ->assertStatus(422);

        // New code works (same verification session).
        $this->postJson('/api/login/verify', ['verification_id' => $verificationId, 'code' => $newCode])
            ->assertOk()->assertJsonStructure(['user', 'token']);

        $this->travelBack();
    }

    public function test_resend_is_cooldown_limited(): void
    {
        $user = $this->user();
        [$verificationId] = $this->login($user);

        // Immediate resend (within cooldown) is rejected.
        $this->postJson('/api/login/resend-code', ['verification_id' => $verificationId])
            ->assertStatus(422);
    }

    public function test_resend_on_unknown_verification_says_login_again(): void
    {
        $this->postJson('/api/login/resend-code', ['verification_id' => '11111111-1111-1111-1111-111111111111'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Please login again.');
    }

    public function test_account_deletion_still_works_after_verified_login(): void
    {
        $user = $this->user();
        [$verificationId, $code] = $this->login($user);
        $token = $this->postJson('/api/login/verify', ['verification_id' => $verificationId, 'code' => $code])->json('token');

        $this->withToken($token)->deleteJson('/api/account')->assertOk();
    }

    public function test_cleanup_command_removes_expired_and_consumed_codes(): void
    {
        $user = $this->user();
        [$verificationId, $code] = $this->login($user);
        $this->postJson('/api/login/verify', ['verification_id' => $verificationId, 'code' => $code])->assertOk();

        // The consumed code should be removed by cleanup.
        $this->artisan('ballspot:cleanup-login-codes')->assertSuccessful();
        $this->assertDatabaseCount('login_verification_codes', 0);
    }
}
