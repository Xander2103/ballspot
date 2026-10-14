<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use App\Notifications\LoginVerificationCodeNotification;
use App\Services\CaptchaVerifier;
use App\Support\AppLog;
use App\Support\AuthAbuseGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * v1.9.13 anti-bot / email-abuse protection for registration and the auth
 * email flows. Every refusal must be a friendly, stable 429 (or a generic
 * 422 for the honeypot), nothing may leak codes/tokens into logs, and normal
 * registration / login / verification must keep working exactly as before.
 */
class AuthAbuseProtectionTest extends TestCase
{
    use RefreshDatabase;

    private TestHandler $records;

    protected function setUp(): void
    {
        parent::setUp();
        $this->records = new TestHandler();
        Log::channel(AppLog::CHANNEL)->getLogger()->setHandlers([$this->records]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function payload(string $tag = 'one', array $overrides = []): array
    {
        return array_merge([
            'name'           => 'Player ' . $tag,
            'username'       => 'player_' . $tag,
            'email'          => $tag . '@example.com',
            'password'       => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
            'age_confirmed'  => true,
        ], $overrides);
    }

    private function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    /** @return string[] event names logged so far */
    private function events(): array
    {
        return array_map(fn ($r) => $r->message, $this->records->getRecords());
    }

    private function logText(): string
    {
        return implode("\n", array_map(fn ($r) => $r->message . ' ' . json_encode($r->context), $this->records->getRecords()));
    }

    private function emailedCode(User $user): string
    {
        $code = null;
        Notification::assertSentTo($user, EmailVerificationCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });

        return (string) $code;
    }

    private function twoFactorUser(): User
    {
        $user = User::factory()->create(['password' => bcrypt('password123')]);
        $user->two_factor_enabled = true;
        $user->save();

        return $user;
    }

    // ------------------------------------------------------------------
    // Normal flows keep working
    // ------------------------------------------------------------------

    public function test_normal_registration_still_works_and_sends_one_code(): void
    {
        Notification::fake();

        $res = $this->postJson('/api/register', $this->payload('fresh'));

        $res->assertStatus(201)
            ->assertJsonPath('email_verified', false)
            ->assertJsonPath('code_sent', true)
            ->assertJsonStructure(['user', 'token']);
        $user = User::where('email', 'fresh@example.com')->firstOrFail();
        Notification::assertSentToTimes($user, EmailVerificationCodeNotification::class, 1);
        $this->assertContains('auth.registered', $this->events());
    }

    public function test_verified_login_still_works_without_any_email(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => bcrypt('password123')]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertOk()
            ->assertJsonStructure(['user', 'token']);
        Notification::assertNothingSent();
    }

    public function test_unverified_user_still_reaches_the_verification_screen(): void
    {
        Notification::fake();
        $this->postJson('/api/register', $this->payload('pending'))->assertStatus(201);

        $login = $this->postJson('/api/login', ['email' => 'pending@example.com', 'password' => 'password123']);

        $login->assertOk()
            ->assertJsonPath('requires_email_verification', true)
            ->assertJsonPath('email_verified', false)
            ->assertJsonStructure(['token', 'user']);
        $this->withToken($login->json('token'))->getJson('/api/email/verification-status')
            ->assertOk()
            ->assertJsonPath('email', 'pending@example.com')
            ->assertJsonPath('has_usable_code', true);
    }

    // ------------------------------------------------------------------
    // Registration ceilings
    // ------------------------------------------------------------------

    public function test_register_request_throttle_per_ip_answers_a_stable_code(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/register', ['email' => "burst{$i}@example.com"]); // invalid, still counted
        }

        $res = $this->postJson('/api/register', $this->payload('late'));

