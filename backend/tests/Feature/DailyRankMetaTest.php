<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeGuess;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyRankMetaTest extends TestCase
{
    use RefreshDatabase;

    private function activeDaily(): DailyChallenge
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $challenge = Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => 'Rank Meta Challenge',
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/test.jpg',
        ]);
        return DailyChallenge::create([
            'challenge_id'   => $challenge->id,
            'challenge_date' => today()->toDateString(),
            'status'         => 'active',
        ]);
    }

    private function seedGuess(DailyChallenge $dc, int $score): void
    {
        DailyChallengeGuess::create([
            'daily_challenge_id' => $dc->id,
            'user_id'            => User::factory()->create()->id,
            'guess_x_ratio'      => 0.5,
            'guess_y_ratio'      => 0.5,
            'distance'           => 0.1,
            'score'              => $score,
            'submitted_at'       => now(),
        ]);
    }

    public function test_daily_result_includes_rank_and_percentile(): void
    {
        $dc = $this->activeDaily();
        // 9 other players scoring below 90 → our 90 beats all of them.
        foreach ([10, 20, 30, 40, 50, 60, 70, 80, 85] as $s) {
            $this->seedGuess($dc, $s);
        }

        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/daily/{$dc->id}/guess", [
            'guess_x_ratio' => 0.5,
            'guess_y_ratio' => 0.5,
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['rank', 'total_players', 'better_than_percentage', 'score', 'distance']]);

        // 10 players total, our perfect guess (score 100) is rank 1, beats the other 9.
        $this->assertSame(10, $response->json('data.total_players'));
        $this->assertSame(1, $response->json('data.rank'));
        $this->assertSame(90, $response->json('data.better_than_percentage'));
    }

    public function test_single_player_percentile_is_zero(): void
    {
        $dc = $this->activeDaily();
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/daily/{$dc->id}/guess", [
            'guess_x_ratio' => 0.5,
            'guess_y_ratio' => 0.5,
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('data.total_players'));
        $this->assertSame(1, $response->json('data.rank'));
        $this->assertSame(0, $response->json('data.better_than_percentage'));
    }

    public function test_weekly_leaderboard_includes_rank_meta(): void
    {
        $dc = $this->activeDaily();
        $this->seedGuess($dc, 40);

        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $this->withToken($token)->postJson("/api/daily/{$dc->id}/guess", [
            'guess_x_ratio' => 0.5,
            'guess_y_ratio' => 0.5,
        ])->assertOk();

        $response = $this->withToken($token)->getJson('/api/daily/leaderboard/weekly');
        $response->assertOk();
        $response->assertJsonStructure(['meta' => [
            'total_players', 'current_user_rank', 'current_user_score',
            'current_user_average', 'better_than_percentage', 'top_users', 'nearby_users',
        ]]);
        $this->assertSame(2, $response->json('meta.total_players'));
        $this->assertSame(1, $response->json('meta.current_user_rank'));
    }
}
