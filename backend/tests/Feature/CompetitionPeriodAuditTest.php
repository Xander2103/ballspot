<?php

namespace Tests\Feature;

use App\Console\Commands\CompetitionPeriodAudit;
use App\Models\Badge;
use App\Models\Challenge;
use App\Models\CompetitionFinish;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeGuess;
use App\Models\Sport;
use App\Models\User;
use App\Services\RuntimeFingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * 2026-11 "View Weekly Leaderboard" on build 9 while tinker says monthly: the
 * audit command must compare the CLI period with the REAL HTTP response, and
 * /admin/settings must show what the web process itself sees.
 */
class CompetitionPeriodAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday 4 Nov 2026: month 1–30 Nov, ISO week 2–8 Nov.
        $this->travelTo(Carbon::create(2026, 11, 4, 12, 0, 0, config('app.timezone')));
    }

    /**
     * Guesses on 1 Nov (month only) and 3 Nov (month AND week), so a monthly
     * and a weekly total differ: month 40+30 = 70, week 30.
     */
    private function seedScores(User $user): void
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $challenge = Challenge::create([
            'sport_id' => $sport->id, 'title' => 'C', 'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5,
            'difficulty' => 'easy', 'status' => 'active', 'hidden_image_path' => 'x.jpg',
        ]);
        foreach (['2026-11-01' => 40, '2026-11-03' => 30] as $date => $score) {
            $dc = DailyChallenge::create(['challenge_id' => $challenge->id, 'challenge_date' => $date, 'status' => 'active']);
            DailyChallengeGuess::create([
                'daily_challenge_id' => $dc->id, 'user_id' => $user->id,
                'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5, 'score' => $score, 'distance' => 0.0,
                'submitted_at' => Carbon::parse($date)->setTime(12, 0),
            ]);
        }
    }

    /** Answer the command's HTTP calls with the REAL controllers (token included). */
    private function routeHttpThroughTheApp(): void
    {
        Http::fake(function (ClientRequest $request) {
            $path = '/api' . explode('/api', parse_url($request->url(), PHP_URL_PATH), 2)[1];
            $response = $this->withHeaders(['Authorization' => $request->header('Authorization')[0]])->getJson($path);
            $this->app['auth']->forgetGuards();

            return Http::response($response->json(), $response->status());
        });
    }

    /** A web process that LABELS monthly but sums only this week. */
    private function fakeLabelWithoutScoring(int $userId, array $window, int $score): void
    {
        $period = ['period_type' => 'monthly', 'period_label' => 'Monthly', 'period_start' => $window[0], 'period_end' => $window[1]];
        $entry = ['user_id' => $userId, 'username' => 'x', 'total_score' => $score, 'rank' => 1];
        Http::fake([
            '*/daily/stats'              => Http::response(['weekly_rank' => 1, 'period' => $period]),
            '*/daily/leaderboard/weekly' => Http::response([
                'data' => [$entry], 'week_start' => $window[0], 'week_end' => $window[1], 'period' => $period,
                'meta' => ['current_user_rank' => 1, 'current_user_score' => $score],
            ]),
        ]);
    }

    private function fakeApi(string $type): void
    {
        $period = ['period_type' => $type, 'period_label' => ucfirst($type), 'period_start' => '2026-11-01', 'period_end' => '2026-11-30'];
        Http::fake([
            '*/daily/stats'              => Http::response(['current_streak' => 1, 'period' => $period]),
            '*/daily/leaderboard/weekly' => Http::response(['data' => [], 'period' => $period]),
        ]);
    }

    public function test_cli_only_run_prints_the_fingerprint_and_creates_no_token(): void
    {
        config(['ballspot.competition.period' => 'monthly']);

        $this->artisan('ballspot:competition-period-audit')
            ->expectsOutputToContain('CLI process')
            ->assertSuccessful();

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_http_weekly_while_cli_monthly_is_reported_as_mismatch_and_token_is_deleted(): void
    {
        config(['ballspot.competition.period' => 'monthly', 'app.url' => 'https://ballpicker.test']);
        $this->fakeApi('weekly');
        $user = User::factory()->create();

        $this->artisan('ballspot:competition-period-audit', ['--user' => $user->id, '--yes' => true])
            ->expectsOutputToContain("MISMATCH: the CLI computes 'monthly', the web process answers 'weekly'")
            ->assertFailed();

        Http::assertSent(fn ($r) => $r->url() === 'https://ballpicker.test/api/daily/stats' && $r->hasHeader('Authorization'));
        $this->assertSame(0, PersonalAccessToken::where('name', CompetitionPeriodAudit::TOKEN_NAME)->count(), 'temporary token must be removed');
    }

    public function test_matching_http_response_passes(): void
    {
        config(['ballspot.competition.period' => 'monthly', 'app.url' => 'https://ballpicker.test']);
        $this->fakeApi('monthly');
        $user = User::factory()->create();

        $this->artisan('ballspot:competition-period-audit', ['--user' => $user->id, '--yes' => true])
            ->expectsOutputToContain("OK: CLI and HTTP agree on 'monthly'")
            ->assertSuccessful();

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_declining_the_confirmation_sends_nothing(): void
    {
        Http::fake();
        $user = User::factory()->create();

        $this->artisan('ballspot:competition-period-audit', ['--user' => $user->id])
            ->expectsConfirmation("Create a temporary API token for user #{$user->id}, call " . rtrim((string) config('app.url'), '/') . '/api/daily/stats and delete the token again?', 'no')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_dotenv_values_lists_every_assignment_of_only_that_key_in_order(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($path, implode("\n", [
            'APP_KEY=base64:secretsecret',
            'BALLPICKER_COMPETITION_PERIOD=weekly',
            '# BALLPICKER_COMPETITION_PERIOD=ignored',
            'export BALLPICKER_COMPETITION_PERIOD="monthly" # comment',
            'BALLPICKER_COMPETITION_PERIOD_X=nope',
        ]));

        $values = app(RuntimeFingerprintService::class)->dotenvValues('BALLPICKER_COMPETITION_PERIOD', $path);
        @unlink($path);

        $this->assertSame(['weekly', 'monthly'], $values);
    }

    public function test_admin_settings_shows_the_web_process_runtime_without_secrets(): void
    {
        config(['ballspot.competition.period' => 'monthly']);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/admin/settings')
            ->assertOk()
            ->assertSee('Runtime (this web process)')
            ->assertSee('data-section="runtime"', false)
            ->assertSee('ballspot:competition-period-audit')
            ->assertDontSee((string) config('app.key'));
    }

    public function test_runtime_card_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/settings')->assertForbidden();
    }

    public function test_real_backend_monthly_label_window_and_scores_all_agree(): void
    {
        config(['ballspot.competition.period' => 'monthly', 'ballspot.competition.label' => null, 'app.url' => 'https://ballpicker.test']);
        $user = User::factory()->create();
        $this->seedScores($user);
        $this->routeHttpThroughTheApp();

        $this->artisan('ballspot:competition-period-audit', ['--user' => $user->id, '--yes' => true])
            ->expectsOutputToContain('scores computed over: monthly')
            ->expectsOutputToContain("OK: CLI and HTTP agree on 'monthly'")
            ->doesntExpectOutputToContain('FAIL')
            ->assertSuccessful();

        $this->assertSame(0, PersonalAccessToken::count(), 'temporary token must be removed');
    }

    public function test_real_backend_weekly_config_is_consistently_weekly(): void
    {
        config(['ballspot.competition.period' => 'weekly', 'ballspot.competition.label' => null, 'app.url' => 'https://ballpicker.test']);
        $user = User::factory()->create();
        $this->seedScores($user);
        $this->routeHttpThroughTheApp();

        $this->artisan('ballspot:competition-period-audit', ['--user' => $user->id, '--yes' => true])
            ->expectsOutputToContain('scores computed over: weekly')
            ->expectsOutputToContain("OK: CLI and HTTP agree on 'weekly'")
            ->assertSuccessful();
    }

    public function test_monthly_label_over_weekly_scores_is_inconsistent(): void
    {
        config(['ballspot.competition.period' => 'monthly', 'app.url' => 'https://ballpicker.test']);
        $user = User::factory()->create();
        $this->seedScores($user);
        // Monthly label and window, but the total is the week's 30, not the month's 70.
        $this->fakeLabelWithoutScoring($user->id, ['2026-11-01', '2026-11-30'], 30);

        $this->artisan('ballspot:competition-period-audit', ['--user' => $user->id, '--yes' => true])
            ->expectsOutputToContain('scores computed over: weekly')
            ->expectsOutputToContain("INCONSISTENT: the API says 'monthly'")
            ->assertFailed();

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_monthly_label_over_a_weekly_window_is_inconsistent(): void
    {
        config(['ballspot.competition.period' => 'monthly', 'app.url' => 'https://ballpicker.test']);
        $user = User::factory()->create();
        $this->seedScores($user);
        $this->fakeLabelWithoutScoring($user->id, ['2026-11-02', '2026-11-08'], 30);

        $this->artisan('ballspot:competition-period-audit', ['--user' => $user->id, '--yes' => true])
            ->expectsOutputToContain('= weekly; month 2026-11-01 → 2026-11-30')
            ->expectsOutputToContain("INCONSISTENT: the API says 'monthly'")
            ->assertFailed();
    }

    public function test_output_carries_no_token_name_or_email(): void
    {
        config(['ballspot.competition.period' => 'monthly', 'ballspot.competition.label' => null, 'app.url' => 'https://ballpicker.test']);
        $user = User::factory()->create(['username' => 'secretplayer', 'email' => 'private@example.test']);
        $this->seedScores($user);
        $this->routeHttpThroughTheApp();

        $this->artisan('ballspot:competition-period-audit', ['--user' => $user->id, '--yes' => true])
            ->doesntExpectOutputToContain('secretplayer')
            ->doesntExpectOutputToContain('private@example.test')
            ->doesntExpectOutputToContain('Bearer')
            ->assertSuccessful();
    }

    public function test_history_keeps_weekly_finishes_weekly_and_flags_the_old_badge_name(): void
    {
        config(['ballspot.competition.period' => 'monthly', 'ballspot.competition.label' => null, 'app.url' => 'https://ballpicker.test']);
        $user = User::factory()->create();
        Badge::create(['code' => 'weekly_top_10', 'name' => 'Weekly Top 10', 'description' => 'Finished in the weekly top 10.', 'icon' => '*', 'category' => 'daily', 'rarity' => 'rare']);
        CompetitionFinish::create([
            'user_id' => $user->id, 'period_type' => 'weekly', 'period_label' => 'Week 44, 2026',
            'period_start' => '2026-10-26', 'period_end' => '2026-11-01', 'placement' => 1,
            'total_score' => 99, 'total_players' => 5, 'xp_awarded' => 0,
        ]);
        $this->routeHttpThroughTheApp();

        $this->artisan('ballspot:competition-period-audit', ['--user' => $user->id, '--yes' => true])
            ->expectsOutputToContain('Closed competitions (Trophy Room history)')
            ->expectsOutputToContain('still the old name: run BadgeSeeder')
            ->assertSuccessful();

        $this->assertSame('weekly', CompetitionFinish::first()->period_type, 'the audit is read-only for history');
    }
}
