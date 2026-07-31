<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\Sport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleDailyChallengesTest extends TestCase
{
    use RefreshDatabase;

    private function makeReadyChallenge(string $title = 'Real Challenge'): Challenge
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        return Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => $title,
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/test.jpg',
        ]);
    }

    public function test_dry_run_does_not_create_daily_challenges(): void
    {
        $this->makeReadyChallenge();

        $this->artisan('ballspot:schedule-daily-challenges', ['--days' => 3, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertDatabaseCount('daily_challenges', 0);
    }

    public function test_command_creates_daily_challenges_for_requested_days(): void
    {
        // Strict mode never reuses, so the pool must be at least as large as --days.
        $this->makeReadyChallenge('Challenge A');
        $this->makeReadyChallenge('Challenge B');
        $this->makeReadyChallenge('Challenge C');
        $this->makeReadyChallenge('Challenge D');

        $this->artisan('ballspot:schedule-daily-challenges', ['--days' => 4])
            ->assertExitCode(0);

        $this->assertDatabaseCount('daily_challenges', 4);
    }

    public function test_command_skips_existing_daily_challenges(): void
    {
        $challenge = $this->makeReadyChallenge();
        $this->makeReadyChallenge('Challenge B');
        $this->makeReadyChallenge('Challenge C');
        $today = today()->toDateString();
        DailyChallenge::create([
            'challenge_id'   => $challenge->id,
            'challenge_date' => $today,
            'status'         => 'active',
        ]);

        $this->artisan('ballspot:schedule-daily-challenges', ['--days' => 3])
            ->assertExitCode(0);

        // today was already there; 2 new ones added for the following days
        $this->assertDatabaseCount('daily_challenges', 3);
    }

    // ----- Strict no-reuse (default) -----

    public function test_default_command_does_not_reuse_a_challenge_already_used_as_daily(): void
    {
        $used = $this->makeReadyChallenge('Already Used');
        $fresh = $this->makeReadyChallenge('Fresh Challenge');

        DailyChallenge::create([
            'challenge_id'   => $used->id,
            'challenge_date' => today()->subDays(10)->toDateString(),
            'status'         => 'archived',
        ]);

        $this->artisan('ballspot:schedule-daily-challenges', ['--days' => 3])
            ->assertExitCode(0);

        $this->assertEquals(
            1,
            DailyChallenge::where('challenge_id', $used->id)->count(),
            'A previously used challenge must not be scheduled again in strict mode.'
        );
        $this->assertEquals(
            1,
            DailyChallenge::where('challenge_id', $fresh->id)->count(),
            'The one unused challenge should be scheduled exactly once.'
        );
    }

    public function test_default_command_stops_gracefully_when_unused_pool_is_exhausted(): void
    {
        $this->makeReadyChallenge('Challenge A');
        $this->makeReadyChallenge('Challenge B');

        $this->artisan('ballspot:schedule-daily-challenges', ['--days' => 4])
            ->expectsOutputToContain('Pool exhausted: scheduled 2 of 4 requested days.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('daily_challenges', 2);

        $usedIds = DailyChallenge::pluck('challenge_id')->toArray();
        $this->assertCount(2, array_unique($usedIds));
    }

    public function test_default_command_succeeds_quietly_when_every_challenge_is_already_used(): void
    {
        $used = $this->makeReadyChallenge('Already Used');
        DailyChallenge::create([
            'challenge_id'   => $used->id,
            'challenge_date' => today()->subDays(10)->toDateString(),
            'status'         => 'archived',
        ]);

        $this->artisan('ballspot:schedule-daily-challenges', ['--days' => 3])
            ->assertExitCode(0);

        // Nothing new was created, and the historical row is untouched.
        $this->assertDatabaseCount('daily_challenges', 1);
    }

    // ----- Legacy reuse behaviour, opt-in via --allow-reuse -----

    public function test_allow_reuse_fills_every_requested_day_by_rotating_the_pool(): void
    {
        $this->makeReadyChallenge('Challenge A');
        $this->makeReadyChallenge('Challenge B');

        $this->artisan('ballspot:schedule-daily-challenges', ['--days' => 4, '--allow-reuse' => true])
            ->assertExitCode(0);

        $this->assertDatabaseCount('daily_challenges', 4);
    }

    public function test_allow_reuse_schedules_around_an_existing_date_using_a_single_challenge(): void
    {
        $challenge = $this->makeReadyChallenge();
        $today = today()->toDateString();
        DailyChallenge::create([
            'challenge_id'   => $challenge->id,
            'challenge_date' => $today,
            'status'         => 'active',
        ]);

        $this->artisan('ballspot:schedule-daily-challenges', ['--days' => 3, '--allow-reuse' => true])
            ->assertExitCode(0);

        // today was already there; the same challenge is reused for the next 2 days
        $this->assertDatabaseCount('daily_challenges', 3);
    }

    public function test_command_does_not_use_incomplete_or_draft_challenges(): void
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);

        // Draft — should be ignored
        Challenge::create([
            'sport_id'   => $sport->id,
            'title'      => 'Draft Challenge',
            'difficulty' => 'easy',
            'status'     => 'draft',
        ]);

        // Active but incomplete (no hidden_image_path) — should be ignored
        Challenge::create([
            'sport_id'   => $sport->id,
            'title'      => 'Incomplete Active',
            'difficulty' => 'easy',
            'status'     => 'active',
        ]);

        $this->artisan('ballspot:schedule-daily-challenges', ['--days' => 2])
            ->assertExitCode(1);

        $this->assertDatabaseCount('daily_challenges', 0);
    }

    public function test_command_avoids_duplicate_challenge_use_within_range(): void
    {
        $this->makeReadyChallenge('Challenge A');
        $this->makeReadyChallenge('Challenge B');
        $this->makeReadyChallenge('Challenge C');

        $this->artisan('ballspot:schedule-daily-challenges', ['--days' => 3])
            ->assertExitCode(0);

        $usedIds = DailyChallenge::pluck('challenge_id')->toArray();
        $this->assertCount(3, array_unique($usedIds), 'Each of the 3 days should use a different challenge');
    }

    public function test_force_replaces_existing_daily_challenge_without_duplication(): void
    {
        $challengeA = $this->makeReadyChallenge('Challenge A');
        $this->makeReadyChallenge('Challenge B');
        $today = today()->toDateString();

        DailyChallenge::create([
            'challenge_id'   => $challengeA->id,
            'challenge_date' => $today,
            'status'         => 'active',
        ]);

        $this->artisan('ballspot:schedule-daily-challenges', ['--days' => 1, '--force' => true])
            ->assertExitCode(0);

        // Should update the existing row, not insert a second one
        $this->assertDatabaseCount('daily_challenges', 1);
        $this->assertDatabaseHas('daily_challenges', ['challenge_date' => $today]);
    }

    public function test_no_eligible_challenges_prints_error_and_fails(): void
    {
        // No challenges in the database at all
        $this->artisan('ballspot:schedule-daily-challenges', ['--days' => 3])
            ->assertExitCode(1);

        $this->assertDatabaseCount('daily_challenges', 0);
    }

    public function test_invalid_start_date_shows_friendly_error_and_fails(): void
    {
        $this->makeReadyChallenge();

        $this->artisan('ballspot:schedule-daily-challenges', ['--start' => 'not-a-date', '--days' => 3])
            ->assertExitCode(1);

        $this->assertDatabaseCount('daily_challenges', 0);
    }
}
