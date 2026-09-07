<?php

namespace Tests\Feature;

use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use App\Support\AppLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Tests\TestCase;

/**
 * Production regression (2026-09-07): POST /api/register created the user and
 * sent the verification email, then answered 500. POST /api/login with an
 * unknown account answered 500 as well. Both paths write an operational event
 * via AppLog; a verified login (the only auth path that logs nothing) worked.
 *
 * Root cause: the `events` log channel could not be written (the day's rotated
 * events file was not writable by the web user) and the exception escaped the
 * logging call into the request. Logging must never break a user request, so
 * this suite drives the real flows with a deliberately broken events channel.
 */
class LoggingFailureResilienceTest extends TestCase
{
    use RefreshDatabase;

    private TestHandler $fallback;

    private array $payload = [
        'name' => 'Fresh Player', 'username' => 'freshplayer',
        'email' => 'fresh.player@example.com', 'password' => 'password123',
        'terms_accepted' => true, 'age_confirmed' => true,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // The events channel behaves like Monolog does when the log file cannot
        // be opened for appending.
        $throwing = new class extends AbstractProcessingHandler {
            protected function write(LogRecord $record): void
            {
                throw new \UnexpectedValueException(
                    'The stream or file "storage/logs/ballpicker-events-2026-09-07.log" could not be opened in append mode: Failed to open stream: Permission denied'
                );
            }
        };
        Log::channel(AppLog::CHANNEL)->getLogger()->setHandlers([$throwing]);

        // Whatever AppLog falls back to must land here instead of on disk.
        $this->fallback = new TestHandler();
        Log::channel((string) config('logging.default'))->getLogger()->setHandlers([$this->fallback]);
    }

    public function test_fresh_register_succeeds_after_the_mail_is_sent_even_when_event_logging_is_broken(): void
    {
        Notification::fake();

        $res = $this->postJson('/api/register', $this->payload);

        $res->assertStatus(201)
            ->assertJsonPath('email_verified', false)
            ->assertJsonPath('code_sent', true)
            ->assertJsonStructure(['token', 'user' => ['id', 'username']]);

        $user = User::where('email', $this->payload['email'])->firstOrFail();
        Notification::assertSentToTimes($user, EmailVerificationCodeNotification::class, 1);
        $this->assertSame(1, EmailVerificationCode::where('user_id', $user->id)->whereNull('consumed_at')->count());

        // The token works for the verification screen's first call.
        $this->withToken($res->json('token'))->getJson('/api/email/verification-status')
            ->assertOk()->assertJsonPath('has_usable_code', true);
    }

    public function test_failed_login_is_a_422_not_a_500_when_event_logging_is_broken(): void
    {
        $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'whatever-1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_unverified_login_still_issues_a_code_when_event_logging_is_broken(): void
    {
        $user = User::factory()->unverified()->create(['password' => bcrypt('password123')]);
        Notification::fake();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('requires_email_verification', true)
            ->assertJsonPath('code_sent', true);

        Notification::assertSentToTimes($user, EmailVerificationCodeNotification::class, 1);
        $this->assertSame(1, EmailVerificationCode::where('user_id', $user->id)->count());
    }

    public function test_resend_still_works_when_event_logging_is_broken(): void
    {
        $user  = User::factory()->unverified()->create();
        $token = $user->createToken('mobile')->plainTextToken;
        Notification::fake();

        $this->withToken($token)->postJson('/api/email/verification-notification')->assertOk();

        Notification::assertSentToTimes($user, EmailVerificationCodeNotification::class, 1);
        $this->assertSame(1, EmailVerificationCode::where('user_id', $user->id)->count());
    }

    public function test_the_logging_failure_itself_is_reported_once_per_request_on_the_default_channel(): void
    {
        $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'whatever-1'])->assertStatus(422);

        $failures = array_values(array_filter(
            $this->fallback->getRecords(),
            fn ($r) => $r->message === 'applog.write_failed'
        ));
        $this->assertNotEmpty($failures, 'the broken events channel must be reported on the default channel');
        $this->assertSame('UnexpectedValueException', $failures[0]->context['exception']);
        $this->assertStringContainsString('could not be opened', $failures[0]->context['error']);
        $this->assertSame('auth.login_failed', $failures[0]->context['event']);
        // The original context is preserved (sanitized) so the event is not lost.
        $this->assertSame('unknown_account', $failures[0]->context['context']['reason']);
    }

    public function test_applog_never_throws_even_when_every_channel_is_broken(): void
    {
        $throwing = new class extends AbstractProcessingHandler {
            protected function write(LogRecord $record): void
            {
                throw new \RuntimeException('disk full');
            }
        };
        Log::channel((string) config('logging.default'))->getLogger()->setHandlers([$throwing]);

        AppLog::event('test.event', ['user_id' => 1]);
        AppLog::warn('test.warn', ['user_id' => 1]);
        AppLog::error('test.error', ['user_id' => 1]);

        $this->assertTrue(true, 'reached without an exception');
    }
}
