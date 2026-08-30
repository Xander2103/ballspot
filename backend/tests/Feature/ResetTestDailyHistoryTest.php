<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeGuess;
use App\Models\Guess;
use App\Models\League;
use App\Models\LeagueRound;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ballspot:reset-test-daily-history — one-time pre-launch reset of Daily
 * Challenge history. Must be dry-run by default, require --force AND
 * --confirm-prelaunch, and touch ONLY daily_challenges + daily_challenge_guesses.
 */
class ResetTestDailyHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Challenge $dailyUsed;
    private Challenge $tournamentOnly;
    private LeagueRound $round;

    protected function setUp(): void
    {
        parent::setUp();

        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $make  = fn (string $title, string $pool) => Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => $title,
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'usage_pool'        => $pool,
            'hidden_image_path' => "challenges/hidden/{$title}.jpg",
        ]);

        $this->dailyUsed      = $make('Was daily', Challenge::POOL_DAILY);
        $this->tournamentOnly = $make('Tournament only', Challenge::POOL_TOURNAMENT);

        $users = User::factory()->count(2)->create();

        foreach (['2026-08-01', '2026-08-02'] as $date) {
            $dc = DailyChallenge::create(['challenge_id' => $this->dailyUsed->id, 'challenge_date' => $date, 'status' => 'archived']);
            foreach ($users as $u) {
                DailyChallengeGuess::create([
                    'daily_challenge_id' => $dc->id, 'user_id' => $u->id,
                    'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5, 'distance' => 0.1, 'score' => 50, 'submitted_at' => now(),
                ]);
            }
        }

        $league = League::create(['name' => 'Cup', 'owner_user_id' => $users[0]->id, 'sport_id' => $sport->id, 'join_code' => 'ABC123', 'status' => 'active', 'duration_days' => 7, 'rounds_per_day' => 1, 'starts_at' => now(), 'ends_at' => now()->addDays(7)]);
        $this->round = LeagueRound::create(['league_id' => $league->id, 'challenge_id' => $this->tournamentOnly->id, 'round_number' => 1, 'status' => 'open']);
        Guess::create([
            'league_round_id' => $this->round->id, 'user_id' => $users[0]->id,
            'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5, 'distance' => 0.1, 'score' => 50, 'submitted_at' => now(),
        ]);
    }

    public function test_dry_run_by_default_deletes_nothing_and_reports_counts(): void
    {
        $this->artisan('ballspot:reset-test-daily-history')
            ->expectsOutputToContain('daily_challenges rows: 2')
            ->expectsOutputToContain('daily_challenge_guesses rows: 4')
            ->expectsOutputToContain('Was daily')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertSame(2, DailyChallenge::count());
        $this->assertSame(4, DailyChallengeGuess::count());
        $this->assertTrue($this->dailyUsed->fresh()->isDailyUsed());
    }

    public function test_force_without_confirm_prelaunch_refuses(): void
    {
        $this->artisan('ballspot:reset-test-daily-history', ['--force' => true])
            ->expectsOutputToContain('--confirm-prelaunch')
            ->assertFailed();

        $this->assertSame(2, DailyChallenge::count());
        $this->assertSame(4, DailyChallengeGuess::count());
    }

    public function test_confirm_prelaunch_without_force_refuses(): void
    {
        $this->artisan('ballspot:reset-test-daily-history', ['--confirm-prelaunch' => true])
            ->expectsOutputToContain('--force')
            ->assertFailed();

        $this->assertSame(2, DailyChallenge::count());
        $this->assertSame(4, DailyChallengeGuess::count());
    }

    public function test_forced_and_confirmed_run_deletes_only_daily_history(): void
    {
        $challengeCount = Challenge::count();
        $imageBefore    = $this->dailyUsed->hidden_image_path;
        $poolBefore     = $this->dailyUsed->usage_pool;

        $this->artisan('ballspot:reset-test-daily-history', ['--force' => true, '--confirm-prelaunch' => true])
            ->expectsOutputToContain('Deleted 4 daily_challenge_guesses rows')
            ->expectsOutputToContain('Deleted 2 daily_challenges rows')
            ->assertSuccessful();

        // Daily history is gone.
        $this->assertSame(0, DailyChallenge::count());
        $this->assertSame(0, DailyChallengeGuess::count());

        // Challenges + images + pool untouched; "Used as Daily" flag cleared.
        $this->assertSame($challengeCount, Challenge::count());
        $fresh = $this->dailyUsed->fresh();
        $this->assertSame($imageBefore, $fresh->hidden_image_path);
        $this->assertSame($poolBefore, $fresh->usage_pool);
        $this->assertSame(Challenge::POOL_DAILY, $fresh->usage_pool);
        $this->assertFalse($fresh->isDailyUsed());
        $this->assertSame(0, Challenge::dailyUsed()->count());

        // Tournament history, users untouched.
        $this->assertSame(1, LeagueRound::count());
        $this->assertSame(1, Guess::count());
        $this->assertSame(1, League::count());
        $this->assertSame(2, User::count());
    }

    public function test_run_on_empty_history_is_a_noop(): void
    {
        DB::table('daily_challenge_guesses')->delete();
        DB::table('daily_challenges')->delete();

        $this->artisan('ballspot:reset-test-daily-history', ['--force' => true, '--confirm-prelaunch' => true])
            ->expectsOutputToContain('Nothing to delete')
            ->assertSuccessful();
    }
}
