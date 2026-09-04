<?php

namespace Tests\Feature;

use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use App\Notifications\LoginVerificationCodeNotification;
use App\Support\AppLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * The exact fresh "Create account → code → verify" path the app takes, plus
 * every way a stale session from a previous account could confuse it.
 */
class FreshRegistrationVerificationTest extends TestCase
{
    use RefreshDatabase;

    private TestHandler $records;

    private array $payload = [
        'name' => 'Fresh Player', 'username' => 'freshplayer',
        'email' => 'Fresh.Player@Example.com', 'password' => 'password123',
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

    private function lastCode(User $user): string
    {
        $code = null;
        Notification::assertSentTo($user, EmailVerificationCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;
            return true;
        });
        $this->assertNotNull($code);

        return $code;
    }

    /** @return array{0:User,1:string,2:string} user, token, code */
    private function registerFresh(array $headers = []): array
    {
        Notification::fake();
        $res  = $this->withHeaders($headers)->postJson('/api/register', $this->payload);
        $res->assertStatus(201)->assertJsonPath('email_verified', false)->assertJsonPath('code_sent', true);
        $user = User::where('email', $this->payload['email'])->firstOrFail();

        return [$user, $res->json('token'), $this->lastCode($user)];
    }

    // ------------------------------------------------------------------
    // The happy path, exactly as the app does it
    // ------------------------------------------------------------------

    public function test_fresh_register_creates_exactly_one_valid_hashed_code(): void
    {
        [$user, , $code] = $this->registerFresh();

        Notification::assertSentToTimes($user, EmailVerificationCodeNotification::class, 1);
        Notification::assertNotSentTo($user, LoginVerificationCodeNotification::class);

        $rows = EmailVerificationCode::where('user_id', $user->id)->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertNull($row->consumed_at);
        $this->assertSame(0, $row->attempts);
        $this->assertTrue($row->expires_at->isFuture());
        $this->assertNotSame($code, $row->code_hash);
        $this->assertTrue(Hash::check($code, $row->code_hash));
        $this->assertTrue($row->isUsable(5));
        $this->assertNotEmpty($this->logged('auth.verification_sent'));
    }

    public function test_the_returned_token_verifies_the_exact_emailed_code(): void
    {
        [$user, $token, $code] = $this->registerFresh();

        $res = $this->withToken($token)->postJson('/api/email/verify', ['code' => $code]);

        $res->assertOk()
            ->assertJsonPath('email_verified', true)
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', $this->payload['email']);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertSame($user->id, $this->logged('auth.verification_completed')[0]->context['user_id']);
    }

    public function test_the_latest_db_row_is_the_one_the_email_carries(): void
    {
        [$user, , $code] = $this->registerFresh();

        $latest = EmailVerificationCode::where('user_id', $user->id)->latest('code_sent_at')->latest('id')->first();
        $this->assertTrue(Hash::check($code, $latest->code_hash));
    }

