<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\Sport;
use App\Models\User;
use App\Services\CompetitionPeriodService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
