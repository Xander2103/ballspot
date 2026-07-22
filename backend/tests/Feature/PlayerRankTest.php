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
use App\Services\PlayerRankService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerRankTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PlayerRankService
    {
        return app(PlayerRankService::class);
    }

    private function challenge(): Challenge
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football', 'status' => Sport::STATUS_ACTIVE]);
        return Challenge::create([
            'sport_id' => $sport->id, 'title' => 'C', 'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5,
            'difficulty' => 'easy', 'status' => 'active', 'hidden_image_path' => 'challenges/hidden/x.jpg',
        ]);
    }

    private function tournamentGuess(User $user, int $score): void
    {
        $challenge = $this->challenge();
        $league = League::create([
            'name' => 'L', 'join_code' => strtoupper(\Illuminate\Support\Str::random(6)),
            'owner_user_id' => $user->id, 'sport_id' => $challenge->sport_id,
            'duration_days' => 1, 'rounds_per_day' => 1, 'status' => 'active',
        ]);
        $round = LeagueRound::create([
            'league_id' => $league->id, 'challenge_id' => $challenge->id, 'round_number' => 1, 'status' => 'open',
        ]);
        Guess::create([
            'league_round_id' => $round->id, 'user_id' => $user->id,
            'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5, 'distance' => 0.0, 'score' => $score, 'submitted_at' => now(),
        ]);
    }

    private function dailyGuess(User $user, int $score): void
    {
        $challenge = $this->challenge();
        $dc = DailyChallenge::create([
            'challenge_id' => $challenge->id, 'challenge_date' => today()->toDateString(), 'status' => 'active',
        ]);
        DailyChallengeGuess::create([
            'daily_challenge_id' => $dc->id, 'user_id' => $user->id,
            'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5, 'distance' => 0.0, 'score' => $score, 'submitted_at' => now(),
        ]);
    }

    public function test_new_user_is_rookie_with_zero_xp(): void
    {
        $rank = $this->service()->forUser(User::factory()->create());

        $this->assertSame('Rookie', $rank['name']);
        $this->assertSame(1, $rank['level']);
        $this->assertSame(0, $rank['total_xp']);
        $this->assertSame('Amateur', $rank['next_rank_name']);
        $this->assertSame(2500, $rank['xp_to_next_rank']);
        $this->assertFalse($rank['is_max_rank']);
    }

    public function test_tournament_scores_count_as_xp(): void
    {
        $user = User::factory()->create();
        $this->tournamentGuess($user, 800);
        $this->tournamentGuess($user, 700);

        $this->assertSame(1500, $this->service()->totalXpForUser($user));
    }

    public function test_daily_scores_count_as_xp(): void
    {
        $user = User::factory()->create();
        $this->dailyGuess($user, 900);

        $this->assertSame(900, $this->service()->totalXpForUser($user));
    }

    public function test_combined_xp_crosses_rank_threshold(): void
    {
        $user = User::factory()->create();
        $this->tournamentGuess($user, 2000);
        $this->dailyGuess($user, 1000); // 3000 total → Amateur (>=2500)

        $rank = $this->service()->forUser($user);
        $this->assertSame('Amateur', $rank['name']);
        $this->assertSame(2, $rank['level']);
        $this->assertSame(3000, $rank['total_xp']);
    }

    public function test_progress_percentage_is_correct(): void
    {
        // Pro band is 10,000–25,000. 12,550 XP → 2,550 / 15,000 ≈ 17%.
        $rank = $this->service()->forXp(12550);

        $this->assertSame('Pro', $rank['name']);
        $this->assertSame(3, $rank['level']);
        $this->assertSame('Elite', $rank['next_rank_name']);
        $this->assertSame(12450, $rank['xp_to_next_rank']);
        $this->assertSame(17, $rank['progress_to_next_rank_pct']);
    }

    public function test_max_rank_has_no_next(): void
    {
        $rank = $this->service()->forXp(150000);

        $this->assertSame('Ball Master', $rank['name']);
        $this->assertSame(6, $rank['level']);
        $this->assertTrue($rank['is_max_rank']);
        $this->assertNull($rank['next_rank_name']);
        $this->assertNull($rank['next_rank_xp']);
        $this->assertNull($rank['xp_to_next_rank']);
        $this->assertSame(100, $rank['progress_to_next_rank_pct']);
    }

    public function test_profile_stats_includes_rank(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $res = $this->withToken($token)->getJson('/api/profile/stats');

        $res->assertOk()->assertJsonStructure([
            'rank' => [
                'name', 'level', 'total_xp', 'current_rank_min_xp',
                'next_rank_name', 'next_rank_xp', 'xp_to_next_rank',
                'progress_to_next_rank_pct', 'is_max_rank',
            ],
        ]);
        $res->assertJsonPath('rank.name', 'Rookie');
    }

    public function test_me_rank_endpoint(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/me/rank')
            ->assertOk()
            ->assertJsonPath('rank.name', 'Rookie');
    }
}
