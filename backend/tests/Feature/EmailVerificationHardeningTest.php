<?php

namespace Tests\Feature;

use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use App\Support\AppLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * Launch hardening for the registration code flow.
 *
 * Root cause of "the code in my email does not work": every send() wiped the
 * previous code, and logging in again after 60s silently sent a new one — so
 * a slow email (or two emails arriving out of order) carried a dead code.
 */
class EmailVerificationHardeningTest extends TestCase
{
    use RefreshDatabase;

    private TestHandler $records;

    private array $payload = [
        'name' => 'Slow Mail', 'username' => 'slowmail',
        'email' => 'slowmail@example.com', 'password' => 'password123',
        'terms_accepted' => true, 'age_confirmed' => true,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->records = new TestHandler();
        Log::channel(AppLog::CHANNEL)->getLogger()->setHandlers([$this->records]);
    }

    private function logged(string $message): array
    {
        return array_values(array_filter($this->records->getRecords(), fn ($r) => $r->message === $message));
    }

    /** Captures the code from the most recently sent notification. */
    private function lastCode(User $user): string
    {
        $code = null;
        Notification::assertSentTo($user, EmailVerificationCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;
            return true;
        });

        return $code;
    }

    private function register(): array
    {
        Notification::fake();
        $res  = $this->postJson('/api/register', $this->payload)->assertStatus(201);
        $user = User::where('email', $this->payload['email'])->firstOrFail();

        return [$user, $res->json('token'), $this->lastCode($user)];
    }

    public function test_the_first_code_still_works_after_a_resend(): void
    {
        [$user, $token, $first] = $this->register();

        $this->travel(61)->seconds();
        Notification::fake();
        $this->withToken($token)->postJson('/api/email/verification-notification')->assertOk();
        $second = $this->lastCode($user);
        $this->assertNotSame($first, $second);

        // The user opens the FIRST email (delivered late) — it must still work.
        $this->withToken($token)->postJson('/api/email/verify', ['code' => $first])->assertOk();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertSame(0, EmailVerificationCode::where('user_id', $user->id)->whereNull('consumed_at')->count());
    }

    public function test_the_newest_code_works_too(): void
    {
        [$user, $token] = $this->register();

        $this->travel(61)->seconds();
        Notification::fake();
        $this->withToken($token)->postJson('/api/email/verification-notification')->assertOk();
        $second = $this->lastCode($user);

        $this->withToken($token)->postJson('/api/email/verify', ['code' => $second])->assertOk();
    }

    public function test_only_the_last_three_codes_stay_valid(): void
    {
        [$user, $token, $first] = $this->register();

        for ($i = 0; $i < 3; $i++) {
            $this->travel(61)->seconds();
            Notification::fake();
            $this->withToken($token)->postJson('/api/email/verification-notification')->assertOk();
        }

        $this->assertSame(3, EmailVerificationCode::where('user_id', $user->id)->count());
        $this->withToken($token)->postJson('/api/email/verify', ['code' => $first])->assertStatus(422);
    }

    public function test_logging_in_again_does_not_invalidate_a_usable_code(): void
    {
        [$user, , $first] = $this->register();

        // Well past the resend cooldown: the old behaviour sent a NEW code
        // here and deleted the first one.
        $this->travel(5)->minutes();
        Notification::fake();
        $login = $this->postJson('/api/login', ['email' => $this->payload['email'], 'password' => 'password123']);

        $login->assertOk()
            ->assertJsonPath('requires_email_verification', true)
            ->assertJsonPath('code_sent', false);
        Notification::assertNothingSent();

        $this->withToken($login->json('token'))->postJson('/api/email/verify', ['code' => $first])->assertOk();
    }

    public function test_logging_in_sends_a_fresh_code_when_the_old_one_expired(): void
    {
        [$user] = $this->register();

        $this->travel(2)->hours(); // past the 60-minute expiry
        Notification::fake();
        $login = $this->postJson('/api/login', ['email' => $this->payload['email'], 'password' => 'password123']);

        $login->assertOk()->assertJsonPath('code_sent', true);
        $code = $this->lastCode($user);
        $this->withToken($login->json('token'))->postJson('/api/email/verify', ['code' => $code])->assertOk();
    }

    public function test_wrong_codes_lock_after_max_attempts_with_a_clear_message(): void
    {
        [, $token, $code] = $this->register();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->withToken($token)->postJson('/api/email/verify', ['code' => $wrong])
                ->assertStatus(422)
                ->assertJsonPath('errors.code.0', 'Invalid or expired verification code.');
        }

        // Even the right code is refused once locked — request a new one.
        $this->withToken($token)->postJson('/api/email/verify', ['code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Too many incorrect attempts. Please request a new code.');
    }

    public function test_expired_code_gets_a_specific_friendly_message(): void
    {
        [, $token, $code] = $this->register();

        $this->travel(2)->hours();

        $this->withToken($token)->postJson('/api/email/verify', ['code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'This code has expired. Please request a new one.');
    }

    public function test_verification_events_are_logged_without_the_code(): void
    {
        [$user, $token, $code] = $this->register();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->withToken($token)->postJson('/api/email/verify', ['code' => $wrong])->assertStatus(422);
        $this->withToken($token)->postJson('/api/email/verify', ['code' => $code])->assertOk();

        $this->assertNotEmpty($this->logged('auth.verification_sent'));
        $this->assertSame('wrong_code', $this->logged('auth.verification_failed')[0]->context['reason']);
        $this->assertSame($user->id, $this->logged('auth.verification_completed')[0]->context['user_id']);

        $dump = json_encode(array_map(fn ($r) => $r->context, $this->records->getRecords()));
        $this->assertStringNotContainsString($code, $dump);
        $this->assertStringNotContainsString($this->payload['email'], $dump);
    }

    public function test_mail_failure_during_registration_is_logged_and_registration_still_succeeds(): void
    {
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('smtp down at mail.example.internal'));

        $res = $this->postJson('/api/register', $this->payload);

        $res->assertStatus(201)->assertJsonPath('email_verified', false)->assertJsonPath('code_sent', false);
        $failed = $this->logged('auth.verification_send_failed');
        $this->assertNotEmpty($failed);
        $this->assertSame('RuntimeException', $failed[0]->context['exception']);
        $this->assertStringNotContainsString('mail.example.internal', json_encode($failed[0]->context));
    }

    // ------------------------------------------------------------------
    // Verification switched off by config
    // ------------------------------------------------------------------

    public function test_when_verification_is_disabled_new_accounts_are_verified_and_no_code_is_sent(): void
    {
        config(['ballspot.auth.require_email_verification' => false]);
        Notification::fake();

        $res = $this->postJson('/api/register', $this->payload);

        $res->assertStatus(201)->assertJsonPath('email_verified', true);
        Notification::assertNothingSent();
        $this->withToken($res->json('token'))->getJson('/api/profile/stats')->assertOk();
    }

    public function test_when_verification_is_disabled_existing_unverified_users_are_not_locked_out(): void
    {
        config(['ballspot.auth.require_email_verification' => false]);
        $user = User::factory()->unverified()->create(['password' => bcrypt('password123')]);

        $login = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123']);
        $login->assertOk()->assertJsonStructure(['token'])->assertJsonMissingPath('requires_email_verification');

        // The `verified` gate must honour the same switch, or every protected
        // endpoint 403s for accounts created while verification was on.
        $this->withToken($login->json('token'))->getJson('/api/profile/stats')->assertOk();
        $this->withToken($login->json('token'))->getJson('/api/me')->assertOk()->assertJsonPath('data.email_verified', true);
    }

    public function test_when_verification_is_enabled_the_gate_still_blocks_unverified_users(): void
    {
        config(['ballspot.auth.require_email_verification' => true]);
        $user  = User::factory()->unverified()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)->getJson('/api/profile/stats')->assertStatus(403);
    }
}
