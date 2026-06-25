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
        $this->makeReadyChallenge('Challenge A');
        $this->makeReadyChallenge('Challenge B');

        $this->artisan('ballspot:schedule-daily-challenges', ['--days' => 4])
            ->assertExitCode(0);

        $this->assertDatabaseCount('daily_challenges', 4);
    }

    public function test_command_skips_existing_daily_challenges(): void
    {
        $challenge = $this->makeReadyChallenge();
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
}
