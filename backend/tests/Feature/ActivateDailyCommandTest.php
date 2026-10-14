<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\Sport;
use App\Models\User;
use App\Services\DiagnosticsService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ballspot:activate-daily — flips TODAY's `scheduled` daily to `active`.
 *
 * Gated by BALLPICKER_AUTO_ACTIVATE_DAILIES (default false). The manual
 * Admin → Daily workflow must keep working regardless of the flag.
 */
class ActivateDailyCommandTest extends TestCase
{
    use RefreshDatabase;

    private function readyChallenge(string $title = 'Ready photo', array $attrs = []): Challenge
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football', 'status' => Sport::STATUS_ACTIVE]);

        return Challenge::create(array_merge([
            'sport_id'          => $sport->id,
            'title'             => $title,
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'usage_pool'        => Challenge::POOL_GENERAL,
            'hidden_image_path' => 'challenges/hidden/' . str_replace(' ', '-', strtolower($title)) . '.jpg',
        ], $attrs));
    }

    private function daily(string $date, string $status = 'scheduled', ?Challenge $challenge = null): DailyChallenge
    {
        return DailyChallenge::create([
            'challenge_id'   => ($challenge ?? $this->readyChallenge("Photo {$date}"))->id,
            'challenge_date' => $date,
            'status'         => $status,
        ]);
    }

    private function today(): string
    {
        return Carbon::today()->toDateString();
    }

    private function schedulerEvent(): Event
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $e) => str_contains((string) $e->command, 'ballspot:activate-daily'));

        $this->assertNotNull($event, 'the activation entry must stay registered so schedule:list shows it');

        return $event;
    }

    // ------------------------------------------------------------------
    // Flag
    // ------------------------------------------------------------------

    public function test_auto_activation_is_off_by_default(): void
    {
        $this->assertFalse((bool) config('ballspot.daily.auto_activate'));
        $this->assertFalse($this->schedulerEvent()->filtersPass($this->app));
    }

    public function test_command_does_nothing_when_the_flag_is_disabled(): void
    {
        config(['ballspot.daily.auto_activate' => false]);
        $daily = $this->daily($this->today());

        $this->artisan('ballspot:activate-daily')
            ->expectsOutputToContain('Automatic daily activation is OFF')
            ->assertExitCode(0);

        $this->assertSame('scheduled', $daily->fresh()->status);
    }

    public function test_force_option_activates_today_even_when_the_flag_is_disabled(): void
    {
        config(['ballspot.daily.auto_activate' => false]);
        $daily = $this->daily($this->today());

        $this->artisan('ballspot:activate-daily', ['--force' => true])->assertExitCode(0);

        $this->assertSame('active', $daily->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Behaviour with the flag on
    // ------------------------------------------------------------------

    public function test_scheduled_daily_for_today_becomes_active_when_the_flag_is_enabled(): void
    {
        config(['ballspot.daily.auto_activate' => true]);
        $daily = $this->daily($this->today());

        $this->artisan('ballspot:activate-daily')
            ->expectsOutputToContain("Activated today's daily")
            ->assertExitCode(0);

        $this->assertSame('active', $daily->fresh()->status);
        // The API now serves it.
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->getJson('/api/daily/today')
            ->assertOk()->assertJsonPath('has_daily', true);
    }

    public function test_future_scheduled_dailies_stay_scheduled(): void
    {
        config(['ballspot.daily.auto_activate' => true]);
        $today    = $this->daily($this->today());
        $tomorrow = $this->daily(Carbon::today()->addDay()->toDateString());
        $nextWeek = $this->daily(Carbon::today()->addDays(7)->toDateString());

        $this->artisan('ballspot:activate-daily')->assertExitCode(0);

        $this->assertSame('active', $today->fresh()->status);
        $this->assertSame('scheduled', $tomorrow->fresh()->status);
        $this->assertSame('scheduled', $nextWeek->fresh()->status);
        $this->assertSame(1, DailyChallenge::where('status', 'active')->count());
    }

    public function test_past_scheduled_daily_is_left_alone(): void
    {
        config(['ballspot.daily.auto_activate' => true]);
        $yesterday = $this->daily(Carbon::today()->subDay()->toDateString());

        $this->artisan('ballspot:activate-daily')
            ->expectsOutputToContain('No daily challenge row exists for today')
            ->assertExitCode(0);

        $this->assertSame('scheduled', $yesterday->fresh()->status);
    }

    public function test_already_active_daily_is_not_changed(): void
    {
        config(['ballspot.daily.auto_activate' => true]);
        $daily = $this->daily($this->today(), 'active');
        $frozen = Carbon::today()->subHours(5);
        DailyChallenge::whereKey($daily->id)->update(['updated_at' => $frozen]);

        $this->artisan('ballspot:activate-daily')
            ->expectsOutputToContain('already active')
            ->assertExitCode(0);

        $fresh = $daily->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertTrue($fresh->updated_at->equalTo($frozen), 'an already-active row must not be rewritten');
    }

    public function test_no_daily_for_today_returns_cleanly(): void
    {
        config(['ballspot.daily.auto_activate' => true]);

        $this->artisan('ballspot:activate-daily')
            ->expectsOutputToContain('No daily challenge row exists for today')
            ->assertExitCode(0);

        $this->assertSame(0, DailyChallenge::count());
    }

    public function test_running_twice_is_idempotent(): void
    {
        config(['ballspot.daily.auto_activate' => true]);
        $daily = $this->daily($this->today());

        $this->artisan('ballspot:activate-daily')->assertExitCode(0);
        $this->artisan('ballspot:activate-daily')->assertExitCode(0);
        $this->artisan('ballspot:activate-daily')->assertExitCode(0);

        $this->assertSame('active', $daily->fresh()->status);
        $this->assertSame(1, DailyChallenge::count());
    }

    public function test_archived_daily_is_not_resurrected(): void
    {
        config(['ballspot.daily.auto_activate' => true]);
        $daily = $this->daily($this->today(), 'archived');

        $this->artisan('ballspot:activate-daily')
            ->expectsOutputToContain("is 'archived', not 'scheduled'")
            ->assertExitCode(0);

        $this->assertSame('archived', $daily->fresh()->status);
    }

    public function test_a_daily_whose_challenge_is_not_ready_is_not_activated(): void
    {
        config(['ballspot.daily.auto_activate' => true]);
        $draft = $this->readyChallenge('Draft photo', ['status' => 'draft']);
        $daily = $this->daily($this->today(), 'scheduled', $draft);

        $this->artisan('ballspot:activate-daily')
            ->expectsOutputToContain('not ready')
            ->assertExitCode(1);

        $this->assertSame('scheduled', $daily->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Scheduler registration + the manual admin path
    // ------------------------------------------------------------------

    public function test_scheduler_entry_runs_at_00_10_only_when_enabled(): void
    {
        $event = $this->schedulerEvent();
        Carbon::setTestNow(Carbon::create(2030, 3, 1, 0, 10, 0, config('app.timezone')));

        $this->assertTrue($event->isDue($this->app), 'due at 00:10');

        config(['ballspot.daily.auto_activate' => false]);
        $this->assertFalse($event->filtersPass($this->app));

        config(['ballspot.daily.auto_activate' => true]);
        $this->assertTrue($event->filtersPass($this->app));

        Carbon::setTestNow(Carbon::create(2030, 3, 1, 0, 5, 0, config('app.timezone')));
        $this->assertFalse($event->isDue($this->app), 'not due at 00:05, i.e. it runs after the scheduler');

        Carbon::setTestNow();
    }

    public function test_manual_admin_activation_still_works_with_the_flag_off(): void
    {
        config(['ballspot.daily.auto_activate' => false]);
        $admin = User::factory()->create(['is_admin' => true]);
        $daily = $this->daily($this->today());

        $this->actingAs($admin)
            ->patch("/admin/daily/{$daily->id}/status", ['status' => 'active'])
            ->assertRedirect(route('admin.daily.index'));

        $this->assertSame('active', $daily->fresh()->status);
    }

    public function test_diagnostics_reports_the_activation_flag(): void
    {
        Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football', 'status' => Sport::STATUS_ACTIVE]);

        $snapshot = app(DiagnosticsService::class)->snapshot();
        $this->assertFalse($snapshot['daily']['auto_activate_enabled']);
        $this->assertStringContainsString('ballspot:activate-daily', $snapshot['daily']['cron_command']);

        config(['ballspot.daily.auto_activate' => true]);
        $this->assertTrue(app(DiagnosticsService::class)->snapshot()['daily']['auto_activate_enabled']);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/diagnostics')
            ->assertOk()
            ->assertSee('BALLPICKER_AUTO_ACTIVATE_DAILIES');
    }
}
