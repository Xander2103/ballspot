<?php

namespace Tests\Feature;

use App\Models\EmailVerificationCode;
use App\Models\LoginVerificationCode;
use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Production data shape (2026-09-07): a users row with email_verified_at NULL
 * and ZERO rows in both email_verification_codes and login_verification_codes
 * (the hourly cleanup drops expired codes, so any account that never finished
 * verification ends up here). Such an account must always be able to recover
 * through the app: logging in or tapping "resend" has to mint a fresh, usable
 * email verification code — never a dead end, never a 500.
 */
class UnverifiedUserRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function strandedUser(): User
    {
        $user = User::factory()->unverified()->create(['password' => Hash::make('password123')]);

        $this->assertNull($user->email_verified_at);
        $this->assertSame(0, EmailVerificationCode::where('user_id', $user->id)->count());
        $this->assertSame(0, LoginVerificationCode::where('user_id', $user->id)->count());

        return $user;
    }

    private function assertOneUsableCode(User $user): EmailVerificationCode
    {
        $rows = EmailVerificationCode::where('user_id', $user->id)->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertNull($row->consumed_at);
        $this->assertTrue($row->expires_at->isFuture());
        $this->assertSame(0, $row->attempts);
        $this->assertTrue($row->isUsable((int) config('ballspot.auth.login_code_max_attempts', 5)));

        return $row;
    }

    private function emailedCode(User $user): string
    {
        $code = null;
        Notification::assertSentTo($user, EmailVerificationCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;
            return true;
        });
        $this->assertNotNull($code);

        return $code;
    }

    public function test_login_detects_no_usable_code_and_creates_and_sends_a_new_one(): void
    {
        $user = $this->strandedUser();
        Notification::fake();

        $res = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123']);

        $res->assertOk()
            ->assertJsonPath('requires_email_verification', true)
            ->assertJsonPath('email_verified', false)
            ->assertJsonPath('code_sent', true)
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['token']);

        Notification::assertSentToTimes($user, EmailVerificationCodeNotification::class, 1);
        $row = $this->assertOneUsableCode($user);
        $this->assertTrue(Hash::check($this->emailedCode($user), $row->code_hash));

        // The token from that login verifies the emailed code.
        $this->withToken($res->json('token'))
            ->postJson('/api/email/verify', ['code' => $this->emailedCode($user), 'email' => $user->email])
            ->assertOk()->assertJsonPath('email_verified', true);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_resend_creates_and_sends_a_new_code_when_none_exists(): void
    {
        $user  = $this->strandedUser();
        $token = $user->createToken('mobile')->plainTextToken;
        Notification::fake();

        $this->withToken($token)->postJson('/api/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('email_verified', false)
            ->assertJsonPath('email', $user->email);

        Notification::assertSentToTimes($user, EmailVerificationCodeNotification::class, 1);
        $row = $this->assertOneUsableCode($user);
        $this->assertTrue(Hash::check($this->emailedCode($user), $row->code_hash));
    }

    public function test_status_endpoint_reports_no_code_and_resend_available_for_a_stranded_user(): void
    {
        $user  = $this->strandedUser();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)->getJson('/api/email/verification-status')
            ->assertOk()
            ->assertJsonPath('email', $user->email)
            ->assertJsonPath('email_verified', false)
            ->assertJsonPath('has_usable_code', false)
            ->assertJsonPath('can_resend', true)
            ->assertJsonPath('resend_available_in_seconds', 0)
            ->assertJsonPath('code_expires_in_seconds', 0);
    }

    public function test_verify_without_any_code_is_a_clear_422_not_a_500(): void
    {
        $user  = $this->strandedUser();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)->postJson('/api/email/verify', ['code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'no_code');
    }

    public function test_login_with_only_expired_codes_also_mints_a_fresh_one(): void
    {
        $user = $this->strandedUser();
        EmailVerificationCode::create([
            'user_id'      => $user->id,
            'code_hash'    => Hash::make('000000'),
            'code_sent_at' => now()->subHours(3),
            'expires_at'   => now()->subHours(2),
            'attempts'     => 0,
        ]);
        Notification::fake();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertOk()->assertJsonPath('code_sent', true);

        Notification::assertSentToTimes($user, EmailVerificationCodeNotification::class, 1);
        // The expired row is dropped; exactly one live code remains.
        $this->assertOneUsableCode($user);
    }
}
