<?php

namespace Tests\Feature;

use App\Mail\OpsAlertMail;
use App\Mail\Transport\BrevoTransport;
use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use App\Notifications\LoginVerificationCodeNotification;
use App\Notifications\ResetPasswordNotification;
use App\Support\AppLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

/**
 * The Brevo mail transport, driven through Laravel's real mail stack. Every
 * HTTP response is mocked and stray requests are refused, so these tests can
 * never send a real email.
 */
class BrevoMailTransportTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'xkeysib-test-key-not-real';
    private const OK  = ['messageId' => '<202611011200.123@smtp-relay.mailin.fr>'];

    private TestHandler $records;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default'      => 'brevo',
            'mail.from.address' => 'info@vanmalderstudio.be',
            'mail.from.name'    => 'BallPicker',
            'services.brevo.key' => self::KEY,
        ]);

        Http::preventStrayRequests();

        $this->records = new TestHandler();
        Log::channel(AppLog::CHANNEL)->getLogger()->setHandlers([$this->records]);
    }

    private function fakeBrevo(int $status = 201, mixed $body = self::OK): void
    {
        Http::fake([BrevoTransport::ENDPOINT => Http::response($body, $status)]);
    }

    private function sendPlain(): void
    {
        Mail::raw('hello', fn (Message $m) => $m->to('player@example.com')->subject('Secret subject'));
    }

    /** The JSON bodies posted to Brevo, oldest first. */
    private function sentPayloads(): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0]->data())->values()->all();
    }

    private function logDump(): string
    {
        return json_encode(array_map(fn ($r) => [$r->message, $r->context], $this->records->getRecords()));
    }

    private function failureLog(): array
    {
        foreach ($this->records->getRecords() as $record) {
            if ($record->message === 'mail.brevo.failed') {
                return $record->context;
            }
        }
        $this->fail('mail.brevo.failed was not logged');
    }

    // ---- request mapping -------------------------------------------------

    public function test_a_full_message_maps_onto_the_brevo_payload(): void
    {
        $this->fakeBrevo();

        Mail::send([], [], function (Message $m) {
            $m->to('ann@example.com', 'Ann')
                ->cc('cc@example.com', 'Carbon')
                ->bcc('bcc@example.com')
                ->replyTo('reply@example.com', 'Reply Desk')
                ->subject('Hallo wereld');
            $m->html('<p>Hallo</p>');
            $m->text('Hallo');
            $m->attachData('%PDF-bytes', 'report.pdf', ['mime' => 'application/pdf']);
            $m->getHeaders()->addTextHeader('X-Ballpicker-Flow', 'test');
            $m->getHeaders()->add(new TagHeader('transactional'));
        });

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'https://api.brevo.com/v3/smtp/email'
            && $r->hasHeader('api-key', self::KEY)
            && $r->hasHeader('Accept', 'application/json')
            && $r->hasHeader('Content-Type', 'application/json'));

        $this->assertSame([
            'sender'      => ['email' => 'info@vanmalderstudio.be', 'name' => 'BallPicker'],
            'to'          => [['email' => 'ann@example.com', 'name' => 'Ann']],
            'cc'          => [['email' => 'cc@example.com', 'name' => 'Carbon']],
            'bcc'         => [['email' => 'bcc@example.com']],
            'replyTo'     => ['email' => 'reply@example.com', 'name' => 'Reply Desk'],
            'subject'     => 'Hallo wereld',
            'htmlContent' => '<p>Hallo</p>',
            'textContent' => 'Hallo',
            'attachment'  => [['name' => 'report.pdf', 'content' => base64_encode('%PDF-bytes')]],
            'headers'     => ['X-Ballpicker-Flow' => 'test'],
            'tags'        => ['transactional'],
        ], $this->sentPayloads()[0]);
    }

    public function test_a_text_only_message_sends_no_html_and_no_empty_fields(): void
    {
        $this->fakeBrevo();

        $this->sendPlain();

        $this->assertSame([
            'sender'      => ['email' => 'info@vanmalderstudio.be', 'name' => 'BallPicker'],
            'to'          => [['email' => 'player@example.com']],
            'subject'     => 'Secret subject',
            'textContent' => 'hello',
        ], $this->sentPayloads()[0]);
    }

    public function test_multiple_to_recipients_all_stay_in_to(): void
    {
        $this->fakeBrevo();

        Mail::raw('x', fn (Message $m) => $m->to(['a@example.com', 'b@example.com'])->subject('s'));

        $this->assertSame([['email' => 'a@example.com'], ['email' => 'b@example.com']], $this->sentPayloads()[0]['to']);
    }

    public function test_a_file_attachment_is_read_and_a_nameless_one_gets_a_name(): void
    {
        $this->fakeBrevo();
        $path = tempnam(sys_get_temp_dir(), 'bp');
        file_put_contents($path, "line one\r\nline two");

        Mail::raw('x', function (Message $m) use ($path) {
            $m->to('a@example.com')->subject('s')->attach($path, ['as' => 'notes.txt', 'mime' => 'text/plain']);
            $m->getSymfonyMessage()->attach('raw-bytes', null, 'image/png');
        });
        unlink($path);

        $this->assertSame([
            ['name' => 'notes.txt', 'content' => base64_encode("line one\r\nline two")],
            ['name' => 'attachment-2.png', 'content' => base64_encode('raw-bytes')],
        ], $this->sentPayloads()[0]['attachment']);
    }

    public function test_an_inline_attachment_still_arrives_and_is_flagged_in_the_log(): void
    {
        $this->fakeBrevo();

        Mail::send([], [], function (Message $m) {
            $m->to('a@example.com')->subject('s');
            $m->html('<img src="' . $m->embedData('png-bytes', 'logo.png', 'image/png') . '">');
        });

        $this->assertSame('logo.png', $this->sentPayloads()[0]['attachment'][0]['name']);
        $this->assertStringContainsString('mail.brevo.inline_attachment_not_embedded', $this->logDump());
    }

    public function test_a_long_display_name_is_cut_to_brevos_maximum(): void
    {
        $this->fakeBrevo();

        Mail::raw('x', fn (Message $m) => $m->to('a@example.com', str_repeat('é', 80))->subject('s'));

        $this->assertSame(str_repeat('é', 70), $this->sentPayloads()[0]['to'][0]['name']);
    }

    public function test_the_request_uses_the_configured_timeouts_and_never_follows_a_redirect(): void
    {
        config(['services.brevo.timeout' => 7, 'services.brevo.connect_timeout' => 3]);
        $options = null;
        Http::fake(function ($request, array $opts) use (&$options) {
            $options = $opts;

            return Http::response(self::OK, 201);
        });

        $this->sendPlain();

        $this->assertSame(7, $options['timeout']);
        $this->assertSame(3, $options['connect_timeout']);
        $this->assertFalse($options['allow_redirects']);
    }

    public function test_a_zero_timeout_cannot_turn_into_waiting_forever(): void
    {
        config(['services.brevo.timeout' => 0, 'services.brevo.connect_timeout' => '']);
        $options = null;
        Http::fake(function ($request, array $opts) use (&$options) {
            $options = $opts;

            return Http::response(self::OK, 201);
        });

        $this->sendPlain();

        $this->assertSame(1, $options['timeout']);
        $this->assertSame(1, $options['connect_timeout']);
    }

    // ---- success ---------------------------------------------------------

    public function test_a_201_is_a_send_and_exposes_brevos_message_id(): void
    {
        $this->fakeBrevo();
        Event::fake([MessageSent::class]);

        $sent = Mail::raw('x', fn (Message $m) => $m->to('a@example.com')->subject('s'));

        $this->assertSame(self::OK['messageId'], $sent->getMessageId());
        Event::assertDispatched(MessageSent::class);
    }

    // ---- failures must never look like a send ----------------------------

    public static function rejectedResponses(): array
    {
        return [
            'invalid parameter' => [400, ['code' => 'invalid_parameter', 'message' => 'bad']],
            'bad key'           => [401, ['code' => 'unauthorized', 'message' => 'Key not found']],
            'no credits'        => [402, ['code' => 'not_enough_credits', 'message' => 'none left']],
            'not acceptable'    => [406, ['code' => 'not_acceptable', 'message' => 'no']],
            'rate limited'      => [429, ['code' => 'too_many_requests', 'message' => 'slow down']],
            'server error'      => [500, ['code' => 'internal_error', 'message' => 'oops']],
            'gateway html'      => [502, '<html>Bad gateway</html>'],
            'wrong 2xx'         => [200, self::OK],
            'scheduled, not sent' => [202, self::OK],
            'redirect'          => [302, ''],
        ];
    }

    #[DataProvider('rejectedResponses')]
    public function test_any_response_but_201_throws_and_is_not_reported_as_sent(int $status, mixed $body): void
    {
        $this->fakeBrevo($status, $body);
        Event::fake([MessageSent::class]);

        try {
            $this->sendPlain();
            $this->fail('A failed Brevo response was treated as a successful send.');
        } catch (TransportException $e) {
            $this->assertStringContainsString("HTTP {$status}", $e->getMessage());
        }

        Event::assertNotDispatched(MessageSent::class);
        $this->assertSame($status, $this->failureLog()['http_status']);
        $this->assertSame('api_rejected', $this->failureLog()['reason']);
        $this->assertStringNotContainsString('mail.brevo.sent', $this->logDump());
    }

    public static function unprovenSends(): array
    {
        return [
            'empty body'    => [''],
            'html page'     => ['<html>proxy page</html>'],
            'no message id' => [['status' => 'ok']],
            'blank id'      => [['messageId' => '']],
            'non-string id' => [['messageId' => 12345]],
        ];
    }

    #[DataProvider('unprovenSends')]
    public function test_a_201_without_a_message_id_is_not_a_send(mixed $body): void
    {
        $this->fakeBrevo(201, $body);
        Event::fake([MessageSent::class]);

        try {
            $this->sendPlain();
            $this->fail('A 201 without a message id was treated as a successful send.');
        } catch (TransportException) {
        }

        Event::assertNotDispatched(MessageSent::class);
        $this->assertSame('invalid_response', $this->failureLog()['reason']);
    }

    public function test_a_timeout_or_connection_failure_throws(): void
    {
        Http::fake([BrevoTransport::ENDPOINT => Http::failedConnection('cURL error 28: Operation timed out')]);

        try {
            $this->sendPlain();
            $this->fail('A connection failure was treated as a successful send.');
        } catch (TransportException) {
        }

        $this->assertSame('connection_failed', $this->failureLog()['reason']);
        $this->assertStringContainsString('cURL error 28', $this->failureLog()['detail']);
    }

    public function test_a_missing_key_fails_without_calling_the_api(): void
    {
        config(['services.brevo.key' => null]);
        Mail::purge('brevo');
        $this->fakeBrevo();

        try {
            $this->sendPlain();
            $this->fail('Sending without a key was treated as a successful send.');
        } catch (TransportException $e) {
            $this->assertStringContainsString('BREVO_API_KEY', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_bcc_only_message_fails_clearly_without_calling_the_api(): void
    {
        $this->fakeBrevo();

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('To recipient');

        try {
            Mail::raw('x', fn (Message $m) => $m->bcc('hidden@example.com')->subject('s'));
        } finally {
            Http::assertNothingSent();
        }
    }

    // ---- secrets ---------------------------------------------------------

    public function test_logs_and_exceptions_never_carry_the_key_addresses_or_content(): void
    {
        $this->fakeBrevo(400, [
            'code'    => 'invalid_parameter',
            'message' => 'email player@example.com is not valid for key ' . self::KEY,
        ]);

        try {
            $this->sendPlain();
            $this->fail('Expected a TransportException.');
        } catch (TransportException $e) {
            $exception = $e->getMessage();
        }

        $log = $this->logDump();
        foreach ([$log, $exception] as $output) {
            $this->assertStringNotContainsString(self::KEY, $output);
            $this->assertStringNotContainsString('player@example.com', $output);
            $this->assertStringNotContainsString('Secret subject', $output);
        }
        // The operator still sees what Brevo objected to.
        $this->assertSame('invalid_parameter', $this->failureLog()['api_error']);
        $this->assertStringContainsString('is not valid', $this->failureLog()['api_message']);
    }

    public function test_the_success_log_has_counts_and_the_message_id_only(): void
    {
        $this->fakeBrevo();

        $this->sendPlain();

        $log = $this->logDump();
        $this->assertStringContainsString('mail.brevo.sent', $log);
        $this->assertStringContainsString('smtp-relay.mailin.fr', $log);
        $this->assertStringNotContainsString(self::KEY, $log);
        $this->assertStringNotContainsString('player@example.com', $log);
        $this->assertStringNotContainsString('Secret subject', $log);
    }

    public function test_the_transport_description_does_not_contain_the_key(): void
    {
        $this->assertSame('brevo+api://api.brevo.com', (string) Mail::mailer('brevo')->getSymfonyTransport());
    }

    // ---- the existing BallPicker emails ----------------------------------

    private function registerPlayer(): array
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Brevo Player', 'username' => 'brevoplayer', 'email' => 'brevoplayer@example.com',
            'password' => 'password123', 'terms_accepted' => true, 'age_confirmed' => true,
        ]);

        return [$response, User::where('email', 'brevoplayer@example.com')->firstOrFail()];
    }

    private function verifiedUser(array $attributes = []): User
    {
        return User::forceCreate($attributes + [
            'name' => 'Verified', 'username' => 'verified', 'email' => 'verified@example.com',
            'password' => Hash::make('password123'), 'email_verified_at' => now(),
        ]);
    }

    public function test_the_verification_email_carries_a_working_code(): void
    {
        $this->fakeBrevo();

        [$response, $user] = $this->registerPlayer();
        $response->assertStatus(201)->assertJsonPath('code_sent', true);

        $payload = $this->sentPayloads()[0];
        $this->assertSame([['email' => 'brevoplayer@example.com']], $payload['to']);
        $this->assertSame(trans('emails.verify.subject', ['app' => config('ballspot.app_name')]), $payload['subject']);
        $this->assertStringContainsString('<html', $payload['htmlContent']);
        $this->assertMatchesRegularExpression('/\b\d{6}\b/', $payload['htmlContent']);
        $this->assertSame(1, preg_match('/\b(\d{6})\b/', $payload['textContent'], $match));

        $this->withToken($response->json('token'))->postJson('/api/email/verify', ['code' => $match[1]])->assertOk();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_the_login_code_email_carries_a_working_code(): void
    {
        config(['ballspot.auth.force_login_2fa' => true]);
        $user = $this->verifiedUser();
        $this->fakeBrevo();

        $login = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123']);
        $login->assertOk()->assertJsonPath('requires_2fa', true);

        $this->assertSame(1, preg_match('/\b(\d{6})\b/', $this->sentPayloads()[0]['textContent'], $match));
        $this->postJson('/api/login/verify', ['verification_id' => $login->json('verification_id'), 'code' => $match[1]])
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_the_reset_email_carries_a_working_link_with_the_same_markup(): void
    {
        $user = $this->verifiedUser();
        $this->fakeBrevo();

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

        $payload = $this->sentPayloads()[0];
        $this->assertSame(1, preg_match('/[?&]token=([^&\s]+)/', $payload['textContent'], $match));
        $this->assertStringContainsString('email=' . urlencode($user->email), $payload['textContent']);
        // Same token in the HTML button (where & is escaped).
        $this->assertStringContainsString('token=' . $match[1] . '&amp;email=', $payload['htmlContent']);

        $this->postJson('/api/reset-password', [
            'email' => $user->email, 'token' => $match[1],
            'password' => 'newpassword123', 'password_confirmation' => 'newpassword123',
        ])->assertOk();
        $this->assertTrue(Hash::check('newpassword123', $user->fresh()->password));
    }

    public function test_emails_are_still_rendered_in_the_recipients_language(): void
    {
        $user = $this->verifiedUser(['preferred_language' => 'nl']);
        $this->fakeBrevo();

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

        $this->assertSame(
            trans('emails.reset.subject', ['app' => config('ballspot.app_name')], 'nl'),
            $this->sentPayloads()[0]['subject'],
        );
    }

    public function test_the_ops_alert_is_sent_as_html(): void
    {
        $this->fakeBrevo();

        $this->artisan('ballspot:send-alert', ['--subject' => '[BallPicker] DOWN', '--body' => 'http=503', '--to' => 'ops@example.com'])
            ->assertExitCode(0);

        $payload = $this->sentPayloads()[0];
        $this->assertSame('[BallPicker] DOWN', $payload['subject']);
        $this->assertStringContainsString('http=503', $payload['htmlContent']);
    }

    public function test_the_mailable_class_uses_the_default_brevo_mailer(): void
    {
        $this->fakeBrevo();

        Mail::to('ops@example.com')->send(new OpsAlertMail('s', 'b'));

        Http::assertSentCount(1);
    }

    public function test_the_alert_command_can_use_brevo_while_smtp_stays_the_default(): void
    {
        config(['mail.default' => 'smtp']);
        $this->fakeBrevo();

        $this->artisan('ballspot:send-alert', ['--mailer' => 'brevo', '--to' => 'ops@example.com', '--subject' => 's', '--body' => 'b'])
            ->assertExitCode(0);

        Http::assertSentCount(1);
    }

    public static function existingEmails(): array
    {
        return [
            'verification, en' => [fn () => new EmailVerificationCodeNotification('123456', 60), 'en'],
            'verification, nl' => [fn () => new EmailVerificationCodeNotification('123456', 60), 'nl'],
            'login code, en'   => [fn () => new LoginVerificationCodeNotification('654321', 10), 'en'],
            'login code, de'   => [fn () => new LoginVerificationCodeNotification('654321', 10), 'de'],
            'reset link, en'   => [fn () => new ResetPasswordNotification('reset-token-abc'), 'en'],
            'reset link, fr'   => [fn () => new ResetPasswordNotification('reset-token-abc'), 'fr'],
        ];
    }

    /** What the SMTP path would send is what Brevo gets: same subject, HTML, text and sender. */
    #[DataProvider('existingEmails')]
    public function test_brevo_receives_exactly_the_email_the_smtp_path_renders(\Closure $make, string $locale): void
    {
        $user = $this->verifiedUser(['preferred_language' => $locale]);
        $this->fakeBrevo();

        config(['mail.default' => 'array']);
        $user->notify($make());
        $rendered = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();

        config(['mail.default' => 'brevo']);
        $user->notify($make());
        $payload = $this->sentPayloads()[0];

        $this->assertSame($rendered->getSubject(), $payload['subject']);
        $this->assertSame($rendered->getHtmlBody(), $payload['htmlContent']);
        $this->assertSame($rendered->getTextBody(), $payload['textContent']);
        $this->assertSame($rendered->getFrom()[0]->getAddress(), $payload['sender']['email']);
        $this->assertSame($rendered->getTo()[0]->getAddress(), $payload['to'][0]['email']);
        // Nothing but the content: no stray headers end up at Brevo.
        $this->assertArrayNotHasKey('headers', $payload);
    }

    // ---- existing failure handling, now with a real Brevo rejection -------

    public function test_a_rejected_verification_email_reports_code_sent_false(): void
    {
        $this->fakeBrevo(401, ['code' => 'unauthorized', 'message' => 'Key not found']);

        [$response, $user] = $this->registerPlayer();

        $response->assertStatus(201)->assertJsonPath('code_sent', false);
        $this->assertSame(0, EmailVerificationCode::where('user_id', $user->id)->count());
    }

    public function test_a_rejected_login_code_is_the_stable_503_without_provider_details(): void
    {
        config(['ballspot.auth.force_login_2fa' => true]);
        $user = $this->verifiedUser();
        $this->fakeBrevo(500, ['code' => 'internal_error', 'message' => 'boom']);

        $response = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123']);

        $response->assertStatus(503)->assertJsonPath('code', 'two_factor_send_failed');
        $this->assertStringNotContainsStringIgnoringCase('brevo', $response->getContent());
        $this->assertDatabaseCount('login_verification_codes', 0);
    }

    public function test_a_rejected_reset_email_keeps_the_generic_answer_and_logs_send_failed(): void
    {
        $user = $this->verifiedUser();
        $this->fakeBrevo(429, ['code' => 'too_many_requests', 'message' => 'slow down']);

        $this->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('message', __('messages.auth.reset_link_sent'));

        $this->assertStringContainsString('send_failed', $this->logDump());
    }

    public function test_a_rejected_ops_alert_makes_the_command_fail(): void
    {
        $this->fakeBrevo(401, ['code' => 'unauthorized', 'message' => 'Key not found']);

        $this->artisan('ballspot:send-alert', ['--subject' => 's', '--body' => 'b', '--to' => 'ops@example.com'])
            ->assertExitCode(1);
    }

    // ---- rollback and configuration --------------------------------------

    public function test_rolling_back_to_smtp_stops_using_brevo(): void
    {
        config(['mail.default' => 'smtp']);

        $this->assertInstanceOf(EsmtpTransport::class, Mail::mailer()->getSymfonyTransport());
    }

    public function test_the_brevo_mailer_is_usable_while_smtp_stays_the_default(): void
    {
        config(['mail.default' => 'smtp']);
        $this->fakeBrevo();

        Mail::mailer('brevo')->raw('x', fn (Message $m) => $m->to('a@example.com')->subject('s'));

        Http::assertSentCount(1);
    }

    public function test_readiness_warns_when_brevo_is_selected_without_a_key_in_production(): void
    {
        config(['app.env' => 'production', 'services.brevo.key' => '']);

        $this->artisan('ballspot:store-readiness-check')
            ->expectsOutputToContain('BREVO_API_KEY is empty');
    }

    public function test_readiness_never_prints_the_key(): void
    {
        $this->artisan('ballspot:store-readiness-check')
            ->expectsOutputToContain('BREVO_API_KEY is set')
            ->doesntExpectOutputToContain(self::KEY);
    }
}
