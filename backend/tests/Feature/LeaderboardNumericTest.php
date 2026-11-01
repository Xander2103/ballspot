<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeGuess;
use App\Models\Guess;
use App\Models\League;
use App\Models\LeagueMember;
use App\Models\LeagueRound;
use App\Models\Sport;
use App\Models\User;
use App\Models\XpEvent;
use App\Services\CompetitionStandingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs unchanged on SQLite and MariaDB (see phpunit.xml; DB_* env overrides it).
 * MariaDB returns SUM()/AVG() as DECIMAL strings, SQLite as numbers: the API must
 * answer the same JSON types and the same order on both.
 */
class LeaderboardNumericTest extends TestCase
{
    use RefreshDatabase;

    private Sport $sport;

    private Challenge $challenge;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ballspot.competition.period' => 'monthly', 'ballspot.competition.timezone' => 'UTC']);
        Carbon::setTestNow('2026-11-15 12:00:00');

        $this->sport = Sport::create(['name' => 'Football', 'slug' => 'football']);
        $this->challenge = $this->makeChallenge('T');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeChallenge(string $title): Challenge
    {
        return Challenge::create(['sport_id' => $this->sport->id, 'title' => $title, 'hidden_image_path' => 'x.jpg',
            'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5, 'difficulty' => 'easy', 'status' => 'active']);
    }

    private function user(string $username): User
    {
        return User::factory()->create(['username' => $username]);
    }

    private function league(array $users): LeagueRound
    {
        $league = League::create(['name' => 'L', 'join_code' => strtoupper(substr(uniqid(), -6)), 'owner_user_id' => $users[0]->id,
            'sport_id' => $this->sport->id, 'duration_days' => 1, 'rounds_per_day' => 1, 'status' => 'active']);
        foreach ($users as $u) {
            LeagueMember::create(['league_id' => $league->id, 'user_id' => $u->id, 'joined_at' => now()]);
        }

        return LeagueRound::create(['league_id' => $league->id, 'challenge_id' => $this->challenge->id,
            'round_number' => 1, 'status' => 'open']);
    }

    private function roundGuess(LeagueRound $round, User $u, int $score, ?string $at = null): void
    {
        Guess::create(['league_round_id' => $round->id, 'user_id' => $u->id, 'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5,
            'distance' => 0.1, 'score' => $score, 'submitted_at' => $at ?? now()]);
    }

    private function dailyGuess(string $date, User $u, int $score, string $at): void
    {
        $dc = DailyChallenge::firstOrCreate(['challenge_date' => $date], [
            'challenge_id' => $this->makeChallenge('Daily ' . $date)->id, 'status' => 'active',
        ]);
        DailyChallengeGuess::create(['daily_challenge_id' => $dc->id, 'user_id' => $u->id, 'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5,
            'distance' => 0.1, 'score' => $score, 'submitted_at' => $at]);
    }

    private function board(LeagueRound $round, User $viewer): array
    {
        return $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/leagues/{$round->league_id}/leaderboard")
            ->assertOk()->json();
    }

    public function test_tournament_scores_are_json_numbers_in_rows_and_meta(): void
    {
        [$a, $b] = [$this->user('aaa'), $this->user('bbb')];
        $round = $this->league([$a, $b]);
        $this->roundGuess($round, $a, 75);
        $this->roundGuess($round, $b, 50);

        $res = $this->board($round, $a);

        foreach (array_merge($res['data'], $res['meta']['top_users'], $res['meta']['nearby_users']) as $row) {
            $this->assertIsInt($row['user_id']);
            $this->assertIsInt($row['total_score']);
            $this->assertIsInt($row['guesses_count']);
            $this->assertTrue(is_int($row['avg_score']) || is_float($row['avg_score']), 'avg_score is a number');
        }
        $this->assertSame(75, $res['meta']['current_user_score']);
        $this->assertSame(75.0, (float) $res['meta']['current_user_average']);
        // The raw JSON has no quoted score, whatever the driver returned.
        $this->assertStringNotContainsString('"total_score":"', json_encode($res));
    }

    public function test_tournament_order_is_numeric_not_alphabetical(): void
    {
        // As strings "9" > "100" > "1000", and the names run the other way.
        [$nine, $hundred, $thousand] = [$this->user('aaa-nine'), $this->user('mmm-hundred'), $this->user('zzz-thousand')];
        $round = $this->league([$nine, $hundred, $thousand]);
        $this->roundGuess($round, $nine, 9);
        $this->roundGuess($round, $hundred, 100);
        $this->roundGuess($round, $thousand, 1000);

        $res = $this->board($round, $nine);

        $this->assertSame([1000, 100, 9], array_column($res['data'], 'total_score'));
        $this->assertSame([1, 2, 3], array_column($res['data'], 'rank'));
        $this->assertSame(3, $res['meta']['current_user_rank']);
    }

    public function test_tournament_ties_follow_the_final_placement_rule(): void
    {
        [$late, $early, $sameTimeLow, $sameTimeHigh] = [$this->user('a-late'), $this->user('b-early'), $this->user('c-same'), $this->user('d-same')];
        $round = $this->league([$late, $early, $sameTimeLow, $sameTimeHigh]);
        $this->roundGuess($round, $late, 80, '2026-11-15 11:00:00');
        $this->roundGuess($round, $early, 80, '2026-11-15 09:00:00');
        $this->roundGuess($round, $sameTimeHigh, 80, '2026-11-15 10:00:00');
        $this->roundGuess($round, $sameTimeLow, 80, '2026-11-15 10:00:00');

        $res = $this->board($round, $late);
        $live = array_column($res['data'], 'user_id');

        $this->assertSame([$early->id, $sameTimeLow->id, $sameTimeHigh->id, $late->id], $live);

        $final = app(\App\Services\TournamentCompletionService::class)->calculateStandings(League::find($round->league_id));
        $this->assertSame(array_column($final, 'user_id'), $live, 'live board equals the final placement');
    }

    public function test_large_totals_stay_exact_integers(): void
    {
        $user = $this->user('big');
        $round = $this->league([$user]);
        // 3 x 2,000,000,001 exceeds 32 bits; a float or int32 path would corrupt it.
        foreach (range(1, 3) as $n) {
            $extra = LeagueRound::create(['league_id' => $round->league_id, 'challenge_id' => $this->makeChallenge("R$n")->id,
                'round_number' => $n + 1, 'status' => 'open']);
            $this->roundGuess($extra, $user, 2000000001);
        }

        $res = $this->board($round, $user);

        $this->assertSame(6000000003, $res['data'][0]['total_score']);
        $this->assertSame(6000000003, $res['meta']['current_user_score']);
    }

    public function test_competition_board_and_stats_rank_agree_on_ties_and_numbers(): void
    {
        [$late, $early, $low, $gone] = [$this->user('a-late'), $this->user('b-early'), $this->user('c-low'), $this->user('d-gone')];
        $this->dailyGuess('2026-11-02', $late, 90, '2026-11-02 18:00:00');
        $this->dailyGuess('2026-11-02', $early, 90, '2026-11-02 08:00:00');
        $this->dailyGuess('2026-11-02', $low, 9, '2026-11-02 07:00:00');
        $this->dailyGuess('2026-11-02', $gone, 100, '2026-11-02 07:00:00');
        $gone->forceFill(['anonymized_at' => now()])->save();

        $board = $this->actingAs($late, 'sanctum')->getJson('/api/daily/leaderboard/weekly')->assertOk()->json();
        $this->assertSame([$early->id, $late->id, $low->id], array_column($board['data'], 'user_id'));
        $this->assertSame([90, 90, 9], array_column($board['data'], 'total_score'));
        $this->assertSame(90, $board['meta']['current_user_score']);

        foreach ($board['data'] as $row) {
            $stats = $this->actingAs(User::find($row['user_id']), 'sanctum')->getJson('/api/daily/stats')->assertOk();
            $this->assertSame($row['rank'], $stats->json('weekly_rank'), 'stats rank equals the visible board');
        }

        // A deleted account holds no place in either ranking.
        $this->assertFalse(app(CompetitionStandingsService::class)->rankedUserIds('2026-11-01', '2026-11-30')->contains($gone->id));
    }

    public function test_ranked_user_ids_match_the_full_standings(): void
    {
        $users = collect(range(1, 6))->map(fn ($i) => $this->user("u$i"));
        foreach ($users as $i => $u) {
            $this->dailyGuess('2026-11-03', $u, ($i % 3) * 40, '2026-11-03 0' . (9 - $i) . ':00:00');
            $this->dailyGuess('2026-11-04', $u, 10, '2026-11-04 10:00:00');
        }

        $service = app(CompetitionStandingsService::class);

        $this->assertSame(
            $service->forWindow('2026-11-01', '2026-11-30')->pluck('user_id')->all(),
            $service->rankedUserIds('2026-11-01', '2026-11-30')->all(),
        );
    }

    public function test_profile_public_profile_and_export_numbers_are_typed(): void
    {
        [$me, $other] = [$this->user('me'), $this->user('other')];
        $round = $this->league([$me, $other]);
        $this->roundGuess($round, $me, 33);
        $this->roundGuess($round, $other, 44);

        $stats = $this->actingAs($me, 'sanctum')->getJson('/api/profile/stats')->assertOk();
        $this->assertSame(33, $stats->json('total_score'));

        $public = $this->actingAs($other, 'sanctum')->getJson("/api/users/{$me->id}/public-profile")->assertOk();
        $this->assertSame(33, $public->json('data.stats.total_score'));

        $export = $this->actingAs($me, 'sanctum')->getJson('/api/me/export')->assertOk();
        $summary = $export->json('tournament_guesses_summary') ?? $export->json('data.tournament_guesses_summary');
        $this->assertSame(1, $summary['count']);
        $this->assertSame(33, $summary['total_score']);
        $this->assertEquals(33.0, $summary['average_score']);
    }

    public function test_reading_rankings_changes_no_score_xp_or_rank_data(): void
    {
        [$a, $b] = [$this->user('aa'), $this->user('bb')];
        $round = $this->league([$a, $b]);
        $this->roundGuess($round, $a, 60);
        $this->dailyGuess('2026-11-05', $a, 70, '2026-11-05 10:00:00');
        XpEvent::create(['user_id' => $a->id, 'amount' => 25, 'source_type' => 'test', 'source_id' => 1, 'reason' => 'seed']);

        $snapshot = fn () => [
            DB::table('guesses')->orderBy('id')->get(['id', 'score'])->toArray(),
            DB::table('daily_challenge_guesses')->orderBy('id')->get(['id', 'score'])->toArray(),
            DB::table('xp_events')->orderBy('id')->get(['id', 'amount'])->toArray(),
            DB::table('user_badges')->count(),
            DB::table('competition_finishes')->count(),
        ];
        $before = $snapshot();

        $this->board($round, $a);
        $this->actingAs($a, 'sanctum')->getJson('/api/daily/leaderboard/weekly')->assertOk();
        $this->actingAs($a, 'sanctum')->getJson('/api/daily/stats')->assertOk();

        $this->assertEquals($before, $snapshot());
    }
}
