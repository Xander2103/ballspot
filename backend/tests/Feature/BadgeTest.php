<?php

namespace Tests\Feature;

use App\Models\Badge;
use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeGuess;
use App\Models\Sport;
use App\Models\User;
use App\Services\BadgeService;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BadgeSeeder::class);
    }

    private function activeDaily(): DailyChallenge
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $challenge = Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => 'Badge Challenge',
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

    public function test_default_badges_are_seeded(): void
    {
        $this->assertDatabaseHas('badges', ['code' => 'first_daily']);
        $this->assertDatabaseHas('badges', ['code' => 'tournament_winner']);
        $this->assertDatabaseHas('badges', ['code' => 'perfect_picker']);
        $this->assertSame(37, Badge::count());
    }

    public function test_v188_badges_are_seeded(): void
    {
        foreach (['rising_star', 'golden_touch', 'legend_status', 'tournament_beast'] as $code) {
            $this->assertDatabaseHas('badges', ['code' => $code]);
        }
    }

    public function test_badge_is_awarded_only_once(): void
    {
        $user = User::factory()->create();
        $service = app(BadgeService::class);

        $first  = $service->award($user, 'first_daily');
        $second = $service->award($user, 'first_daily');

        $this->assertNotNull($first);
        $this->assertNull($second); // idempotent
        $this->assertSame(1, $user->badges()->count());
    }

    public function test_first_daily_badge_earned_on_first_guess(): void
    {
        $dc = $this->activeDaily();
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/daily/{$dc->id}/guess", [
            'guess_x_ratio' => 0.5,
            'guess_y_ratio' => 0.5,
        ]);

        $response->assertOk();
        $codes = collect($response->json('data.new_badges'))->pluck('code');
        $this->assertTrue($codes->contains('first_daily'));
        $this->assertTrue($codes->contains('first_guess'));
        $this->assertTrue($codes->contains('football_rookie'));

        $this->assertDatabaseHas('user_badges', [
            'user_id'  => $user->id,
            'badge_id' => Badge::where('code', 'first_daily')->first()->id,
        ]);
    }

    public function test_top_10_percent_badge_awarded_when_applicable(): void
    {
        $dc = $this->activeDaily();

        // 10 other players who all score poorly (far guesses).
        for ($i = 0; $i < 10; $i++) {
            DailyChallengeGuess::create([
                'daily_challenge_id' => $dc->id,
                'user_id'            => User::factory()->create()->id,
                'guess_x_ratio'      => 0.9,
                'guess_y_ratio'      => 0.9,
                'distance'           => 0.5,
                'score'              => 5,
                'submitted_at'       => now(),
            ]);
        }

        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        // Perfect guess → rank 1 of 11 → top 10%.
        $response = $this->withToken($token)->postJson("/api/daily/{$dc->id}/guess", [
            'guess_x_ratio' => 0.5,
            'guess_y_ratio' => 0.5,
        ]);

        $response->assertOk();
        $codes = collect($response->json('data.new_badges'))->pluck('code');
        $this->assertTrue($codes->contains('top_10_percent_daily'));
        $this->assertTrue($codes->contains('daily_champion'));
        $this->assertTrue($codes->contains('perfect_guess'));
    }

    public function test_me_badges_returns_earned_and_locked(): void
    {
        $dc = $this->activeDaily();
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson("/api/daily/{$dc->id}/guess", [
            'guess_x_ratio' => 0.5,
            'guess_y_ratio' => 0.5,
        ])->assertOk();

        $response = $this->withToken($token)->getJson('/api/me/badges');
        $response->assertOk();
        $response->assertJsonStructure([
            'earned_count', 'total_count',
            'badges' => [['id', 'code', 'name', 'icon', 'rarity', 'category', 'earned', 'earned_at']],
        ]);

        $this->assertSame(37, $response->json('total_count'));
        $this->assertGreaterThanOrEqual(1, $response->json('earned_count'));

        // At least one badge earned and at least one still locked.
        $badges = collect($response->json('badges'));
        $this->assertTrue($badges->firstWhere('code', 'first_daily')['earned']);
        $this->assertFalse($badges->firstWhere('code', 'thirty_day_streak')['earned']);
    }

    public function test_badges_index_lists_catalogue(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/badges');
        $response->assertOk();
        $this->assertCount(37, $response->json('data'));
    }
}
