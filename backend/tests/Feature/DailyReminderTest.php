<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\PushToken;
use App\Models\Sport;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DailyReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ballspot.notifications.push_enabled', true);
        config()->set('ballspot.notifications.daily_reminder_push_enabled', true);
        Carbon::setTestNow(Carbon::parse('2026-08-10 19:05:00', 'UTC'));
    }

    /** Expo accepts everything. Registered per-test: first-registered stub wins. */
    private function fakeExpoOk(): void
    {
        Http::fake(['*' => Http::response(['data' => [['status' => 'ok']]], 200)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeActiveDaily(string $date = '2026-08-10'): DailyChallenge
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $challenge = Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => 'Reminder Challenge ' . $date,
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/test.jpg',
        ]);

        return DailyChallenge::create([
            'challenge_id'   => $challenge->id,
            'challenge_date' => $date,
            'status'         => 'active',
        ]);
    }

    /** Opted-in user with a push token whose reminder_time has just passed. */
    private function makeCandidate(string $reminderTime = '19:00', ?string $tz = null): User
    {
        $user = User::factory()->create();
        PushToken::create(['user_id' => $user->id, 'token' => 'ExponentPushToken[u' . $user->id . ']']);
        $user->notificationSettings()->update([
            'daily_reminder_enabled' => true,
            'reminder_time'          => $reminderTime,
            'timezone'               => $tz,
        ]);

        return $user;
    }

    public function test_sends_reminder_to_opted_in_user_with_token_and_unplayed_daily(): void
    {
        $this->fakeExpoOk();
        $this->makeActiveDaily();
        $user = $this->makeCandidate();

        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(
            '2026-08-10',
            $user->fresh()->notificationSetting->last_daily_reminder_date?->toDateString()
        );
    }

    public function test_no_reminder_after_daily_already_played(): void
    {
        $this->fakeExpoOk();
        $daily = $this->makeActiveDaily();
        $user = $this->makeCandidate();
        $daily->guesses()->create([
            'user_id'       => $user->id,
            'guess_x_ratio' => 0.5,
            'guess_y_ratio' => 0.5,
            'distance'      => 0,
            'score'         => 100,
            'submitted_at'  => now(),
        ]);

        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_disabled_daily_reminders_do_not_send(): void
    {
        $this->fakeExpoOk();
        $this->makeActiveDaily();
        $user = $this->makeCandidate();
        $user->notificationSettings()->update(['daily_reminder_enabled' => false]);

        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_users_without_tokens_are_skipped(): void
    {
        $this->fakeExpoOk();
        $this->makeActiveDaily();
        $user = $this->makeCandidate();
        PushToken::where('user_id', $user->id)->delete();

        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_anonymized_users_are_skipped(): void
    {
        $this->fakeExpoOk();
        $this->makeActiveDaily();
        $user = $this->makeCandidate();
        $user->forceFill(['anonymized_at' => now()])->save();

        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_no_duplicate_reminder_same_day(): void
    {
        $this->fakeExpoOk();
        $this->makeActiveDaily();
        $this->makeCandidate();

        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();
        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_reminder_time_in_future_is_not_sent_yet(): void
    {
        $this->fakeExpoOk();
        $this->makeActiveDaily();
        $this->makeCandidate('21:00');

        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_reminder_older_than_window_is_not_sent(): void
    {
        $this->fakeExpoOk();
        $this->makeActiveDaily();
        // 19:05 UTC is more than 60 minutes past a 17:00 reminder.
        $this->makeCandidate('17:00');

        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_user_timezone_is_respected(): void
    {
        $this->fakeExpoOk();
        $this->makeActiveDaily();
        // 19:05 UTC = 15:05 in New York (EDT, UTC-4 in August), so 15:00 is in-window.
        $this->makeCandidate('15:00', 'America/New_York');

        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_invalid_timezone_falls_back_to_utc(): void
    {
        $this->fakeExpoOk();
        $this->makeActiveDaily();
        $this->makeCandidate('19:00', 'Not/AZone');

        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_flag_off_sends_nothing(): void
    {
        $this->fakeExpoOk();
        config()->set('ballspot.notifications.daily_reminder_push_enabled', false);
        $this->makeActiveDaily();
        $this->makeCandidate();

        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_no_active_daily_sends_nothing(): void
    {
        $this->fakeExpoOk();
        // A scheduled-but-not-activated daily must not trigger reminders either.
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $challenge = Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => 'Scheduled Only',
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/test.jpg',
        ]);
        DailyChallenge::create([
            'challenge_id'   => $challenge->id,
            'challenge_date' => '2026-08-10',
            'status'         => 'scheduled',
        ]);
        $this->makeCandidate();

        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_dead_tokens_are_pruned_during_reminder_send(): void
    {
        Http::fake([
            '*' => Http::response(['data' => [
                ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']],
            ]], 200),
        ]);

        $this->makeActiveDaily();
        $user = $this->makeCandidate();

        $this->artisan('ballspot:send-daily-reminders')->assertSuccessful();

        $this->assertDatabaseMissing('push_tokens', ['user_id' => $user->id]);
    }
}