        $res->assertStatus(429)
            ->assertJsonPath('code', 'registration_rate_limited')
            ->assertJsonStructure(['message', 'code', 'retry_after']);
        $this->assertIsInt($res->json('retry_after'));
        $this->assertContains('register.rate_limited', $this->events());
        $this->assertDatabaseMissing('users', ['email' => 'late@example.com']);
    }

    public function test_register_accounts_per_ip_per_hour_are_capped_counting_successes_only(): void
    {
        config(['ballspot.abuse.register.per_ip_hour' => 2]);
        Notification::fake();

        // Typos never count toward the account budget.
        $this->postJson('/api/register', $this->payload('typo', ['password_confirmation' => 'nope']))->assertStatus(422);
        $this->postJson('/api/register', $this->payload('typo', ['email' => 'not-an-email']))->assertStatus(422);

        $this->postJson('/api/register', $this->payload('a1'))->assertStatus(201);
        $this->postJson('/api/register', $this->payload('a2'))->assertStatus(201);

        $third = $this->postJson('/api/register', $this->payload('a3'));
        $third->assertStatus(429)
            ->assertJsonPath('code', 'registration_rate_limited')
            ->assertHeader('Retry-After');
        $this->assertSame('Too many attempts. Please wait a bit and try again.', $third->json('message'));
        $this->assertDatabaseMissing('users', ['email' => 'a3@example.com']);
        $this->assertContains('register.rate_limited', $this->events());

        // Another IP is unaffected.
        $this->fromIp('10.9.8.7')->postJson('/api/register', $this->payload('a4'))->assertStatus(201);
    }

    public function test_register_accounts_per_ip_per_day_are_capped(): void
    {
        config(['ballspot.abuse.register.per_ip_hour' => 50, 'ballspot.abuse.register.per_ip_day' => 2]);
        Notification::fake();

        $this->postJson('/api/register', $this->payload('d1'))->assertStatus(201);
        $this->postJson('/api/register', $this->payload('d2'))->assertStatus(201);
        $this->postJson('/api/register', $this->payload('d3'))->assertStatus(429)->assertJsonPath('code', 'registration_rate_limited');
    }

    public function test_register_attempts_per_email_are_capped_across_ips(): void
    {
        config(['ballspot.abuse.register.attempts_per_email_hour' => 3]);

        for ($i = 0; $i < 3; $i++) {
            // Same (invalid) payload for one address from three different IPs.
            $this->fromIp("10.0.0.{$i}")->postJson('/api/register', ['email' => 'Target@Example.com', 'username' => "u{$i}"])->assertStatus(422);
        }

        $this->fromIp('10.0.0.99')->postJson('/api/register', $this->payload('target', ['email' => 'target@example.com']))
            ->assertStatus(429)
            ->assertJsonPath('code', 'registration_rate_limited');
        $this->assertDatabaseMissing('users', ['email' => 'target@example.com']);
    }

    public function test_same_unverified_email_never_triggers_more_verification_emails(): void
    {
        Notification::fake();
        $this->postJson('/api/register', $this->payload('dup'))->assertStatus(201);
        $user = User::where('email', 'dup@example.com')->firstOrFail();

        // Replayed registrations: the unique rule stops them, no email leaves.
        for ($i = 0; $i < 3; $i++) {
            $this->fromIp("10.1.0.{$i}")->postJson('/api/register', $this->payload('dup', ['username' => "dup{$i}"]))
                ->assertStatus(422)->assertJsonPath('code', 'email_taken');
        }
        // Repeated unverified logins reuse the code already in the inbox.
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/login', ['email' => 'dup@example.com', 'password' => 'password123'])
                ->assertOk()->assertJsonPath('code_sent', false);
        }

        Notification::assertSentToTimes($user, EmailVerificationCodeNotification::class, 1);
    }

    // ------------------------------------------------------------------
    // Verification email ceilings
    // ------------------------------------------------------------------

    public function test_resend_verification_cooldown_still_applies(): void
    {
        Notification::fake();
        $token = $this->postJson('/api/register', $this->payload('cool'))->json('token');

        $this->withToken($token)->postJson('/api/email/verification-notification')->assertStatus(422);
    }

    public function test_verification_emails_per_address_per_hour_are_capped_and_codes_stay_valid(): void
    {
        config(['ballspot.abuse.verification_email.per_email_hour' => 3]);
        Notification::fake();
        $token = $this->postJson('/api/register', $this->payload('cap'))->json('token'); // email 1
        $user  = User::where('email', 'cap@example.com')->firstOrFail();

        for ($i = 0; $i < 2; $i++) { // emails 2 and 3
            $this->travel(61)->seconds();
            $this->withToken($token)->postJson('/api/email/verification-notification')->assertOk();
        }
        $this->assertSame(3, AuthAbuseGuard::emailSendsThisHour(AuthAbuseGuard::SCOPE_VERIFICATION, 'cap@example.com'));

        $this->travel(61)->seconds();
        $blocked = $this->withToken($token)->postJson('/api/email/verification-notification');
        $blocked->assertStatus(429)
            ->assertJsonPath('code', 'verification_resend_rate_limited')
            ->assertJsonStructure(['message', 'code', 'retry_after'])
            ->assertHeader('Retry-After');
        Notification::assertSentToTimes($user, EmailVerificationCodeNotification::class, 3);
        $this->assertContains('verification.resend_rate_limited', $this->events());

        // The user is not stranded: the newest emailed code still verifies.
        $this->withToken($token)->postJson('/api/email/verify', ['code' => $this->emailedCode($user)])->assertOk();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_verification_emails_per_address_per_day_are_capped(): void
    {
        config(['ballspot.abuse.verification_email.per_email_hour' => 10, 'ballspot.abuse.verification_email.per_email_day' => 2]);
        Notification::fake();
        $token = $this->postJson('/api/register', $this->payload('daily'))->json('token');

        $this->travel(61)->seconds();
        $this->withToken($token)->postJson('/api/email/verification-notification')->assertOk();
        $this->travel(61)->seconds();
        $this->withToken($token)->postJson('/api/email/verification-notification')
            ->assertStatus(429)->assertJsonPath('code', 'verification_resend_rate_limited');
    }

    public function test_unverified_login_past_the_ceiling_does_not_send_but_still_answers_normally(): void
    {
        // Daily ceiling of one: the hourly bucket would expire with the time travel below.
        config(['ballspot.abuse.verification_email.per_email_hour' => 10, 'ballspot.abuse.verification_email.per_email_day' => 1]);
        Notification::fake();
        $this->postJson('/api/register', $this->payload('quiet'))->assertStatus(201); // the one allowed email
        $user = User::where('email', 'quiet@example.com')->firstOrFail();

        $this->travel(2)->hours(); // the only code expired → login would normally send a fresh one
        $login = $this->postJson('/api/login', ['email' => 'quiet@example.com', 'password' => 'password123']);

        $login->assertOk()->assertJsonPath('requires_email_verification', true)->assertJsonPath('code_sent', false);
        Notification::assertSentToTimes($user, EmailVerificationCodeNotification::class, 1);
        $this->assertContains('verification.resend_rate_limited', $this->events());
    }

    // ------------------------------------------------------------------
    // Password reset ceilings (generic, no enumeration)
    // ------------------------------------------------------------------

    public function test_forgot_password_per_email_hourly_limit_is_identical_for_known_and_unknown_addresses(): void
    {
        config(['ballspot.abuse.password_reset.per_email_hour' => 2]);
        $user = User::factory()->create();

        foreach (['nobody@example.com', $user->email] as $email) {
            $responses = [];
            for ($i = 0; $i < 3; $i++) {
                $responses[] = $this->fromIp("10.2.0.{$i}")->postJson('/api/forgot-password', ['email' => $email]);
            }
            $responses[0]->assertOk()->assertJsonPath('message', __('messages.auth.reset_link_sent'));
            $responses[1]->assertOk();
            $responses[2]->assertStatus(429)
                ->assertJsonPath('code', 'password_reset_rate_limited')
                ->assertJsonStructure(['message', 'code', 'retry_after']);
        }
        $this->assertContains('password_reset.rate_limited', $this->events());
        $this->assertStringNotContainsString('example.com', $this->logText());
    }

    public function test_forgot_password_per_ip_hourly_limit(): void
    {
        config(['ballspot.abuse.password_reset.per_ip_hour' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/forgot-password', ['email' => "someone{$i}@example.com"])->assertOk();
        }
        $this->postJson('/api/forgot-password', ['email' => 'someone9@example.com'])
            ->assertStatus(429)->assertJsonPath('code', 'password_reset_rate_limited');
    }

    // ------------------------------------------------------------------
    // Login 2FA email ceilings (only for accounts that already opted in)
    // ------------------------------------------------------------------

    public function test_login_2fa_codes_per_email_are_capped_but_the_flow_keeps_working(): void
    {
        config(['ballspot.abuse.login_2fa.per_email_hour' => 2]);
        Notification::fake();
        $user = $this->twoFactorUser();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertOk()->assertJsonPath('requires_2fa', true);
        $second = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertOk()->assertJsonPath('requires_2fa', true);
        Notification::assertSentToTimes($user, LoginVerificationCodeNotification::class, 2);

        $third = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123']);
        $third->assertStatus(429)
            ->assertJsonPath('code', 'login_2fa_rate_limited')
            ->assertJsonStructure(['message', 'code', 'retry_after']);
        Notification::assertSentToTimes($user, LoginVerificationCodeNotification::class, 2);
        $this->assertContains('login_2fa.rate_limited', $this->events());

        // The pending session is still completable with its emailed code.
        $code = null;
        Notification::assertSentTo($user, LoginVerificationCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });
        $this->postJson('/api/login/verify', ['verification_id' => $second->json('verification_id'), 'code' => $code])
            ->assertOk()->assertJsonStructure(['token']);
    }

    public function test_login_2fa_resend_is_cooldown_limited_and_then_capped(): void
    {
        config(['ballspot.abuse.login_2fa.per_email_hour' => 1]);
        Notification::fake();
        $user = $this->twoFactorUser();
        $id   = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123'])->json('verification_id');

        // Inside the 60s cooldown: the existing cooldown answer.
        $this->postJson('/api/login/resend-code', ['verification_id' => $id])->assertStatus(422);

        $this->travel(61)->seconds();
        $this->postJson('/api/login/resend-code', ['verification_id' => $id])
            ->assertStatus(429)->assertJsonPath('code', 'login_2fa_rate_limited');
        Notification::assertSentToTimes($user, LoginVerificationCodeNotification::class, 1);
    }

    // ------------------------------------------------------------------
    // Honeypot
    // ------------------------------------------------------------------

    public function test_filled_honeypot_is_rejected_generically_logged_and_blocks_the_ip(): void
    {
        Notification::fake();

        $res = $this->postJson('/api/register', $this->payload('bot', ['website' => 'http://spam.example']));

        $res->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->assertStringNotContainsStringIgnoringCase('honeypot', $res->getContent());
        $this->assertDatabaseMissing('users', ['email' => 'bot@example.com']);
        Notification::assertNothingSent();
        $this->assertContains('honeypot.triggered', $this->events());

        // The same IP cannot create an account for the next hour, even with a clean payload.
        $this->postJson('/api/register', $this->payload('bot2'))
            ->assertStatus(429)->assertJsonPath('code', 'registration_rate_limited');
        // A real user elsewhere is unaffected, and an EMPTY honeypot (what the app sends) is fine.
        $this->fromIp('10.3.0.1')->postJson('/api/register', $this->payload('human', ['website' => '']))->assertStatus(201);
    }

    // ------------------------------------------------------------------
    // CAPTCHA (feature-flagged, off by default)
    // ------------------------------------------------------------------

    public function test_captcha_disabled_by_default_allows_normal_registration_and_ignores_a_token(): void
    {
        Notification::fake();
        Http::fake();
        $this->assertFalse(config('ballspot.captcha.enabled'));

        $this->postJson('/api/register', $this->payload('nocaptcha'))->assertStatus(201);
        $this->postJson('/api/register', $this->payload('withtoken', ['captcha_token' => 'ignored']))->assertStatus(201);
        Http::assertNothingSent();
        $this->getJson('/api/config')->assertOk()->assertJsonPath('captcha_required', false)->assertJsonPath('captcha_site_key', null);
    }

    public function test_captcha_enabled_requires_a_token(): void
    {
        config(['ballspot.captcha.enabled' => true, 'ballspot.captcha.secret' => 'test-secret', 'ballspot.captcha.site_key' => '0xPUBLIC']);
        Http::fake();

        $res = $this->postJson('/api/register', $this->payload('needs'));

        $res->assertStatus(422)
            ->assertJsonPath('code', 'registration_captcha_required')
            ->assertJsonPath('codes.captcha_token', 'registration_captcha_required');
        $this->assertDatabaseMissing('users', ['email' => 'needs@example.com']);
        Http::assertNothingSent();
        $this->getJson('/api/config')->assertOk()
            ->assertJsonPath('captcha_required', true)
            ->assertJsonPath('captcha_provider', 'turnstile')
            ->assertJsonPath('captcha_site_key', '0xPUBLIC');
    }

    public function test_captcha_enabled_rejects_an_invalid_token_and_accepts_a_valid_one(): void
    {
        config(['ballspot.captcha.enabled' => true, 'ballspot.captcha.secret' => 'test-secret']);
        Notification::fake();
        Http::fake([
            CaptchaVerifier::TURNSTILE_VERIFY_URL => Http::sequence()
                ->push(['success' => false, 'error-codes' => ['invalid-input-response']])
                ->push(['success' => true, 'hostname' => 'ballpicker.vanmalderstudio.be']),
        ]);

        $this->postJson('/api/register', $this->payload('badtoken', ['captcha_token' => 'bad-token-value']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'registration_captcha_failed');
        $this->assertDatabaseMissing('users', ['email' => 'badtoken@example.com']);
        $this->assertContains('register.captcha_failed', $this->events());
        $this->assertStringNotContainsString('bad-token-value', $this->logText());
        $this->assertStringNotContainsString('test-secret', $this->logText());

        $this->postJson('/api/register', $this->payload('goodtoken', ['captcha_token' => 'good-token-value']))->assertStatus(201);
        Http::assertSent(fn ($request) => $request['secret'] === 'test-secret' && $request['response'] === 'good-token-value');
    }

    public function test_captcha_fails_closed_when_the_provider_is_unreachable(): void
    {
        config(['ballspot.captcha.enabled' => true, 'ballspot.captcha.secret' => 'test-secret']);
        Http::fake([CaptchaVerifier::TURNSTILE_VERIFY_URL => Http::response('upstream error', 503)]);

        $this->postJson('/api/register', $this->payload('outage', ['captcha_token' => 'whatever']))
            ->assertStatus(422)->assertJsonPath('code', 'registration_captcha_failed');
    }

    public function test_config_never_exposes_the_captcha_secret(): void
    {
        config(['ballspot.captcha.enabled' => true, 'ballspot.captcha.secret' => 'SUPERSECRETCAPTCHA', 'ballspot.captcha.site_key' => '0xPUBLIC']);

        $this->assertStringNotContainsString('SUPERSECRETCAPTCHA', $this->getJson('/api/config')->getContent());
    }

    // ------------------------------------------------------------------
    // Logging hygiene
    // ------------------------------------------------------------------

    public function test_no_raw_codes_tokens_or_passwords_are_logged_across_the_abuse_paths(): void
    {
        config([
            'ballspot.abuse.verification_email.per_email_hour' => 1,
            'ballspot.abuse.password_reset.per_email_hour'     => 1,
            'ballspot.abuse.login_2fa.per_email_hour'          => 1,
        ]);
        Notification::fake();

        $token = $this->postJson('/api/register', $this->payload('hygiene', ['website' => '']))->json('token');
        $user  = User::where('email', 'hygiene@example.com')->firstOrFail();
        $code  = $this->emailedCode($user);
        $this->travel(61)->seconds();
        $this->withToken($token)->postJson('/api/email/verification-notification')->assertStatus(429);

        $this->postJson('/api/forgot-password', ['email' => 'hygiene@example.com'])->assertOk();
        $this->postJson('/api/forgot-password', ['email' => 'hygiene@example.com'])->assertStatus(429);

        $twoFactor = $this->twoFactorUser();
        $this->postJson('/api/login', ['email' => $twoFactor->email, 'password' => 'password123'])->assertOk();
        $loginCode = null;
        Notification::assertSentTo($twoFactor, LoginVerificationCodeNotification::class, function ($n) use (&$loginCode) {
            $loginCode = $n->code;

            return true;
        });
        $this->postJson('/api/login', ['email' => $twoFactor->email, 'password' => 'password123'])->assertStatus(429);

        $this->postJson('/api/register', $this->payload('bot', ['website' => 'filled']))->assertStatus(422);
        $this->postJson('/api/register', $this->payload('bot2'))->assertStatus(429); // honeypot-blocked IP

        $log = $this->logText();
        foreach ([$code, $loginCode, 'password123', 'hygiene@example.com', $twoFactor->email, $token] as $secret) {
            $this->assertNotEmpty($secret);
            $this->assertStringNotContainsString($secret, $log, 'secret leaked into the events log');
        }
        foreach (['register.rate_limited', 'verification.resend_rate_limited', 'password_reset.rate_limited', 'login_2fa.rate_limited', 'honeypot.triggered'] as $event) {
            $this->assertContains($event, $this->events(), "expected {$event}");
        }
    }

    public function test_diagnostics_lists_the_abuse_counters(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $snapshot = app(\App\Services\DiagnosticsService::class)->snapshot();
        $this->assertSame(array_keys(\App\Services\DiagnosticsService::ABUSE_EVENTS), array_column($snapshot['abuse']['rows'], 'event'));
        $this->assertFalse($snapshot['abuse']['captcha_enabled']);

        $this->actingAs($admin)->get('/admin/diagnostics')
            ->assertOk()
            ->assertSee('Auth email abuse protection')
            ->assertSee('Registration rate limits')
            ->assertSee('Verification email rate limits')
            ->assertSee('Password reset rate limits')
            ->assertSee('Honeypot triggers')
            ->assertSee('<code>register.rate_limited</code>', false);
    }
}
