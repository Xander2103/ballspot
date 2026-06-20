<?php
namespace Tests\Feature;
use App\Models\Challenge;
use App\Models\League;
use App\Models\LeagueMember;
use App\Models\LeagueRound;
use App\Models\Sport;
use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GuessTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_submit_guess_and_receive_score(): void
    {
        $sport = Sport::create(['name' => 'Football', 'slug' => 'football']);
        $challenge = Challenge::create(['sport_id' => $sport->id, 'title' => 'T', 'hidden_image_path' => 'x.jpg',
            'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5, 'difficulty' => 'easy', 'status' => 'active']);
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $league = League::create(['name' => 'L', 'join_code' => 'XXXXXX', 'owner_user_id' => $user->id,
            'sport_id' => $sport->id, 'duration_days' => 1, 'rounds_per_day' => 1, 'status' => 'active']);
        LeagueMember::create(['league_id' => $league->id, 'user_id' => $user->id, 'joined_at' => now()]);
        $round = LeagueRound::create(['league_id' => $league->id, 'challenge_id' => $challenge->id,
            'round_number' => 1, 'status' => 'open']);

        $this->postJson("/api/rounds/{$round->id}/guess", ['guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5],
            ['Authorization' => "Bearer $token"])
            ->assertStatus(201)
            ->assertJsonFragment(['score' => 100]);
    }

    public function test_duplicate_guess_is_rejected(): void
    {
        $sport = Sport::create(['name' => 'Football', 'slug' => 'football']);
        $challenge = Challenge::create(['sport_id' => $sport->id, 'title' => 'T', 'hidden_image_path' => 'x.jpg',
            'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5, 'difficulty' => 'easy', 'status' => 'active']);
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $league = League::create(['name' => 'L2', 'join_code' => 'YYYYYY', 'owner_user_id' => $user->id,
            'sport_id' => $sport->id, 'duration_days' => 1, 'rounds_per_day' => 1, 'status' => 'active']);
        LeagueMember::create(['league_id' => $league->id, 'user_id' => $user->id, 'joined_at' => now()]);
        $round = LeagueRound::create(['league_id' => $league->id, 'challenge_id' => $challenge->id,
            'round_number' => 1, 'status' => 'open']);

        $headers = ['Authorization' => "Bearer $token"];
        $this->postJson("/api/rounds/{$round->id}/guess", ['guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5], $headers)->assertStatus(201);
        $this->postJson("/api/rounds/{$round->id}/guess", ['guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5], $headers)->assertStatus(422);
    }
}
