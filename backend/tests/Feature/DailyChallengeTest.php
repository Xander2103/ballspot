<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyChallengeTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(): array
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        return [$user, $token];
    }

    private function createActiveChallenge(): Challenge
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        return Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => 'Test Challenge',
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/test.jpg',
        ]);
    }

    private function createDailyChallenge(Challenge $challenge, string $date = null, string $status = 'active'): DailyChallenge
    {
        return DailyChallenge::create([
            'challenge_id'   => $challenge->id,
            'challenge_date' => $date ?? today()->toDateString(),
            'status'         => $status,
        ]);
    }

    public function test_today_endpoint_requires_auth(): void
    {
        $response = $this->getJson('/api/daily/today');
        $response->assertUnauthorized();
    }

    public function test_today_returns_no_daily_when_none_exists(): void
    {
        [$user, $token] = $this->createUser();
        $response = $this->withToken($token)->getJson('/api/daily/today');
        $response->assertOk();
        $response->assertJsonPath('has_daily', false);
        $response->assertJsonPath('reason', 'no_daily_challenge');
    }

    public function test_today_does_not_expose_ball_position_before_guess(): void
    {
        [$user, $token] = $this->createUser();
        $challenge = $this->createActiveChallenge();
        $this->createDailyChallenge($challenge);

        $response = $this->withToken($token)->getJson('/api/daily/today');
        $response->assertOk();
        $response->assertJsonPath('has_daily', true);

        // SECURITY: ball position must not be in the response
        $response->assertJsonMissingPath('daily_challenge.challenge.ball_x_ratio');
        $response->assertJsonMissingPath('daily_challenge.challenge.ball_y_ratio');
        $response->assertJsonMissingPath('daily_challenge.challenge.reveal_image_url');
    }

    public function test_user_can_submit_daily_guess_and_receive_score(): void
    {
        [$user, $token] = $this->createUser();
        $challenge = $this->createActiveChallenge();
        $dc = $this->createDailyChallenge($challenge);

        $response = $this->withToken($token)->postJson("/api/daily/{$dc->id}/guess", [
            'guess_x_ratio' => 0.5,
            'guess_y_ratio' => 0.5,
        ]);
        $response->assertOk();
        $response->assertJsonStructure(['data' => ['id', 'score', 'distance', 'guess_x_ratio', 'guess_y_ratio', 'ball_x_ratio', 'ball_y_ratio']]);
    }

    public function test_duplicate_daily_guess_is_rejected(): void
    {
        [$user, $token] = $this->createUser();
        $challenge = $this->createActiveChallenge();
        $dc = $this->createDailyChallenge($challenge);

        $this->withToken($token)->postJson("/api/daily/{$dc->id}/guess", [
            'guess_x_ratio' => 0.5,
            'guess_y_ratio' => 0.5,
        ])->assertOk();

        $response = $this->withToken($token)->postJson("/api/daily/{$dc->id}/guess", [
            'guess_x_ratio' => 0.6,
            'guess_y_ratio' => 0.6,
        ]);
        $response->assertStatus(422);
        $response->assertJsonStructure(['message']);
    }

    public function test_result_exposes_ball_position_after_guess(): void
    {
        [$user, $token] = $this->createUser();
        $challenge = $this->createActiveChallenge();
        $dc = $this->createDailyChallenge($challenge);

        $this->withToken($token)->postJson("/api/daily/{$dc->id}/guess", [
            'guess_x_ratio' => 0.4,
            'guess_y_ratio' => 0.4,
        ])->assertOk();

        $response = $this->withToken($token)->getJson("/api/daily/{$dc->id}/result");
        $response->assertOk();
        $response->assertJsonStructure(['data' => ['ball_x_ratio', 'ball_y_ratio', 'score', 'distance']]);
    }

    public function test_result_returns_404_if_not_guessed(): void
    {
        [$user, $token] = $this->createUser();
        $challenge = $this->createActiveChallenge();
        $dc = $this->createDailyChallenge($challenge);

        $response = $this->withToken($token)->getJson("/api/daily/{$dc->id}/result");
        $response->assertStatus(404);
    }

    public function test_weekly_leaderboard_includes_scores(): void
    {
        [$user, $token] = $this->createUser();
        $challenge = $this->createActiveChallenge();
        $dc = $this->createDailyChallenge($challenge);

        $this->withToken($token)->postJson("/api/daily/{$dc->id}/guess", [
            'guess_x_ratio' => 0.5,
            'guess_y_ratio' => 0.5,
        ])->assertOk();

        $response = $this->withToken($token)->getJson('/api/daily/leaderboard/weekly');
        $response->assertOk();
        $response->assertJsonStructure(['data', 'week_start', 'week_end']);
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_daily_stats_returns_expected_fields(): void
    {
        [$user, $token] = $this->createUser();

        $response = $this->withToken($token)->getJson('/api/daily/stats');
        $response->assertOk();
        $response->assertJsonStructure([
            'current_streak', 'best_streak', 'total_played',
            'average_score', 'best_score', 'weekly_rank',
        ]);
    }

    public function test_today_returns_safe_response_when_challenge_is_archived(): void
    {
        [$user, $token] = $this->createUser();
        $challenge = $this->createActiveChallenge();
        $this->createDailyChallenge($challenge);

        // Archive the linked challenge after scheduling
        $challenge->update(['status' => 'archived']);

        $response = $this->withToken($token)->getJson('/api/daily/today');
        $response->assertOk();
        $response->assertJsonPath('has_daily', false);
    }
}
