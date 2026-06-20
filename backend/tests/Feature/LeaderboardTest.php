<?php
namespace Tests\Feature;
use App\Models\Challenge;
use App\Models\Guess;
use App\Models\League;
use App\Models\LeagueMember;
use App\Models\LeagueRound;
use App\Models\Sport;
use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LeaderboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_leaderboard_shows_ranked_scores(): void
    {
        $sport = Sport::create(['name' => 'Football', 'slug' => 'football']);
        $challenge = Challenge::create(['sport_id' => $sport->id, 'title' => 'T', 'hidden_image_path' => 'x.jpg',
            'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5, 'difficulty' => 'easy', 'status' => 'active']);
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $token = $user1->createToken('test')->plainTextToken;

        $league = League::create(['name' => 'L', 'join_code' => 'ZZZZZZ', 'owner_user_id' => $user1->id,
            'sport_id' => $sport->id, 'duration_days' => 1, 'rounds_per_day' => 1, 'status' => 'active']);
        foreach ([$user1, $user2] as $u) {
            LeagueMember::create(['league_id' => $league->id, 'user_id' => $u->id, 'joined_at' => now()]);
        }
        $round = LeagueRound::create(['league_id' => $league->id, 'challenge_id' => $challenge->id,
            'round_number' => 1, 'status' => 'open']);
        Guess::create(['league_round_id' => $round->id, 'user_id' => $user1->id,
            'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5, 'distance' => 0.0, 'score' => 100, 'submitted_at' => now()]);
        Guess::create(['league_round_id' => $round->id, 'user_id' => $user2->id,
            'guess_x_ratio' => 0.8, 'guess_y_ratio' => 0.8, 'distance' => 0.424, 'score' => 0, 'submitted_at' => now()]);

        $this->getJson("/api/leagues/{$league->id}/leaderboard", ['Authorization' => "Bearer $token"])
            ->assertOk()
            ->assertJsonPath('data.0.rank', 1)
            ->assertJsonPath('data.0.total_score', 100);
    }
}
