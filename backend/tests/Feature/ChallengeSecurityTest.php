<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\League;
use App\Models\LeagueMember;
use App\Models\LeagueRound;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChallengeSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function createFixture(): array
    {
        $sport = Sport::create(['name' => 'Football', 'slug' => 'football']);
        $challenge = Challenge::create([
            'sport_id'            => $sport->id,
            'title'               => 'Security Test',
            'hidden_image_path'   => 'challenges/hidden/test.jpg',
            'original_image_path' => 'challenges/original/test.jpg',
            'ball_x_ratio'        => 0.6,
            'ball_y_ratio'        => 0.7,
            'difficulty'          => 'easy',
            'status'              => 'active',
        ]);
        $user   = User::factory()->create();
        $token  = $user->createToken('test')->plainTextToken;
        $league = League::create([
            'name'           => 'Security League',
            'join_code'      => 'SECTEST',
            'owner_user_id'  => $user->id,
            'sport_id'       => $sport->id,
            'duration_days'  => 1,
            'rounds_per_day' => 1,
            'status'         => 'active',
        ]);
        LeagueMember::create(['league_id' => $league->id, 'user_id' => $user->id, 'joined_at' => now()]);
        $round = LeagueRound::create([
            'league_id'    => $league->id,
            'challenge_id' => $challenge->id,
            'round_number' => 1,
            'status'       => 'open',
        ]);
        return [$user, $league, $round, ['Authorization' => "Bearer $token"]];
    }

    public function test_current_round_does_not_expose_ball_position(): void
    {
        [, $league, , $headers] = $this->createFixture();

        $response = $this->getJson("/api/leagues/{$league->id}/current-round", $headers);
        $response->assertOk();

        $challenge = $response->json('current_round.challenge');
        $this->assertIsArray($challenge);
        $this->assertArrayNotHasKey('ball_x_ratio',    $challenge, 'ball_x_ratio must not be in current-round response');
        $this->assertArrayNotHasKey('ball_y_ratio',    $challenge, 'ball_y_ratio must not be in current-round response');
        $this->assertArrayNotHasKey('reveal_image_url', $challenge, 'reveal_image_url must not be in current-round response');
    }

    public function test_result_exposes_ball_position_after_guessing(): void
    {
        [, , $round, $headers] = $this->createFixture();

        $this->postJson("/api/rounds/{$round->id}/guess", [
            'guess_x_ratio' => 0.5,
            'guess_y_ratio' => 0.5,
        ], $headers)->assertStatus(201);

        $this->getJson("/api/rounds/{$round->id}/result", $headers)
            ->assertOk()
            ->assertJsonStructure(['data' => ['ball_x_ratio', 'ball_y_ratio', 'reveal_image_url']]);
    }

    public function test_result_includes_reveal_image_url_when_original_image_exists(): void
    {
        [, , $round, $headers] = $this->createFixture();

        $this->postJson("/api/rounds/{$round->id}/guess", [
            'guess_x_ratio' => 0.5,
            'guess_y_ratio' => 0.5,
        ], $headers);

        $response = $this->getJson("/api/rounds/{$round->id}/result", $headers)->assertOk();
        $this->assertNotNull(
            $response->json('data.reveal_image_url'),
            'reveal_image_url should be set when original_image_path exists'
        );
    }

    public function test_result_reveal_image_url_is_null_when_no_original_image(): void
    {
        $sport = Sport::create(['name' => 'Football', 'slug' => 'football']);
        $challenge = Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => 'No Reveal',
            'hidden_image_path' => 'challenges/hidden/no-reveal.jpg',
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
        ]);
        $user    = User::factory()->create();
        $token   = $user->createToken('test')->plainTextToken;
        $league  = League::create([
            'name'           => 'NR League',
            'join_code'      => 'NOREV1',
            'owner_user_id'  => $user->id,
            'sport_id'       => $sport->id,
            'duration_days'  => 1,
            'rounds_per_day' => 1,
            'status'         => 'active',
        ]);
        LeagueMember::create(['league_id' => $league->id, 'user_id' => $user->id, 'joined_at' => now()]);
        $round   = LeagueRound::create([
            'league_id'    => $league->id,
            'challenge_id' => $challenge->id,
            'round_number' => 1,
            'status'       => 'open',
        ]);
        $headers = ['Authorization' => "Bearer $token"];

        $this->postJson("/api/rounds/{$round->id}/guess", ['guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5], $headers);

        $response = $this->getJson("/api/rounds/{$round->id}/result", $headers)->assertOk();
        $this->assertNull(
            $response->json('data.reveal_image_url'),
            'reveal_image_url should be null when no original_image_path'
        );
    }
}
