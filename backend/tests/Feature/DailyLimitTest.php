<?php
namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\Guess;
use App\Models\League;
use App\Models\LeagueMember;
use App\Models\LeagueRound;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyLimitTest extends TestCase
{
    use RefreshDatabase;

    private function makeSportWithChallenge(): array
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $challenge = Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => 'DailyLimit Challenge',
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/test.jpg',
        ]);
        return [$sport, $challenge];
    }

    private function makeLeagueWithMember(Sport $sport, int $roundsPerDay, int $numRounds = 1): array
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $league = League::create([
            'name'           => 'Test League',
            'join_code'      => strtoupper(substr(md5(uniqid()), 0, 6)),
            'owner_user_id'  => $user->id,
            'sport_id'       => $sport->id,
            'duration_days'  => 7,
            'rounds_per_day' => $roundsPerDay,
            'status'         => 'active',
        ]);

        LeagueMember::create(['league_id' => $league->id, 'user_id' => $user->id, 'joined_at' => now()]);

        return [$user, $token, $league];
    }

    private function createRound(League $league, Challenge $challenge, int $number): LeagueRound
    {
        return LeagueRound::create([
            'league_id'    => $league->id,
            'challenge_id' => $challenge->id,
            'round_number' => $number,
            'status'       => 'open',
        ]);
    }

    private function submitGuess(User $user, LeagueRound $round): Guess
    {
        return Guess::create([
            'league_round_id' => $round->id,
            'user_id'         => $user->id,
            'guess_x_ratio'   => 0.5,
            'guess_y_ratio'   => 0.5,
            'distance'        => 0.0,
            'score'           => 100,
            'submitted_at'    => now(),
        ]);
    }

    public function test_rounds_per_day_1_blocks_second_round_same_day(): void
    {
        [$sport, $challenge] = $this->makeSportWithChallenge();
        [$user, $token, $league] = $this->makeLeagueWithMember($sport, 1, 2);

        $round1 = $this->createRound($league, $challenge, 1);
        $round2 = $this->createRound($league, $challenge, 2);

        // User plays round 1 today
        $this->submitGuess($user, $round1);

        // Now request current-round — should be blocked by daily limit
        $res = $this->withToken($token)->getJson("/api/leagues/{$league->id}/current-round");

        $res->assertOk();
        $res->assertJsonPath('reason', 'daily_limit_reached');
        $res->assertJsonPath('has_current_round', false);
        $res->assertJsonPath('completed', false);
        $res->assertJsonPath('current_round', null);
    }

    public function test_rounds_per_day_3_allows_three_rounds_per_day(): void
    {
        [$sport, $challenge] = $this->makeSportWithChallenge();

        // Need a second and third distinct challenge to avoid duplicate-guess constraint
        $challenge2 = Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => 'DailyLimit Challenge 2',
            'ball_x_ratio'      => 0.6,
            'ball_y_ratio'      => 0.6,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/test2.jpg',
        ]);
        $challenge3 = Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => 'DailyLimit Challenge 3',
            'ball_x_ratio'      => 0.7,
            'ball_y_ratio'      => 0.7,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/test3.jpg',
        ]);

        [$user, $token, $league] = $this->makeLeagueWithMember($sport, 3, 4);

        $round1 = $this->createRound($league, $challenge, 1);
        $round2 = $this->createRound($league, $challenge2, 2);
        $round3 = $this->createRound($league, $challenge3, 3);

        // User plays 3 rounds today
        $this->submitGuess($user, $round1);
        $this->submitGuess($user, $round2);
        $this->submitGuess($user, $round3);

        // Fourth request — should now be blocked by daily limit
        $res = $this->withToken($token)->getJson("/api/leagues/{$league->id}/current-round");

        $res->assertOk();
        $res->assertJsonPath('reason', 'daily_limit_reached');
        $res->assertJsonPath('has_current_round', false);
    }

    public function test_daily_limit_is_per_user_not_global(): void
    {
        [$sport, $challenge] = $this->makeSportWithChallenge();

        // User A is the owner/member
        [$userA, $tokenA, $league] = $this->makeLeagueWithMember($sport, 1, 2);

        // User B joins the same league
        $userB = User::factory()->create();
        LeagueMember::create(['league_id' => $league->id, 'user_id' => $userB->id, 'joined_at' => now()]);

        $round1 = $this->createRound($league, $challenge, 1);

        // User A plays round 1 today — hits the daily limit
        $this->submitGuess($userA, $round1);

        // User A should get daily_limit_reached
        $resA = $this->withToken($tokenA)->getJson("/api/leagues/{$league->id}/current-round");
        $resA->assertOk();
        $resA->assertJsonPath('reason', 'daily_limit_reached');

        // User B has NOT played today — should still see a pending round
        $resB = $this->actingAs($userB, 'sanctum')->getJson("/api/leagues/{$league->id}/current-round");
        $resB->assertOk();
        $resB->assertJsonPath('has_current_round', true);
        $resB->assertJsonPath('reason', 'has_pending_round');
    }

    public function test_duplicate_guess_is_rejected_with_clear_message(): void
    {
        [$sport, $challenge] = $this->makeSportWithChallenge();
        [$user, $token, $league] = $this->makeLeagueWithMember($sport, 3);

        $round = $this->createRound($league, $challenge, 1);

        // Submit first guess via API
        $res = $this->withToken($token)->postJson("/api/rounds/{$round->id}/guess", [
            'guess_x_ratio' => 0.5,
            'guess_y_ratio' => 0.5,
        ]);
        $res->assertSuccessful();

        // Try to guess again — should be rejected
        $res2 = $this->withToken($token)->postJson("/api/rounds/{$round->id}/guess", [
            'guess_x_ratio' => 0.6,
            'guess_y_ratio' => 0.6,
        ]);
        $res2->assertStatus(422);
        // Check there's a message key in the response
        $res2->assertJsonStructure(['message']);
    }

    public function test_result_available_after_guessing(): void
    {
        [$sport, $challenge] = $this->makeSportWithChallenge();
        [$user, $token, $league] = $this->makeLeagueWithMember($sport, 3);

        $round = $this->createRound($league, $challenge, 1);

        // Submit guess
        $this->withToken($token)->postJson("/api/rounds/{$round->id}/guess", [
            'guess_x_ratio' => 0.5,
            'guess_y_ratio' => 0.5,
        ])->assertSuccessful();

        // Result should be available
        $res = $this->withToken($token)->getJson("/api/rounds/{$round->id}/result");
        $res->assertOk();
        $res->assertJsonStructure(['data' => ['score', 'distance', 'ball_x_ratio', 'ball_y_ratio']]);
    }
}
