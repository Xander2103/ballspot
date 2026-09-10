<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\DailyChallenge;
use App\Models\League;
use App\Models\LeagueMember;
use App\Models\LeagueRound;
use App\Models\Sport;
use App\Models\User;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The guess marker in the app follows the CHALLENGE's sport (a tennis photo
 * inside a "Mixed Sports" pack shows a tennis ball, not a football). Every
 * challenge payload — pack, daily, tournament round — and every result
 * payload therefore carries the challenge's own `sport` with slug + object
 * name, additively (older clients ignore it).
 */
class ChallengeSportPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BadgeSeeder::class);
    }

    private function auth(): array
    {
        $user = User::factory()->create();

        return [$user, $user->createToken('test')->plainTextToken];
    }

    private function sport(string $slug, string $object = 'ball'): Sport
    {
        return Sport::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug), 'emoji' => '🏅', 'object_name' => $object, 'status' => 'active']);
    }

    private function challenge(Sport $sport, string $title): Challenge
    {
        return Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => $title,
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/test.jpg',
        ]);
    }

    public function test_pack_challenge_payload_uses_the_challenges_own_sport_not_the_packs(): void
    {
        [, $token] = $this->auth();
        $tennis = $this->sport('tennis');
        $hockey = $this->sport('hockey', 'puck');

        // A "Mixed Sports" pack: no pack-level sport at all.
        $pack = ChallengePack::create([
            'name' => 'Mixed Sports', 'slug' => 'mixed', 'status' => ChallengePack::STATUS_ACTIVE,
            'visibility' => ChallengePack::VISIBILITY_PUBLIC, 'sport_id' => null,
        ]);
        $pack->challenges()->attach($this->challenge($tennis, 'Serve')->id, ['sort_order' => 1]);
        $pack->challenges()->attach($this->challenge($hockey, 'Slapshot')->id, ['sort_order' => 2]);

        $start = $this->withToken($token)->postJson('/api/packs/mixed/start')->assertOk();
        $start->assertJsonPath('challenge.sport.slug', 'tennis')
              ->assertJsonPath('challenge.sport.object_name', 'ball');

        // The guess result and the next challenge both carry their own sport.
        $res = $this->withToken($token)->postJson('/api/pack-attempts/' . $start->json('attempt.id') . '/guess', [
            'challenge_id' => $start->json('challenge.id'), 'guessed_x' => 0.5, 'guessed_y' => 0.5,
        ])->assertOk();
        $res->assertJsonPath('result.sport.slug', 'tennis')
            ->assertJsonPath('next_challenge.sport.slug', 'hockey')
            ->assertJsonPath('next_challenge.sport.object_name', 'puck');

        $this->withToken($token)->getJson('/api/packs/mixed/attempt')->assertOk()
            ->assertJsonPath('challenge.sport.slug', 'hockey');
    }

    public function test_daily_challenge_payload_and_result_carry_the_sport(): void
    {
        [, $token] = $this->auth();
        $golf      = $this->sport('golf');
        $challenge = $this->challenge($golf, 'Putt');
        $daily     = DailyChallenge::create(['challenge_id' => $challenge->id, 'challenge_date' => today()->toDateString(), 'status' => 'active']);

        $this->withToken($token)->getJson('/api/daily/today?sport=golf')->assertOk()
            ->assertJsonPath('daily_challenge.challenge.sport.slug', 'golf')
            ->assertJsonPath('daily_challenge.challenge.sport.object_name', 'ball');

        $this->withToken($token)->postJson("/api/daily/{$daily->id}/guess", ['guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5])
            ->assertSuccessful()
            ->assertJsonPath('data.sport.slug', 'golf');

        $this->withToken($token)->getJson("/api/daily/{$daily->id}/result")->assertOk()
            ->assertJsonPath('data.sport.slug', 'golf')
            ->assertJsonPath('data.sport.object_name', 'ball');
    }

    public function test_tournament_round_payload_and_result_carry_the_sport(): void
    {
        [$user, $token] = $this->auth();
        $basketball = $this->sport('basketball');
        $challenge  = $this->challenge($basketball, 'Dunk');

        $league = League::create([
            'name' => 'Hoops', 'join_code' => 'HOOPS1', 'owner_user_id' => $user->id,
            'sport_id' => $basketball->id, 'duration_days' => 1, 'rounds_per_day' => 1, 'status' => 'active',
        ]);
        LeagueMember::create(['league_id' => $league->id, 'user_id' => $user->id, 'joined_at' => now()]);
        $round = LeagueRound::create(['league_id' => $league->id, 'challenge_id' => $challenge->id, 'round_number' => 1, 'status' => 'open']);

        $this->withToken($token)->getJson("/api/leagues/{$league->id}/current-round")->assertOk()
            ->assertJsonPath('current_round.challenge.sport.slug', 'basketball')
            ->assertJsonPath('current_round.challenge.sport.object_name', 'ball');

        $this->withToken($token)->postJson("/api/rounds/{$round->id}/guess", ['guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5])
            ->assertStatus(201);

        $res = $this->withToken($token)->getJson("/api/rounds/{$round->id}/result")->assertOk();
        $this->assertSame('basketball', data_get($res->json(), 'data.sport.slug') ?? data_get($res->json(), 'sport.slug'));
    }
}
