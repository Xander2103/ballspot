<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\PushReceipt;
use App\Models\PushToken;
use App\Models\Sport;
use App\Models\User;
use App\Services\ExpoPushService;
use App\Services\PushReceiptService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Expo tickets → receipts → token hygiene, and the admin single-device test.
 * A ticket "ok" is acceptance by Expo only; the receipt is the APNs/FCM answer.
 */
class PushDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const SEND = 'https://exp.host/--/api/v2/push/send';
    private const RECEIPTS = 'https://exp.host/--/api/v2/push/getReceipts';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ballspot.notifications.push_enabled', true);
        config()->set('ballspot.notifications.expo_access_token', null);
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function token(User $user, string $platform = 'ios', ?string $suffix = null): PushToken
    {
        return PushToken::create([
            'user_id'  => $user->id,
            'token'    => 'ExponentPushToken[' . ($suffix ?? ($platform . $user->id . 'abcdWXYZ')) . ']',
            'platform' => $platform,
            'last_seen_at' => now(),
        ]);
    }

    private function message(PushToken $t): array
    {
        return ['to' => $t->token, 'title' => 'T', 'body' => 'B', 'sound' => 'default'];
    }

    // ---------------------------------------------------------------- tickets

    public function test_ok_tickets_are_recorded_for_receipt_check_without_the_token(): void
    {
        $ios = $this->token(User::factory()->create(), 'ios');
        $android = $this->token(User::factory()->create(), 'android');
        Http::fake([self::SEND => Http::response(['data' => [
            ['status' => 'ok', 'id' => 'ticket-ios'],
            ['status' => 'ok', 'id' => 'ticket-android'],
        ]], 200)]);

        $out = app(ExpoPushService::class)->sendMessages([$this->message($ios), $this->message($android)], PushReceipt::SOURCE_TEST);

        $this->assertSame(2, $out['sent']);
        $this->assertSame([['status' => 'ok', 'id' => 'ticket-ios', 'error' => null], ['status' => 'ok', 'id' => 'ticket-android', 'error' => null]], $out['tickets']);
        $this->assertDatabaseHas('push_receipts', ['ticket_id' => 'ticket-ios', 'push_token_id' => $ios->id, 'platform' => 'ios', 'source' => 'test', 'status' => 'pending']);
        $this->assertDatabaseHas('push_receipts', ['ticket_id' => 'ticket-android', 'push_token_id' => $android->id, 'platform' => 'android']);
        $this->assertNotContains('token', \Schema::getColumnListing('push_receipts'));
    }

    public function test_ticket_without_id_is_still_counted_sent_but_not_recorded(): void
    {
        $t = $this->token(User::factory()->create());
        Http::fake([self::SEND => Http::response(['data' => [['status' => 'ok']]], 200)]);

        $out = app(ExpoPushService::class)->sendMessages([$this->message($t)]);

        $this->assertSame(1, $out['sent']);
        $this->assertSame(0, PushReceipt::count());
    }

    public function test_ticket_device_not_registered_removes_token_but_credential_errors_keep_it(): void
    {
        $dead = $this->token(User::factory()->create(), 'ios');
        $misconfigured = $this->token(User::factory()->create(), 'android');
        Http::fake([self::SEND => Http::response(['data' => [
            ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']],
            ['status' => 'error', 'details' => ['error' => 'InvalidCredentials']],
        ]], 200)]);

        $out = app(ExpoPushService::class)->sendMessages([$this->message($dead), $this->message($misconfigured)]);

        $this->assertSame(2, $out['failed']);
        $this->assertSame(['DeviceNotRegistered' => 1, 'InvalidCredentials' => 1], $out['reasons']);
        $this->assertDatabaseMissing('push_tokens', ['id' => $dead->id]);
        $this->assertDatabaseHas('push_tokens', ['id' => $misconfigured->id]);
        $this->assertSame(0, PushReceipt::count());
    }

    public function test_http_error_fails_the_batch_and_records_nothing(): void
    {
        $t = $this->token(User::factory()->create());
        Http::fake([self::SEND => Http::response(['errors' => [['code' => 'INTERNAL_SERVER_ERROR']]], 500)]);

        $out = app(ExpoPushService::class)->sendMessages([$this->message($t)]);

        $this->assertSame(['sent' => 0, 'failed' => 1], ['sent' => $out['sent'], 'failed' => $out['failed']]);
        $this->assertSame(['http_500' => 1], $out['reasons']);
        $this->assertDatabaseHas('push_tokens', ['id' => $t->id]);
        $this->assertSame(0, PushReceipt::count());
    }

    public function test_rate_limit_is_retried_and_then_succeeds_once(): void
    {
        $t = $this->token(User::factory()->create());
        Http::fakeSequence(self::SEND)
            ->push(['errors' => [['code' => 'TOO_MANY_REQUESTS']]], 429)
            ->push(['data' => [['status' => 'ok', 'id' => 'after-retry']]], 200);

        $out = app(ExpoPushService::class)->sendMessages([$this->message($t)]);

        $this->assertSame(1, $out['sent']);
        Http::assertSentCount(2);
        $this->assertDatabaseHas('push_receipts', ['ticket_id' => 'after-retry']);
    }

    public function test_connection_error_is_not_retried_so_a_push_is_never_doubled(): void
    {
        $t = $this->token(User::factory()->create());
        Http::fake([self::SEND => fn () => throw new ConnectionException('timeout')]);

        $out = app(ExpoPushService::class)->sendMessages([$this->message($t)]);

        $this->assertSame(1, $out['failed']);
        $this->assertSame(['http_exception' => 1], $out['reasons']);
        Http::assertSentCount(0); // the faked closure throws before a response is recorded
    }

    public function test_access_token_is_sent_only_when_configured(): void
    {
        $t = $this->token(User::factory()->create());
        Http::fake([self::SEND => Http::response(['data' => [['status' => 'ok', 'id' => 'x1']]], 200)]);

        app(ExpoPushService::class)->sendMessages([$this->message($t)]);
        Http::assertSent(fn (Request $r) => ! $r->hasHeader('Authorization'));

        config()->set('ballspot.notifications.expo_access_token', 'expo-secret-value');
        app(ExpoPushService::class)->sendMessages([$this->message($t)]);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer expo-secret-value'));
    }

    public function test_receipt_recording_failure_never_breaks_the_send(): void
    {
        $t = $this->token(User::factory()->create());
        \Schema::drop('push_receipts');
        Http::fake([self::SEND => Http::response(['data' => [['status' => 'ok', 'id' => 'x']]], 200)]);

        $out = app(ExpoPushService::class)->sendMessages([$this->message($t)]);

        $this->assertSame(1, $out['sent']);
    }

    public function test_announcement_and_daily_reminder_tag_their_receipts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-03 19:05:00', 'UTC'));
        config()->set('ballspot.notifications.daily_reminder_push_enabled', true);

        $user = User::factory()->create();
        $this->token($user);
        $user->notificationSettings()->update(['daily_reminder_enabled' => true, 'reminder_time' => '19:00', 'timezone' => 'UTC']);
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $challenge = Challenge::create([
            'sport_id' => $sport->id, 'title' => 'Receipt daily', 'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5,
            'difficulty' => 'easy', 'status' => 'active', 'hidden_image_path' => 'challenges/hidden/t.jpg',
        ]);
        DailyChallenge::create(['challenge_id' => $challenge->id, 'challenge_date' => '2026-11-03', 'status' => 'active']);

        Http::fakeSequence(self::SEND)
            ->push(['data' => [['status' => 'ok', 'id' => 'daily-1']]], 200)
            ->push(['data' => [['status' => 'ok', 'id' => 'announce-1']]], 200);

        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();
        app(ExpoPushService::class)->sendAnnouncement(AdminNotification::create([
            'title' => 'Hi', 'body' => 'There', 'target_type' => AdminNotification::TARGET_ALL, 'status' => AdminNotification::STATUS_DRAFT,
        ]));

        $this->assertDatabaseHas('push_receipts', ['ticket_id' => 'daily-1', 'source' => 'daily_reminder']);
        $this->assertDatabaseHas('push_receipts', ['ticket_id' => 'announce-1', 'source' => 'announcement']);
    }

    // --------------------------------------------------------------- receipts

    private function pending(string $ticket, ?PushToken $t, int $minutesAgo = 30): PushReceipt
    {
        $r = PushReceipt::create([
            'ticket_id' => $ticket, 'push_token_id' => $t?->id, 'platform' => $t?->platform,
            'source' => 'test', 'status' => 'pending',
        ]);
        $r->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

        return $r;
    }

    public function test_receipt_check_records_ok_and_errors_and_removes_only_dead_tokens(): void
    {
        $okToken = $this->token(User::factory()->create(), 'ios');
        $deadToken = $this->token(User::factory()->create(), 'ios', 'dead1234');
        $credsToken = $this->token(User::factory()->create(), 'android');
        $this->pending('t-ok', $okToken);
        $this->pending('t-dead', $deadToken);
        $this->pending('t-creds', $credsToken);
        $this->pending('t-notready', $okToken);

        Http::fake([self::RECEIPTS => Http::response(['data' => [
            't-ok'    => ['status' => 'ok'],
            't-dead'  => ['status' => 'error', 'message' => '…', 'details' => ['error' => 'DeviceNotRegistered']],
            't-creds' => ['status' => 'error', 'details' => ['error' => 'InvalidCredentials']],
        ]], 200)]);

        $out = app(PushReceiptService::class)->checkPending();

        $this->assertSame(3, $out['checked']);
        $this->assertSame(1, $out['ok']);
        $this->assertSame(2, $out['error']);
        $this->assertSame(1, $out['still_pending']);
        $this->assertSame(1, $out['tokens_removed']);
        $this->assertSame(['DeviceNotRegistered' => 1, 'InvalidCredentials' => 1], $out['errors']);

        $this->assertDatabaseHas('push_receipts', ['ticket_id' => 't-ok', 'status' => 'ok']);
        $this->assertDatabaseHas('push_receipts', ['ticket_id' => 't-dead', 'status' => 'error', 'error' => 'DeviceNotRegistered', 'push_token_id' => null]);
        $this->assertDatabaseHas('push_receipts', ['ticket_id' => 't-creds', 'status' => 'error', 'error' => 'InvalidCredentials']);
        $this->assertDatabaseHas('push_receipts', ['ticket_id' => 't-notready', 'status' => 'pending']);
        $this->assertDatabaseMissing('push_tokens', ['id' => $deadToken->id]);
        $this->assertDatabaseHas('push_tokens', ['id' => $credsToken->id]); // config problem, not the device
        $this->assertDatabaseHas('push_tokens', ['id' => $okToken->id]);

        Http::assertSent(fn (Request $r) => $r->url() === self::RECEIPTS
            && $r['ids'] === ['t-ok', 't-dead', 't-creds', 't-notready']);
    }

    public function test_receipt_check_waits_for_the_delay_expires_old_and_prunes(): void
    {
        $t = $this->token(User::factory()->create());
        $this->pending('too-young', $t, 2);
        $this->pending('too-old', $t, 26 * 60);
        $ancient = $this->pending('ancient', $t, 40 * 24 * 60);
        $ancient->update(['status' => 'ok']);
        Http::fake();

        $out = app(PushReceiptService::class)->checkPending();

        Http::assertNothingSent();
        $this->assertSame(1, $out['expired']);
        $this->assertSame(1, $out['pruned']);
        $this->assertDatabaseHas('push_receipts', ['ticket_id' => 'too-young', 'status' => 'pending']);
        $this->assertDatabaseHas('push_receipts', ['ticket_id' => 'too-old', 'status' => 'expired']);
        $this->assertDatabaseMissing('push_receipts', ['ticket_id' => 'ancient']);
    }

    public function test_receipt_request_failure_leaves_tickets_pending(): void
    {
        $t = $this->token(User::factory()->create());
        $this->pending('p1', $t);
        Http::fake([self::RECEIPTS => Http::response([], 500)]);

        $out = app(PushReceiptService::class)->checkPending();

        $this->assertTrue($out['request_failed']);
        $this->assertDatabaseHas('push_receipts', ['ticket_id' => 'p1', 'status' => 'pending']);
        $this->assertDatabaseHas('push_tokens', ['id' => $t->id]);
    }

    public function test_receipt_command_and_schedule(): void
    {
        Http::fake();
        $this->artisan('ballspot:check-push-receipts')->assertSuccessful();

        $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode("\n");
        $this->assertStringContainsString('ballspot:check-push-receipts', $events);
    }

    // ------------------------------------------------------ admin test command

    public function test_push_test_dry_run_sends_nothing_and_masks_tokens(): void
    {
        $user = User::factory()->create();
        $t = $this->token($user, 'ios', 'SECRETMIDDLEPARTzz9Q');
        Http::fake();

        Artisan::call('ballspot:push-test', ['user' => $user->id]);
        $output = Artisan::output();

        Http::assertNothingSent();
        $this->assertStringNotContainsString('SECRETMIDDLEPART', $output);
        $this->assertStringContainsString('ExponentPushToken[SE…zz9Q]', $output);
        $this->assertStringContainsString((string) $t->id, $output);
        $this->assertStringContainsString('Dry run', $output);
    }

    public function test_push_test_sends_exactly_one_message_to_the_one_device_and_checks_receipt(): void
    {
        $user = User::factory()->create();
        $t = $this->token($user, 'ios');
        $other = $this->token(User::factory()->create(), 'ios'); // another player: must never be touched
        Http::fake([
            self::SEND     => Http::response(['data' => [['status' => 'ok', 'id' => 'test-ticket']]], 200),
            self::RECEIPTS => Http::response(['data' => ['test-ticket' => ['status' => 'ok']]], 200),
        ]);

        $this->artisan('ballspot:push-test', ['user' => $user->id, '--send' => true, '--wait' => 0])
            ->expectsConfirmation("Send exactly ONE test notification to user #{$user->id}, device #{$t->id} (ios)? Only do this for your own test device.", 'yes')
            ->expectsOutputToContain('Expo ticket: status=ok id=test-ticket')
            ->expectsOutputToContain('Expo receipt: ok')
            ->expectsChoice('Did the notification appear on the phone?', 'yes', ['yes', 'no', 'not checked'])
            ->assertSuccessful();

        $sends = Http::recorded(fn (Request $r) => $r->url() === self::SEND);
        $this->assertCount(1, $sends);
        $payload = $sends[0][0]->data();
        $this->assertCount(1, $payload);
        $this->assertSame($t->token, $payload[0]['to']);
        $this->assertNotSame($other->token, $payload[0]['to']);
        $this->assertDatabaseHas('push_receipts', ['ticket_id' => 'test-ticket', 'source' => 'test', 'status' => 'ok']);
    }

    public function test_push_test_refuses_without_confirmation(): void
    {
        $user = User::factory()->create();
        $t = $this->token($user);
        Http::fake();

        $this->artisan('ballspot:push-test', ['user' => $user->id, '--send' => true])
            ->expectsConfirmation("Send exactly ONE test notification to user #{$user->id}, device #{$t->id} (ios)? Only do this for your own test device.", 'no')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_push_test_never_sends_non_interactively(): void
    {
        $user = User::factory()->create();
        $this->token($user);
        Http::fake();

        Artisan::call('ballspot:push-test', ['user' => $user->id, '--send' => true, '--no-interaction' => true]);

        Http::assertNothingSent();
    }

    public function test_push_test_requires_a_device_choice_and_ownership(): void
    {
        $user = User::factory()->create();
        $this->token($user, 'ios');
        $this->token($user, 'android');
        $foreign = $this->token(User::factory()->create(), 'ios');
        Http::fake();

        $this->artisan('ballspot:push-test', ['user' => $user->id, '--send' => true])
            ->expectsOutputToContain('several devices')->assertFailed();
        $this->artisan('ballspot:push-test', ['user' => $user->id, '--send' => true, '--token' => $foreign->id])
            ->expectsOutputToContain('does not belong')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_push_test_refuses_deleted_accounts_missing_tokens_and_disabled_push(): void
    {
        $deleted = User::factory()->create();
        $this->token($deleted);
        $deleted->forceFill(['anonymized_at' => now()])->save();
        $noToken = User::factory()->create();
        $ok = User::factory()->create();
        $this->token($ok);
        Http::fake();

        $this->artisan('ballspot:push-test', ['user' => $deleted->id, '--send' => true])->assertFailed();
        $this->artisan('ballspot:push-test', ['user' => $noToken->id, '--send' => true])
            ->expectsOutputToContain('No push token')->assertFailed();

        config()->set('ballspot.notifications.push_enabled', false);
        $this->artisan('ballspot:push-test', ['user' => $ok->id, '--send' => true])
            ->expectsOutputToContain('BALLPICKER_PUSH_ENABLED is false')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_push_test_reports_credential_error_from_receipt(): void
    {
        $user = User::factory()->create();
        $t = $this->token($user, 'android');
        Http::fake([
            self::SEND     => Http::response(['data' => [['status' => 'ok', 'id' => 'andr']]], 200),
            self::RECEIPTS => Http::response(['data' => ['andr' => ['status' => 'error', 'details' => ['error' => 'InvalidCredentials']]]], 200),
        ]);

        $this->artisan('ballspot:push-test', ['user' => $user->id, '--send' => true, '--wait' => 0])
            ->expectsConfirmation("Send exactly ONE test notification to user #{$user->id}, device #{$t->id} (android)? Only do this for your own test device.", 'yes')
            ->expectsOutputToContain('error=InvalidCredentials')
            ->expectsChoice('Did the notification appear on the phone?', 'no', ['yes', 'no', 'not checked'])
            ->assertFailed();

        $this->assertDatabaseHas('push_tokens', ['id' => $t->id]);
    }

    // ------------------------------------------------------------------ audit

    public function test_push_audit_prints_counts_per_platform_and_no_tokens(): void
    {
        $this->token(User::factory()->create(), 'ios', 'IOSPRIVATEaaaa');
        $this->token(User::factory()->create(), 'ios', 'IOSPRIVATEbbbb');
        $this->token(User::factory()->create(), 'android', 'ANDPRIVATEcccc');

        Artisan::call('ballspot:push-audit');
        $output = Artisan::output();

        $this->assertStringNotContainsString('PRIVATE', $output);
        $this->assertMatchesRegularExpression('/\|\s*ios\s*\|\s*2\s*\|\s*2\s*\|/', $output);
        $this->assertMatchesRegularExpression('/\|\s*android\s*\|\s*1\s*\|\s*1\s*\|/', $output);
        $this->assertStringContainsString('BALLPICKER_DAILY_REMINDER_PUSH_ENABLED', $output);
    }
}
