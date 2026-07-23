<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Challenge;
use App\Models\CompetitionFinish;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeGuess;
use App\Models\Sport;
use App\Models\User;
use App\Models\XpEvent;
use App\Services\CompetitionCloseService;
use App\Services\CompetitionPeriodService;
use App\Services\CompetitionStandingsService;
use Carbon\Carbon;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompetitionCloseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Freeze mid-July so "previous period" is deterministically June 2026.
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 12, 0, 0, config('app.timezone')));
        config(['ballspot.competition.period' => 'monthly']);
        $this->seed(BadgeSeeder::class);
    }

    private function makeDaily(string $date): DailyChallenge
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $challenge = Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => 'T ' . $date,
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/test.jpg',
        ]);

        return DailyChallenge::create([
            'challenge_id'   => $challenge->id,
            'challenge_date' => $date,
            'status'         => 'active',
        ]);
    }

    private function guess(DailyChallenge $dc, User $user, int $score, ?string $submittedAt = null): void
    {
        DailyChallengeGuess::create([
            'daily_challenge_id' => $dc->id,
            'user_id'            => $user->id,
            'guess_x_ratio'      => 0.5,
            'guess_y_ratio'      => 0.5,
            'distance'           => 0.1,
            'score'              => $score,
            'submitted_at'       => $submittedAt ? Carbon::parse($submittedAt) : now(),
        ]);
    }

    /** Three players with distinct June totals: 90 / 70 / 50. */
    private function seedJuneField(): array
    {
        $dc = $this->makeDaily('2026-06-10');
        [$a, $b, $c] = User::factory()->count(3)->create()->all();
        $this->guess($dc, $a, 90);
        $this->guess($dc, $b, 70);
        $this->guess($dc, $c, 50);

        return [$a, $b, $c];
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->seedJuneField();

        $this->artisan('ballspot:close-competition', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertSame(0, CompetitionFinish::count());
        $this->assertSame(0, XpEvent::where('source_type', XpEvent::SOURCE_COMPETITION_FINISH)->count());
    }

    public function test_close_creates_top_three_finishes_with_xp_and_badges(): void
    {
        [$a, $b, $c] = $this->seedJuneField();

        $this->artisan('ballspot:close-competition')->assertExitCode(0);

        $this->assertSame(3, CompetitionFinish::count());

        $first = CompetitionFinish::where('user_id', $a->id)->first();
        $this->assertSame(1, $first->placement);
        $this->assertSame(90, $first->total_score);
        $this->assertSame(3, $first->total_players);
        $this->assertSame('monthly', $first->period_type);
        $this->assertSame('June 2026', $first->period_label);
        $this->assertSame('2026-06-01', $first->period_start->toDateString());
        $this->assertSame('2026-06-30', $first->period_end->toDateString());
        $this->assertSame(2000, $first->xp_awarded);
        $this->assertNotNull($first->awarded_at);

        // XP through the ledger with placement-specific amounts/reasons.
        $this->assertSame(2000, (int) XpEvent::where('user_id', $a->id)->where('source_type', XpEvent::SOURCE_COMPETITION_FINISH)->sum('amount'));
        $this->assertSame(1000, (int) XpEvent::where('user_id', $b->id)->where('source_type', XpEvent::SOURCE_COMPETITION_FINISH)->sum('amount'));
        $this->assertSame(500, (int) XpEvent::where('user_id', $c->id)->where('source_type', XpEvent::SOURCE_COMPETITION_FINISH)->sum('amount'));
        $this->assertSame('Monthly competition winner', XpEvent::where('user_id', $a->id)->where('source_type', XpEvent::SOURCE_COMPETITION_FINISH)->value('reason'));

        // Badges: winner gets monthly_winner + monthly_podium; 2nd/3rd get podium only.
        $this->assertTrue($a->badges()->where('code', 'monthly_winner')->exists());
        $this->assertTrue($a->badges()->where('code', 'monthly_podium')->exists());
        $this->assertFalse($b->badges()->where('code', 'monthly_winner')->exists());
        $this->assertTrue($b->badges()->where('code', 'monthly_podium')->exists());
        $this->assertTrue($c->badges()->where('code', 'monthly_podium')->exists());
    }

    public function test_no_fake_placements_with_fewer_than_three_players(): void
    {
        $dc = $this->makeDaily('2026-06-05');
        $only = User::factory()->create();
        $this->guess($dc, $only, 80);

        $this->artisan('ballspot:close-competition')->assertExitCode(0);

        $this->assertSame(1, CompetitionFinish::count());
        $finish = CompetitionFinish::first();
        $this->assertSame(1, $finish->placement);
        $this->assertSame(1, $finish->total_players);
    }

    public function test_no_players_exits_cleanly_with_no_records(): void
    {
        $this->artisan('ballspot:close-competition')
            ->expectsOutputToContain('No eligible players')
            ->assertExitCode(0);

        $this->assertSame(0, CompetitionFinish::count());
    }

    public function test_close_is_idempotent_no_duplicate_finishes_xp_or_badge_xp(): void
    {
        $this->seedJuneField();

        $this->artisan('ballspot:close-competition')->assertExitCode(0);
        $finishes = CompetitionFinish::count();
        $xpTotal  = (int) XpEvent::sum('amount');

        $this->artisan('ballspot:close-competition')
            ->expectsOutputToContain('already closed')
            ->assertExitCode(0);

        $this->assertSame($finishes, CompetitionFinish::count());
        $this->assertSame($xpTotal, (int) XpEvent::sum('amount'));
    }

    public function test_current_open_period_is_not_closed_by_default(): void
    {
        // Guesses only exist in the CURRENT month (July) — the default close
        // targets June, which is empty, so nothing may be written.
        $dc = $this->makeDaily('2026-07-10');
        $this->guess($dc, User::factory()->create(), 95);

        $this->artisan('ballspot:close-competition')->assertExitCode(0);
        $this->assertSame(0, CompetitionFinish::count());

        // Asking for the open period explicitly is refused without --force.
        $this->artisan('ballspot:close-competition', ['--period' => '2026-07'])
            ->assertExitCode(1);
        $this->assertSame(0, CompetitionFinish::count());

        // --force allows it deliberately.
        $this->artisan('ballspot:close-competition', ['--period' => '2026-07', '--force' => true])
            ->assertExitCode(0);
        $this->assertSame(1, CompetitionFinish::count());
    }

    public function test_period_override_selects_the_requested_month(): void
    {
        $dc = $this->makeDaily('2026-05-20');
        $this->guess($dc, User::factory()->create(), 60);

        $this->artisan('ballspot:close-competition', ['--period' => '2026-05'])->assertExitCode(0);

        $finish = CompetitionFinish::first();
        $this->assertSame('May 2026', $finish->period_label);
        $this->assertSame('2026-05-01', $finish->period_start->toDateString());
        $this->assertSame('2026-05-31', $finish->period_end->toDateString());
    }

    public function test_invalid_period_format_fails(): void
    {
        $this->artisan('ballspot:close-competition', ['--period' => 'June-2026'])->assertExitCode(1);
        $this->assertSame(0, CompetitionFinish::count());
    }

    public function test_tie_handling_is_deterministic(): void
    {
        $dc = $this->makeDaily('2026-06-12');
        $late  = User::factory()->create();
        $early = User::factory()->create();

        // Same total — whoever reached it earlier wins the tie.
        $this->guess($dc, $late, 80, '2026-06-12 18:00:00');
        $this->guess($dc, $early, 80, '2026-06-12 09:00:00');

        $standings = app(CompetitionStandingsService::class)->forWindow('2026-06-01', '2026-06-30');
        $this->assertSame($early->id, $standings[0]['user_id']);
        $this->assertSame($late->id, $standings[1]['user_id']);

        // Identical score AND time — lower user id wins as the stable fallback.
        $dc2 = $this->makeDaily('2026-06-13');
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        $this->guess($dc2, $u2, 70, '2026-06-13 10:00:00');
        $this->guess($dc2, $u1, 70, '2026-06-13 10:00:00');

        $standings = app(CompetitionStandingsService::class)->forWindow('2026-06-13', '2026-06-13');
        $this->assertSame(min($u1->id, $u2->id), $standings[0]['user_id']);
    }

    public function test_monthly_top_10_badge_awarded_in_field_of_ten_or_more(): void
    {
        $dc = $this->makeDaily('2026-06-08');
        $users = User::factory()->count(10)->create()->values();
        foreach ($users as $i => $u) {
            $this->guess($dc, $u, 100 - ($i * 5)); // distinct scores, index 0 wins
        }

        $this->artisan('ballspot:close-competition')->assertExitCode(0);

        // ceil(10 * 0.1) = 1 — only the winner is in the top 10%.
        $this->assertTrue($users[0]->badges()->where('code', 'monthly_top_10')->exists());
        $this->assertFalse($users[1]->badges()->where('code', 'monthly_top_10')->exists());
        // Still only 3 finish records — the badge never creates extra placements.
        $this->assertSame(3, CompetitionFinish::count());
    }

    public function test_weekly_close_stores_finishes_without_monthly_badges(): void
    {
        // Week 24 of 2026: Mon 8 Jun – Sun 14 Jun.
        $dc = $this->makeDaily('2026-06-10');
        $winner = User::factory()->create();
        $this->guess($dc, $winner, 85);

        $this->artisan('ballspot:close-competition', ['--type' => 'weekly', '--period' => '2026-24'])
            ->assertExitCode(0);

        $finish = CompetitionFinish::first();
        $this->assertSame('weekly', $finish->period_type);
        $this->assertSame('Week 24, 2026', $finish->period_label);
        $this->assertSame('2026-06-08', $finish->period_start->toDateString());
        $this->assertSame('2026-06-14', $finish->period_end->toDateString());
        $this->assertSame(2000, $finish->xp_awarded);
        $this->assertSame('Weekly competition winner', XpEvent::where('user_id', $winner->id)->where('source_type', XpEvent::SOURCE_COMPETITION_FINISH)->value('reason'));

        // monthly_* badges are monthly-only by design.
        $this->assertFalse($winner->badges()->where('code', 'monthly_winner')->exists());
    }

    public function test_announce_flag_saves_draft_notification_without_sending(): void
    {
        $this->seedJuneField();

        $this->artisan('ballspot:close-competition', ['--announce' => true])->assertExitCode(0);

        $this->assertSame(1, AdminNotification::count());
        $n = AdminNotification::first();
        $this->assertSame(AdminNotification::STATUS_DRAFT, $n->status);
        $this->assertNull($n->sent_at);
        $this->assertStringContainsString('June 2026', $n->body);

        // Idempotent rerun (already closed) must not create a second draft.
        $this->artisan('ballspot:close-competition', ['--announce' => true])->assertExitCode(0);
        $this->assertSame(1, AdminNotification::count());
    }

    public function test_anonymized_user_keeps_historical_finish(): void
    {
        [$a] = $this->seedJuneField();
        $this->artisan('ballspot:close-competition')->assertExitCode(0);

        // Account deletion anonymizes in place (row survives) — finish stays linked.
        $token = $a->createToken('t')->plainTextToken;
        $this->deleteJson('/api/account', [], ['Authorization' => "Bearer $token"])->assertOk();

        $finish = CompetitionFinish::where('placement', 1)->first();
        $this->assertSame($a->id, $finish->user_id);
        $this->assertSame(1, $finish->placement);
    }

    public function test_trophy_room_api_returns_competition_finishes(): void
    {
        [$a] = $this->seedJuneField();
        $this->artisan('ballspot:close-competition')->assertExitCode(0);

        $token = $a->createToken('t')->plainTextToken;

        $this->getJson('/api/me/competition-finishes', ['Authorization' => "Bearer $token"])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.placement', 1)
            ->assertJsonPath('data.0.period_type', 'monthly')
            ->assertJsonPath('data.0.period_label', 'June 2026')
            ->assertJsonPath('data.0.period_start', '2026-06-01')
            ->assertJsonPath('data.0.period_end', '2026-06-30')
            ->assertJsonPath('data.0.total_score', 90)
            ->assertJsonPath('data.0.total_players', 3)
            ->assertJsonPath('data.0.xp_awarded', 2000);
    }

    public function test_trophy_room_api_empty_state(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $this->getJson('/api/me/competition-finishes', ['Authorization' => "Bearer $token"])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_service_refuses_open_period_without_force(): void
    {
        $period = app(CompetitionPeriodService::class)->describe('monthly', Carbon::create(2026, 7, 15));
        $result = app(CompetitionCloseService::class)->close($period);

        $this->assertSame(CompetitionCloseService::STATUS_REFUSED_OPEN, $result['status']);
        $this->assertSame(0, CompetitionFinish::count());
    }
}
