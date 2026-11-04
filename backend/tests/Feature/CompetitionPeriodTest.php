<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\Sport;
use App\Models\User;
use App\Services\CompetitionPeriodService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CompetitionPeriodTest extends TestCase
{
    use RefreshDatabase;

    private function auth(): array
    {
        $user = User::factory()->create();
        return [$user, $user->createToken('test')->plainTextToken];
    }

    public function test_monthly_config_returns_monthly_label_and_calendar_window(): void
    {
        config(['ballspot.competition.period' => 'monthly']);
        $service = new CompetitionPeriodService();

        $now = Carbon::create(2026, 7, 15, 12, 0, 0, config('app.timezone'));
        $p = $service->toArray($now);

        $this->assertSame('monthly', $p['period_type']);
        $this->assertSame('Monthly', $p['period_label']);
        $this->assertSame('2026-07-01', $p['period_start']);
        $this->assertSame('2026-07-31', $p['period_end']);
    }

    public function test_weekly_config_returns_weekly_window(): void
    {
        config(['ballspot.competition.period' => 'weekly']);
        $service = new CompetitionPeriodService();

        // Wednesday 15 Jul 2026 -> week Mon 13 .. Sun 19.
        $now = Carbon::create(2026, 7, 15, 12, 0, 0, config('app.timezone'));
        $p = $service->toArray($now);

        $this->assertSame('weekly', $p['period_type']);
        $this->assertSame('Weekly', $p['period_label']);
        $this->assertSame('2026-07-13', $p['period_start']);
        $this->assertSame('2026-07-19', $p['period_end']);
    }

    public function test_label_can_be_overridden(): void
    {
        config(['ballspot.competition.period' => 'monthly', 'ballspot.competition.label' => 'Season']);
        $this->assertSame('Season', (new CompetitionPeriodService())->label());
    }

    public function test_leaderboard_response_includes_period_block(): void
    {
        config(['ballspot.competition.period' => 'monthly']);
        [, $token] = $this->auth();

        $res = $this->withToken($token)->getJson('/api/daily/leaderboard/weekly');

        $res->assertOk();
        $res->assertJsonPath('period.period_type', 'monthly');
        $res->assertJsonPath('period_label', 'Monthly');
        $res->assertJsonStructure(['period' => ['period_type', 'period_label', 'period_start', 'period_end']]);
        $this->assertNotNull($res->json('period.period_start'));
        $this->assertNotNull($res->json('period.period_end'));
    }

    /**
     * The app labels the Daily Result button from /daily/stats and the board from
     * /daily/leaderboard/weekly, so both must name the same, configured period.
     */
    #[DataProvider('periodProvider')]
    public function test_stats_and_leaderboard_name_the_configured_period_and_use_its_window(
        string $type,
        string $label,
        string $start,
        string $end,
        int $expectedScore,
    ): void {
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 12, 0, 0, config('app.timezone')));
        config(['ballspot.competition.period' => $type, 'ballspot.competition.label' => null]);
        [$user, $token] = $this->auth();

        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $challenge = Challenge::create([
            'sport_id' => $sport->id, 'title' => 'C', 'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5,
            'difficulty' => 'easy', 'status' => 'active', 'hidden_image_path' => 'x.jpg',
        ]);

        // 14 Jul is in the current week; 2 Jul is only in the current month.
        foreach (['2026-07-14' => 10, '2026-07-02' => 32] as $date => $score) {
            $dc = DailyChallenge::create(['challenge_id' => $challenge->id, 'challenge_date' => $date, 'status' => 'active']);
            \App\Models\DailyChallengeGuess::create([
                'daily_challenge_id' => $dc->id, 'user_id' => $user->id,
                'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5,
                'score' => $score, 'distance' => 0.0,
                'submitted_at' => Carbon::parse($date)->setTime(12, 0),
            ]);
        }

        $period = ['period_type' => $type, 'period_label' => $label, 'period_start' => $start, 'period_end' => $end];

        $board = $this->withToken($token)->getJson('/api/daily/leaderboard/weekly');
        $board->assertOk()
            ->assertJsonPath('period', $period)
            ->assertJsonPath('week_start', $start)
            ->assertJsonPath('week_end', $end)
            ->assertJsonPath('data.0.total_score', $expectedScore)
            ->assertJsonPath('meta.current_user_score', $expectedScore);

        $this->withToken($token)->getJson('/api/daily/stats')
            ->assertOk()
            ->assertJsonPath('period', $period)
            ->assertJsonPath('weekly_rank', 1);

        Carbon::setTestNow();
    }

    public static function periodProvider(): array
    {
        return [
            'weekly'  => ['weekly', 'Weekly', '2026-07-13', '2026-07-19', 10],
            'monthly' => ['monthly', 'Monthly', '2026-07-01', '2026-07-31', 42],
        ];
    }

    /**
     * Without a usable period setting (unset, empty, typo, wrong case) the API
     * still names monthly — never weekly — on both endpoints the app reads, for
     * a player with no score this period too.
     */
    #[DataProvider('unusablePeriodProvider')]
    public function test_unusable_period_config_falls_back_to_monthly_not_weekly(mixed $raw): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 3, 12, 0, 0, config('app.timezone')));
        config(['ballspot.competition.period' => $raw, 'ballspot.competition.label' => null]);
        [, $token] = $this->auth();

        $period = ['period_type' => 'monthly', 'period_label' => 'Monthly', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30'];

        $this->withToken($token)->getJson('/api/daily/stats')
            ->assertOk()
            ->assertJsonPath('period', $period)
            ->assertJsonPath('weekly_rank', null);

        $this->withToken($token)->getJson('/api/daily/leaderboard/weekly')
            ->assertOk()
            ->assertJsonPath('period', $period)
            ->assertJsonPath('period_label', 'Monthly')
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.current_user_rank', null);

        Carbon::setTestNow();
    }

    public static function unusablePeriodProvider(): array
    {
        return [
            'unset'      => [null],
            'empty'      => [''],
            'typo'       => ['montly'],
            'wrong case' => ['Weekly'],
        ];
    }

    public function test_top_10_badge_copy_names_no_period_and_keeps_its_code(): void
    {
        $this->seed(\Database\Seeders\BadgeSeeder::class);

        $badge = \App\Models\Badge::where('code', 'weekly_top_10')->firstOrFail();

        $this->assertDoesNotMatchRegularExpression('/week|month/i', $badge->name . ' ' . $badge->description);
    }

    public function test_monthly_leaderboard_aggregates_the_whole_month_not_just_the_week(): void
    {
        config(['ballspot.competition.period' => 'monthly']);
        [$user, $token] = $this->auth();

        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $challenge = Challenge::create([
            'sport_id' => $sport->id, 'title' => 'C', 'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5,
            'difficulty' => 'easy', 'status' => 'active', 'hidden_image_path' => 'x.jpg',
        ]);

        // A daily earlier this month but in a previous week. Guess it directly.
        $start = Carbon::parse((new CompetitionPeriodService())->start());
        // Pick a date inside the month but likely outside the current week: the 2nd.
        $early = $start->copy()->addDay();
        $dc = DailyChallenge::create([
            'challenge_id' => $challenge->id,
            'challenge_date' => $early->toDateString(),
            'status' => 'active',
        ]);
        \App\Models\DailyChallengeGuess::create([
            'daily_challenge_id' => $dc->id,
            'user_id' => $user->id,
            'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5,
            'score' => 42, 'distance' => 0.0,
            'submitted_at' => $early->copy()->setTime(12, 0),
        ]);

        $res = $this->withToken($token)->getJson('/api/daily/leaderboard/weekly');

        $res->assertOk();
        // The early-month guess is included because the window is the whole month.
        $this->assertNotEmpty($res->json('data'));
        $this->assertSame(42, $res->json('data.0.total_score'));
    }
}