    public function test_verified_user_can_use_protected_endpoints_and_log_in_without_a_code(): void
    {
        [$user, $token, $code] = $this->registerFresh();

        $this->withToken($token)->getJson('/api/profile/stats')->assertStatus(403);
        $this->withToken($token)->postJson('/api/email/verify', ['code' => $code])->assertOk();
        $this->withToken($token)->getJson('/api/profile/stats')->assertOk();
        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('data.email_verified', true);

        Notification::fake();
        $this->postJson('/api/login', ['email' => $this->payload['email'], 'password' => 'password123'])
            ->assertOk()->assertJsonStructure(['token'])->assertJsonMissingPath('requires_email_verification');
        Notification::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // Code normalisation
    // ------------------------------------------------------------------

    public function test_code_with_surrounding_whitespace_is_accepted(): void
    {
        [, $token, $code] = $this->registerFresh();

        $this->withToken($token)->postJson('/api/email/verify', ['code' => "  {$code}\n"])->assertOk();
    }

    public function test_code_sent_as_a_json_number_is_accepted(): void
    {
        // Force a code with no leading zero so it survives as an integer.
        [$user, $token, $code] = $this->registerFresh();
        if ($code[0] === '0') {
            $this->markTestSkipped('random code starts with 0 — integer form would drop it; string form is covered elsewhere');
        }

        $this->withToken($token)->postJson('/api/email/verify', ['code' => (int) $code])->assertOk();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_wrong_code_does_not_consume_or_invalidate_the_valid_one(): void
    {
        [$user, $token, $code] = $this->registerFresh();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->withToken($token)->postJson('/api/email/verify', ['code' => $wrong])
            ->assertStatus(422)->assertJsonPath('reason', 'wrong_code');

        $row = EmailVerificationCode::where('user_id', $user->id)->first();
        $this->assertNull($row->consumed_at);
        $this->assertSame(1, $row->attempts);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());

        $this->withToken($token)->postJson('/api/email/verify', ['code' => $code])->assertOk();
    }

    public function test_multiple_unconsumed_codes_all_work_for_a_new_user(): void
    {
        [$user, $token, $first] = $this->registerFresh();

        $this->travel(61)->seconds();
        Notification::fake();
        $this->withToken($token)->postJson('/api/email/verification-notification')->assertOk();
        $second = $this->lastCode($user);

        $this->assertSame(2, EmailVerificationCode::where('user_id', $user->id)->whereNull('consumed_at')->count());
        // Either email works; using the second here, the first is covered in
        // EmailVerificationHardeningTest.
        $this->withToken($token)->postJson('/api/email/verify', ['code' => $second])->assertOk();
        $this->assertSame(0, EmailVerificationCode::where('user_id', $user->id)->whereNull('consumed_at')->count());
        $this->assertNotSame($first, $second);
    }

    // ------------------------------------------------------------------
    // Stale session from a previous account
    // ------------------------------------------------------------------

    public function test_a_stale_bearer_token_on_the_register_request_is_ignored(): void
    {
        $previous = User::factory()->create();
        $stale    = $previous->createToken('mobile')->plainTextToken;

        [$user, $token] = $this->registerFresh(['Authorization' => "Bearer {$stale}"]);

        $this->assertNotSame($previous->id, $user->id);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->assertSame(0, EmailVerificationCode::where('user_id', $previous->id)->count());
    }

    public function test_previous_users_token_cannot_verify_the_new_users_code(): void
    {
        $previous = User::factory()->unverified()->create();
        $stale    = $previous->createToken('mobile')->plainTextToken;
        [$user, , $code] = $this->registerFresh();

        // The app sends the email it is verifying for; the server notices the
        // token belongs to someone else and says so instead of "invalid code".
        $res = $this->withToken($stale)->postJson('/api/email/verify', [
            'code'  => $code,
            'email' => $this->payload['email'],
        ]);

        $res->assertStatus(409)
            ->assertJsonPath('reason', 'session_mismatch')
            ->assertJsonPath('message', 'This code belongs to a different account than the one signed in on this device. Please log in again with the account you just created.');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertFalse($previous->fresh()->hasVerifiedEmail());
        $this->assertSame('session_mismatch', $this->logged('auth.verification_failed')[0]->context['reason']);
        $this->assertArrayNotHasKey('email', $this->logged('auth.verification_failed')[0]->context);
    }

    public function test_previous_users_token_without_email_hint_fails_as_a_wrong_code_not_a_verification(): void
    {
        $previous = User::factory()->unverified()->create();
        $stale    = $previous->createToken('mobile')->plainTextToken;
        [$user, , $code] = $this->registerFresh();

        $this->withToken($stale)->postJson('/api/email/verify', ['code' => $code])
            ->assertStatus(422)->assertJsonPath('reason', 'no_code');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertFalse($previous->fresh()->hasVerifiedEmail());
    }

    public function test_email_hint_is_compared_case_insensitively_and_trimmed(): void
    {
        [$user, $token, $code] = $this->registerFresh();

        $this->withToken($token)->postJson('/api/email/verify', [
            'code'  => $code,
            'email' => '  fresh.player@example.COM ',
        ])->assertOk();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_verify_status_endpoint_tells_the_app_which_account_the_token_belongs_to(): void
    {
        [$user, $token] = $this->registerFresh();

        $this->withToken($token)->getJson('/api/email/verification-status')
            ->assertOk()
            ->assertJsonPath('email', $this->payload['email'])
            ->assertJsonPath('email_verified', false)
            ->assertJsonPath('has_usable_code', true)
            ->assertJsonPath('can_resend', false)
            ->assertJsonStructure(['resend_available_in_seconds', 'code_expires_in_seconds']);
    }

    // ------------------------------------------------------------------
    // Login must not send codes needlessly
    // ------------------------------------------------------------------

    public function test_unverified_login_skips_sending_when_a_usable_code_exists_and_logs_it(): void
    {
        [$user] = $this->registerFresh();

        $this->travel(5)->minutes();
        Notification::fake();
        $this->postJson('/api/login', ['email' => $this->payload['email'], 'password' => 'password123'])
            ->assertOk()->assertJsonPath('code_sent', false);

        Notification::assertNothingSent();
        $skipped = $this->logged('auth.verification_skipped');
        $this->assertSame('usable_code_exists', $skipped[0]->context['reason']);
        $this->assertSame($user->id, $skipped[0]->context['user_id']);
    }

    public function test_switching_accounts_leaves_no_cross_account_verification_state(): void
    {
        // Account A registers and verifies.
        [$a, $tokenA, $codeA] = $this->registerFresh();
        $this->withToken($tokenA)->postJson('/api/email/verify', ['code' => $codeA])->assertOk();

        // Account B registers on the same device (fresh guard resolution).
        $this->app['auth']->forgetGuards();
        Notification::fake();
        $res = $this->postJson('/api/register', array_merge($this->payload, ['email' => 'second@example.com', 'username' => 'second']));
        $res->assertStatus(201);
        $b     = User::where('email', 'second@example.com')->firstOrFail();
        $codeB = $this->lastCode($b);

        $this->assertSame(0, EmailVerificationCode::where('user_id', $a->id)->whereNull('consumed_at')->count());
        $this->assertSame(1, EmailVerificationCode::where('user_id', $b->id)->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($res->json('token'))->postJson('/api/email/verify', ['code' => $codeB, 'email' => 'second@example.com'])->assertOk();
        $this->assertTrue($b->fresh()->hasVerifiedEmail());
    }

    public function test_when_verification_is_disabled_fresh_register_enters_the_app_directly(): void
    {
        config(['ballspot.auth.require_email_verification' => false]);
        Notification::fake();

        $res = $this->postJson('/api/register', $this->payload);

        $res->assertStatus(201)->assertJsonPath('email_verified', true)->assertJsonPath('code_sent', false);
        Notification::assertNothingSent();
        $this->withToken($res->json('token'))->getJson('/api/leagues')->assertOk();
    }

    public function test_failure_logs_carry_diagnostic_context_but_never_the_code(): void
    {
        [, $token, $code] = $this->registerFresh();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->withToken($token)->postJson('/api/email/verify', ['code' => $wrong])->assertStatus(422);

        $failed = $this->logged('auth.verification_failed')[0]->context;
        $this->assertSame('wrong_code', $failed['reason']);
        $this->assertSame(1, $failed['live_codes']);
        $this->assertArrayHasKey('latest_code_age_seconds', $failed);
        $this->assertSame(1, $failed['attempts']);

        $dump = json_encode(array_map(fn ($r) => $r->context, $this->records->getRecords()));
        $this->assertStringNotContainsString($code, $dump);
        $this->assertStringNotContainsString($wrong, $dump);
        $this->assertStringNotContainsString(strtolower($this->payload['email']), strtolower($dump));
    }
}
