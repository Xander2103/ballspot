<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\Sport;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** GET /api/daily/today → next_daily: only a time the backend can stand behind. */
class DailyNextDailyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 21:30:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function challenge(string $slug = 'football', string $status = 'active'): Challenge
    {
        $sport = Sport::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);

        return Challenge::create([
            'sport_id' => $sport->id, 'title' => 'T', 'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5,
            'difficulty' => 'easy', 'status' => $status, 'hidden_image_path' => 'challenges/hidden/t.jpg',
        ]);
    }

    private function daily(Challenge $c, string $date, string $status): DailyChallenge
    {
        return DailyChallenge::create(['challenge_id' => $c->id, 'challenge_date' => $date, 'status' => $status]);
    }

    private function today(): \Illuminate\Testing\TestResponse
    {
        $user = User::factory()->create();

        return $this->withToken($user->createToken('t')->plainTextToken)->getJson('/api/daily/today');
    }

    public function test_an_active_daily_tomorrow_is_announced_from_midnight(): void
    {
        $this->daily($this->challenge(), '2026-10-21', 'active');

        $this->today()->assertOk()
            ->assertJsonPath('has_daily', false)
            ->assertJsonPath('next_daily.date', '2026-10-21')
            ->assertJsonPath('next_daily.available_at', '2026-10-21T00:00:00Z');
    }

    public function test_a_scheduled_daily_counts_only_with_auto_activation_and_at_its_activation_time(): void
    {
        $this->daily($this->challenge(), '2026-10-21', 'scheduled');

        config(['ballspot.daily.auto_activate' => false]);
        $this->today()->assertJsonPath('next_daily', null);

        config(['ballspot.daily.auto_activate' => true]);
        $this->today()->assertJsonPath('next_daily.available_at', '2026-10-21T00:10:00Z');
    }

    public function test_a_scheduled_daily_whose_photo_is_not_ready_is_never_announced(): void
    {
        config(['ballspot.daily.auto_activate' => true]);
        $this->daily($this->challenge('football', 'draft'), '2026-10-21', 'scheduled');

        $this->today()->assertJsonPath('next_daily', null);
    }

    public function test_it_is_scoped_to_the_requested_sport_and_skips_archived_photos(): void
    {
        $this->daily($this->challenge('tennis'), '2026-10-21', 'active');
        $this->daily($this->challenge('football', 'archived'), '2026-10-22', 'active');
        $this->daily($this->challenge(), '2026-10-23', 'active');

        $this->today()->assertJsonPath('next_daily.date', '2026-10-23');
    }

    public function test_it_is_also_sent_next_to_a_playable_daily(): void
    {
        $c = $this->challenge();
        $this->daily($c, '2026-10-20', 'active');
        $this->daily($c, '2026-10-21', 'active');

        $this->today()->assertJsonPath('has_daily', true)
            ->assertJsonPath('next_daily.available_at', '2026-10-21T00:00:00Z');
    }

    public function test_nothing_scheduled_means_null_never_a_guess(): void
    {
        $this->today()->assertOk()->assertJsonPath('next_daily', null);
    }

    public function test_reading_it_never_activates_or_changes_a_daily(): void
    {
        config(['ballspot.daily.auto_activate' => true]);
        $row = $this->daily($this->challenge(), '2026-10-21', 'scheduled');
        $before = $row->fresh()->updated_at;

        $this->today()->assertJsonPath('next_daily.date', '2026-10-21');

        $this->assertSame('scheduled', $row->fresh()->status);
        $this->assertEquals($before, $row->fresh()->updated_at);
        $this->assertSame(1, DailyChallenge::count());
    }

    public function test_todays_scheduled_daily_counts_until_its_activation_time_only(): void
    {
        config(['ballspot.daily.auto_activate' => true]);
        $this->daily($this->challenge(), '2026-10-20', 'scheduled');

        Carbon::setTestNow('2026-10-20 00:05:00');
        $this->today()->assertJsonPath('has_daily', false)
            ->assertJsonPath('next_daily.available_at', '2026-10-20T00:10:00Z');

        // Past 00:10 and still not active (activation failed or was skipped): no promise.
        Carbon::setTestNow('2026-10-20 00:30:00');
        $this->today()->assertJsonPath('next_daily', null);
    }

    public function test_the_existing_today_contract_is_unchanged_apart_from_the_new_field(): void
    {
        $c = $this->challenge();
        $this->daily($c, '2026-10-20', 'active');

        $this->today()->assertOk()->assertJsonStructure([
            'has_daily', 'already_played', 'next_daily',
            'daily_challenge' => ['id', 'challenge_date', 'daily_month_index', 'daily_month_total',
                'challenge' => ['id', 'title', 'difficulty', 'hidden_image_url', 'category', 'sport', 'tags']],
        ])->assertJsonMissingPath('daily_challenge.challenge.ball_x_ratio');
    }
}
