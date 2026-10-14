<?php

namespace Tests\Feature;

use App\Models\AppEvent;
use App\Models\Challenge;
use App\Models\CompetitionFinish;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeGuess;
use App\Models\Sport;
use App\Models\User;
use App\Services\CompetitionAdminStatsService;
use Carbon\Carbon;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * /admin/competition dashboard (read-only). The numbers must match what the
 * leaderboard and the close command compute, hide every private detail, and
 * never crash on empty data or a broken config.
 */
class CompetitionAdminStatsTest extends TestCase
{
    use RefreshDatabase;

    private ?string $storage = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Mid-July: the current period is July 2026, the close target June 2026.
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 12, 0, 0, config('app.timezone')));
        config(['ballspot.competition.period' => 'monthly', 'ballspot.competition.label' => null]);
        $this->seed(BadgeSeeder::class);
    }

    protected function tearDown(): void
    {
        if ($this->storage !== null) {
            File::deleteDirectory($this->storage);
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function daily(string $date): DailyChallenge
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $challenge = Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => 'T ' . $date . ' ' . uniqid(),
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/test.jpg',
        ]);

        return DailyChallenge::create(['challenge_id' => $challenge->id, 'challenge_date' => $date, 'status' => 'active']);
    }

    private function guess(DailyChallenge $dc, User $user, int $score, ?string $at = null): void
    {
        DailyChallengeGuess::create([
            'daily_challenge_id' => $dc->id,
            'user_id'            => $user->id,
            'guess_x_ratio'      => 0.5,
            'guess_y_ratio'      => 0.5,
            'distance'           => 0.1,
            'score'              => $score,
            'submitted_at'       => $at ? Carbon::parse($at) : now(),
        ]);
    }

    private function dashboard(): array
    {
        return app(CompetitionAdminStatsService::class)->dashboard();
    }

    /** Point storage_path() at a scratch dir so the events-log scan reads only what the test writes. */
    private function fakeEventsLog(array $lines): void
    {
        $this->storage = sys_get_temp_dir() . '/ballspot-admin-stats-' . uniqid();
        File::makeDirectory($this->storage . '/logs', 0777, true);
        $this->app->useStoragePath($this->storage);
        File::put($this->storage . '/logs/ballpicker-events-' . now()->toDateString() . '.log', implode("\n", $lines) . "\n");
    }

    // ------------------------------------------------------------------
    // Current period
    // ------------------------------------------------------------------

    public function test_current_period_stats_are_calculated_correctly(): void
    {
        $july10 = $this->daily('2026-07-10');
        $july12 = $this->daily('2026-07-12');
        [$a, $b, $c] = User::factory()->count(3)->create()->all();
        $this->guess($july10, $a, 90);
        $this->guess($july12, $a, 70);   // a: 160 over 2 dailies
        $this->guess($july10, $b, 95);   // b: 95, highest single guess
        $this->guess($july12, $c, 40);   // c: 40
        // A deleted account's guess is kept for history but never counted.
        $deleted = User::factory()->create();
        $deleted->forceFill(['anonymized_at' => now(), 'friend_code' => null])->save();
        $this->guess($july10, $deleted, 100);
        // June data belongs to the previous period.
        $this->guess($this->daily('2026-06-20'), $a, 10);

        $cur = $this->dashboard()['current'];

        $this->assertSame('monthly', $cur['period_type']);
        $this->assertSame('Monthly', $cur['period_label']);
        $this->assertSame('2026-07-01', $cur['period_start']);
        $this->assertSame('2026-07-31', $cur['period_end']);
        $this->assertSame(16, $cur['days_remaining']);
        $this->assertFalse($cur['ends_today']);
        $this->assertSame(3, $cur['players']);
        $this->assertSame(4, $cur['guesses']);
        $this->assertSame(73.8, $cur['average_score']); // (90+70+95+40)/4
        $this->assertSame(95, $cur['highest_guess']);
        $this->assertSame(160, $cur['leader_total']);
        $this->assertSame(0, $cur['top_finisher_eligible']); // field < 10
        $this->assertNull($cur['last_recalculated_at']);
    }

    public function test_top_finisher_eligibility_follows_the_ten_player_rule(): void
    {
        $dc = $this->daily('2026-07-05');
        foreach (User::factory()->count(12)->create() as $i => $user) {
            $this->guess($dc, $user, 50 + $i);
        }

        $cur = $this->dashboard()['current'];
        $this->assertSame(12, $cur['players']);
        $this->assertSame(2, $cur['top_finisher_eligible']); // ceil(12 * 0.1)
    }

    // ------------------------------------------------------------------
    // Previous period
    // ------------------------------------------------------------------

    public function test_previous_period_stats_before_and_after_a_close(): void
    {
        $june = $this->daily('2026-06-10');
        [$a, $b, $c] = User::factory()->count(3)->create()->all();
        $this->guess($june, $a, 90);
        $this->guess($june, $b, 70);
        $this->guess($june, $c, 50);

        $prev = $this->dashboard()['previous'];
        $this->assertSame('June 2026', $prev['period_label']);
        $this->assertSame('2026-06-01', $prev['period_start']);
        $this->assertSame('2026-06-30', $prev['period_end']);
        $this->assertFalse($prev['closed']);
        $this->assertNull($prev['closed_at']);
        $this->assertSame(3, $prev['players']);
        $this->assertSame(3, $prev['guesses']);
        $this->assertSame(70.0, $prev['average_score']);
        $this->assertSame(0, $prev['trophies_awarded']);
        $this->assertSame(0, $prev['podium_finishes']);
        $this->assertNull($prev['last_closed']);

        // Close exactly as ops would (CLI) — the dashboard only reads the result.
        $this->artisan('ballspot:close-competition')->assertExitCode(0);

        $prev = $this->dashboard()['previous'];
        $this->assertTrue($prev['closed']);
        $this->assertNotNull($prev['closed_at']);
        $this->assertSame(3, $prev['podium_finishes']);
        $this->assertSame(4, $prev['trophies_awarded']); // monthly_winner + 3× monthly_podium (field < 10: no top-10 badge)
        $this->assertSame(2000 + 1000 + 500, $prev['xp_awarded']);
        $this->assertSame('June 2026', $prev['last_closed']['label']);
        $this->assertSame(3, $prev['last_closed']['total_players']);
        $this->assertSame(3, CompetitionFinish::count(), 'the dashboard must not write finishes');
    }

    // ------------------------------------------------------------------
    // Leaderboard preview + privacy
    // ------------------------------------------------------------------

    public function test_leaderboard_preview_shows_usernames_only_caps_at_ten_and_marks_ties(): void
    {
        $dc = $this->daily('2026-07-03');
        $users = User::factory()->count(12)->create();
        foreach ($users as $i => $user) {
            // Two users share 80 points; everyone else is distinct.
            $this->guess($dc, $user, $i < 2 ? 80 : 60 - $i, sprintf("2026-07-03 10:%02d:00", $i));
        }
        $deleted = User::factory()->create(['username' => 'ghost_player', 'email' => 'ghost@example.com']);
        $this->guess($dc, $deleted, 100);
        $deleted->forceFill(['anonymized_at' => now(), 'friend_code' => null])->save();

        $rows = $this->dashboard()['leaderboard'];

        $this->assertCount(10, $rows);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], array_column($rows, 'placement'));
        $this->assertSame([80, 80], [$rows[0]['total_score'], $rows[1]['total_score']]);
        $this->assertTrue($rows[0]['tied']);
        $this->assertTrue($rows[1]['tied']);
        $this->assertFalse($rows[2]['tied']);
        $this->assertSame($users[0]->username, $rows[0]['username']); // earlier last guess wins the tie
        $this->assertNotContains('ghost_player', array_column($rows, 'username'));
        foreach ($rows as $row) {
            $this->assertSame(['placement', 'username', 'total_score', 'challenges_played', 'avg_score', 'tied'], array_keys($row));
        }

        $html = $this->actingAs($this->admin())->get('/admin/competition')->assertOk()->getContent();
        foreach ($users as $user) {
            $this->assertStringNotContainsString($user->email, $html, 'emails must never render');
        }
        $this->assertStringNotContainsString('ghost@example.com', $html);
        $this->assertStringNotContainsString('ghost_player', $html);
        $this->assertStringContainsString($users[0]->username, $html);
    }

    // ------------------------------------------------------------------
    // Robustness
    // ------------------------------------------------------------------

    public function test_empty_period_does_not_crash_and_shows_not_available(): void
    {
        $d = $this->dashboard();
        $this->assertSame(0, $d['current']['players']);
        $this->assertNull($d['current']['average_score']);
        $this->assertNull($d['current']['highest_guess']);
        $this->assertSame([], $d['leaderboard']);
        $this->assertFalse($d['previous']['closed']);

        $this->actingAs($this->admin())->get('/admin/competition')
            ->assertOk()
            ->assertSee('No daily guesses in this period yet.')
            ->assertSee('Nothing to close')
            ->assertSee('N/A')
            ->assertSee('computed live on every request');
    }

    public function test_invalid_or_missing_config_does_not_crash(): void
    {
        config(['ballspot.competition.period' => 'fortnightly', 'ballspot.competition.label' => null, 'ballspot.competition.timezone' => null]);

        $d = $this->dashboard();
        $this->assertSame('monthly', $d['current']['period_type']);
        $this->assertFalse($d['config']['period_valid']);
        $this->assertSame('fortnightly', $d['config']['period_value']);

        $this->actingAs($this->admin())->get('/admin/competition')
            ->assertOk()
            ->assertSee('invalid — using monthly')
            ->assertSee('BALLPICKER_COMPETITION_PERIOD')
            ->assertSee('BALLPICKER_COMPETITION_LABEL')
            ->assertSee('config:clear');

        config(['ballspot.competition.period' => null]);
        $this->assertSame('monthly', $this->dashboard()['current']['period_type']);
    }

    public function test_page_keeps_the_cli_close_commands_and_the_accounts_summary(): void
    {
        $deleted = User::factory()->create();
        $deleted->forceFill(['anonymized_at' => now(), 'friend_code' => null])->save();
        User::factory()->create(['created_at' => now()->subDays(3)]);
        User::factory()->create(['created_at' => now()->subDays(30)]);

        $d = $this->dashboard();
        $this->assertSame(['active' => 2, 'deleted' => 1, 'new_7d' => 2, 'new_24h' => 1], $d['accounts']);

        $this->actingAs($this->admin())->get('/admin/competition')
            ->assertOk()
            ->assertSee('php artisan ballspot:close-competition --dry-run')
            ->assertSee('php artisan ballspot:close-competition')
            ->assertSee('virtual XP and trophies only')
            ->assertSee('New registrations (7 days)')
            ->assertDontSee('Close period</button>', false);
    }

    // ------------------------------------------------------------------
    // Growth + security summaries
    // ------------------------------------------------------------------

    public function test_growth_summary_shows_empty_state_then_counts_with_attempt_wording(): void
    {
        $this->actingAs($this->admin())->get('/admin/competition')
            ->assertOk()->assertSee('Growth events are not tracked yet.');

        $user = User::factory()->create();
        foreach ([
            [AppEvent::SHARE_APP_TAPPED, 2], [AppEvent::DAILY_RESULT_SHARE_TAPPED, 3], [AppEvent::DAILY_RESULT_HIGH_SCORE_SHARE_TAPPED, 1],
            [AppEvent::SHARE_REWARD_CLAIMED, 2], [AppEvent::RATE_APP_TAPPED, 4], [AppEvent::REVIEW_PROMPT_ATTEMPTED, 5], [AppEvent::NATIVE_REVIEW_REQUESTED, 1],
        ] as [$name, $n]) {
            for ($i = 0; $i < $n; $i++) {
                AppEvent::create(['user_id' => $user->id, 'event_name' => $name, 'created_at' => now()]);
            }
        }
        AppEvent::create(['user_id' => $user->id, 'event_name' => AppEvent::SHARE_APP_TAPPED, 'created_at' => now()->subDays(10)]);

        $growth = $this->dashboard()['growth'];
        $this->assertTrue($growth['has_events']);
        $this->assertSame(3, $growth['totals']['app_shares']);
        $this->assertSame(5, $growth['last_7_days']['shares']);
        $this->assertSame(9, $growth['last_7_days']['review_attempts']);

        $res = $this->actingAs($this->admin())->get('/admin/competition');
        $res->assertOk()
            ->assertSee('Total app shares')
            ->assertSee('Total review prompt attempts')
            ->assertSee('Total native review requests')
            ->assertSee('Review attempts are not confirmed App Store / Google Play reviews.')
            ->assertSee('Native review APIs do not reliably confirm whether a review was submitted.')
            ->assertDontSee('Growth events are not tracked yet.');
        $html = strtolower($res->getContent());
        foreach (['reviews placed', 'reviews submitted', 'confirmed reviews:'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
        $this->assertMatchesRegularExpression('/Total app shares<\/th><td>3\b/', $res->getContent());
    }

    public function test_security_summary_counts_rate_limit_and_honeypot_events_without_exposing_context(): void
    {
        $ts = now()->format('Y-m-d H:i:s');
        $old = now()->subDays(2)->format('Y-m-d H:i:s');
        $this->fakeEventsLog([
            "[{$ts}] testing.WARNING: register.rate_limited {\"scope\":\"ip_accounts\",\"code\":\"registration_rate_limited\"}",
            "[{$ts}] testing.WARNING: register.rate_limited {\"scope\":\"request_throttle\"}",
            "[{$ts}] testing.WARNING: verification.resend_rate_limited {\"user_id\":5,\"trigger\":\"resend\"}",
            "[{$ts}] testing.WARNING: password_reset.rate_limited {\"scope\":\"request_throttle\"}",
            "[{$ts}] testing.WARNING: login_2fa.rate_limited {\"user_id\":9}",
            "[{$ts}] testing.WARNING: honeypot.triggered {\"field\":\"website\",\"secret_probe\":\"SECRETTOKEN123\"}",
            "[{$ts}] testing.INFO: email_verification.sent {\"user_id\":5,\"code_probe\":\"654321\"}",
            "[{$ts}] testing.INFO: email_verification.sent {\"user_id\":6}",
            "[{$ts}] testing.INFO: password_reset.requested {\"outcome\":\"sent\"}",
            "[{$ts}] testing.ERROR: password_reset.requested {\"outcome\":\"send_failed\"}",
            "[{$ts}] testing.ERROR: email_verification.send_failed {\"user_id\":7}",
            "[{$old}] testing.WARNING: honeypot.triggered {\"field\":\"website\"}", // outside 24h
        ]);
        User::factory()->create(['created_at' => now()->subHours(2)]);
        User::factory()->create(['created_at' => now()->subDays(3)]);

        $ab = $this->dashboard()['abuse'];
        $counts = collect($ab['rows'])->pluck('count_24h', 'event')->all();
        $this->assertSame(2, $counts['register.rate_limited']);
        $this->assertSame(1, $counts['verification.resend_rate_limited']);
        $this->assertSame(1, $counts['password_reset.rate_limited']);
        $this->assertSame(1, $counts['login_2fa.rate_limited']);
        $this->assertSame(1, $counts['honeypot.triggered']);
        $this->assertSame(1, $ab['registrations_24h']);
        $this->assertSame(2, $ab['verification_emails_sent_24h']);
        $this->assertSame(2, $ab['password_reset_requests_24h']);
        $this->assertSame(2, $ab['mail_failures_24h']);
        $this->assertFalse($ab['captcha_enabled']);

        $res = $this->actingAs($this->admin())->get('/admin/competition')->assertOk()
            ->assertSee('Security / auth abuse')
            ->assertSee('Honeypot triggers')
            ->assertSee('CAPTCHA / Turnstile')
            ->assertSee('disabled');
        $html = $res->getContent();
        $this->assertStringNotContainsString('SECRETTOKEN123', $html);
        $this->assertStringNotContainsString('654321', $html);
        $this->assertStringNotContainsString('code_probe', $html);
        $this->assertMatchesRegularExpression('/Registration rate-limited<\/th><td>2\b/', $html);
        $this->assertMatchesRegularExpression('/Honeypot triggers<\/th><td>1\b/', $html);
    }
}
