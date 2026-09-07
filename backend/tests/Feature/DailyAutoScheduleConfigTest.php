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
 * Launch control: BALLPICKER_AUTO_SCHEDULE_DAILIES decides whether the 00:05
 * scheduler entry may create daily_challenges rows on its own. Default true;
 * production sets false while the daily calendar is curated by hand. The
 * manual command must keep working regardless of the flag.
 */
class DailyAutoScheduleConfigTest extends TestCase
{
    use RefreshDatabase;

    private function dailySchedulerEvent(): Event
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $e) => str_contains((string) $e->command, 'ballspot:schedule-daily-challenges'));

        $this->assertNotNull($event, 'the daily scheduler entry must stay registered so schedule:list shows it');

        return $event;
    }

    private function eligibleChallenge(): Challenge
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football', 'status' => Sport::STATUS_ACTIVE]);

        return Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => 'Ready photo',
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'usage_pool'        => Challenge::POOL_GENERAL,
            'hidden_image_path' => 'challenges/hidden/ready.jpg',
        ]);
    }

    public function test_auto_scheduling_is_enabled_by_default(): void
    {
        $this->assertTrue((bool) config('ballspot.daily.auto_schedule'));
        $this->assertTrue($this->dailySchedulerEvent()->filtersPass($this->app));
    }

    public function test_scheduler_entry_is_skipped_when_auto_scheduling_is_disabled(): void
    {
        config(['ballspot.daily.auto_schedule' => false]);

        $event = $this->dailySchedulerEvent();

        // Still due at 00:05 (it is listed), but the run filter says no.
        Carbon::setTestNow(Carbon::create(2030, 3, 1, 0, 5, 0, config('app.timezone')));
        $this->assertTrue($event->isDue($this->app));
        $this->assertFalse($event->filtersPass($this->app));
        Carbon::setTestNow();
    }

    public function test_scheduler_entry_runs_when_auto_scheduling_is_enabled(): void
    {
        config(['ballspot.daily.auto_schedule' => true]);

        $event = $this->dailySchedulerEvent();

        Carbon::setTestNow(Carbon::create(2030, 3, 1, 0, 5, 0, config('app.timezone')));
        $this->assertTrue($event->isDue($this->app));
        $this->assertTrue($event->filtersPass($this->app));
        Carbon::setTestNow();
    }

    public function test_manual_command_still_schedules_when_auto_scheduling_is_disabled(): void
    {
        config(['ballspot.daily.auto_schedule' => false]);
        $this->eligibleChallenge();

        $this->artisan('ballspot:schedule-daily-challenges', ['--days' => 1])->assertSuccessful();

        $this->assertSame(1, DailyChallenge::count());
    }

    public function test_diagnostics_reports_auto_scheduling_state(): void
    {
        $snapshot = app(DiagnosticsService::class)->snapshot();
        $this->assertTrue($snapshot['daily']['auto_schedule_enabled']);
        $this->assertStringNotContainsString('Automatic daily scheduling is OFF', implode("\n", array_column($snapshot['warnings'], 'message')));

        config(['ballspot.daily.auto_schedule' => false]);
        $snapshot = app(DiagnosticsService::class)->snapshot();
        $this->assertFalse($snapshot['daily']['auto_schedule_enabled']);
        $this->assertStringContainsString('Automatic daily scheduling is OFF', implode("\n", array_column($snapshot['warnings'], 'message')));
    }

    public function test_diagnostics_page_shows_the_flag_to_the_admin(): void
    {
        Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football', 'status' => Sport::STATUS_ACTIVE]);
        $admin = User::factory()->create(['is_admin' => true]);

        config(['ballspot.daily.auto_schedule' => false]);
        $this->actingAs($admin)->get('/admin/diagnostics')
            ->assertOk()
            ->assertSee('BALLPICKER_AUTO_SCHEDULE_DAILIES')
            ->assertSee('Auto-schedule dailies');
    }
}
