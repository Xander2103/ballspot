<?php

namespace Tests\Feature;

use App\Models\Badge;
use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeGuess;
use App\Models\Sport;
use App\Models\User;
use App\Models\XpEvent;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the v1.7.4 canonical badge taxonomy (Perfect Picker, Almost Perfect,
 * Daily Debut, streaks, multi-sport). Legacy badge behaviour is covered by
 * BadgeTest; these tests assert the NEW codes specifically.
 */
class BadgeExpansionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BadgeSeeder::class);
    }

    private function activeDaily(float $ballX = 0.5, float $ballY = 0.5, string $slug = 'football'): DailyChallenge
    {
        $sport = Sport::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $challenge = Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => 'Badge Challenge',
            'ball_x_ratio'      => $ballX,
            'ball_y_ratio'      => $ballY,
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

    private function play(User $user, DailyChallenge $dc, float $x, float $y)
    {
        $token = $user->createToken('t')->plainTextToken;
        return $this->withToken($token)->postJson("/api/daily/{$dc->id}/guess", [
            'guess_x_ratio' => $x, 'guess_y_ratio' => $y,
        ]);
    }

    public function test_perfect_score_awards_perfect_picker(): void
    {
        $dc = $this->activeDaily();
        $user = User::factory()->create();

        $res = $this->play($user, $dc, 0.5, 0.5); // distance 0 → score 100
        $res->assertOk();

        $codes = collect($res->json('data.new_badges'))->pluck('code');
        $this->assertTrue($codes->contains('perfect_picker'));
        $this->assertTrue($codes->contains('almost_perfect')); // 100 is also >= 95
        $this->assertDatabaseHas('user_badges', [
            'user_id'  => $user->id,
            'badge_id' => Badge::where('code', 'perfect_picker')->first()->id,
        ]);
    }

    public function test_perfect_picker_is_not_awarded_twice_on_result_reopen(): void
    {
        $dc = $this->activeDaily();
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson("/api/daily/{$dc->id}/guess", ['guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5])->assertOk();

        // Reopening the result must not re-award or return new_badges again.
        $reopen = $this->withToken($token)->getJson("/api/daily/{$dc->id}/result");
        $reopen->assertOk();
        $this->assertNull($reopen->json('data.new_badges'));

        $badgeId = Badge::where('code', 'perfect_picker')->first()->id;
        $this->assertSame(1, DailyChallengeGuess::where('user_id', $user->id)->count());
        $this->assertSame(1, \DB::table('user_badges')->where('user_id', $user->id)->where('badge_id', $badgeId)->count());
    }

    public function test_almost_perfect_awarded_without_perfect_picker(): void
    {
        $dc = $this->activeDaily();
        $user = User::factory()->create();

        // Offset by 0.012 → distance 0.012 → score 97 (>=95 but < 100).
        $res = $this->play($user, $dc, 0.512, 0.5);
        $res->assertOk();
        $this->assertSame(97, $res->json('data.score'));

        $codes = collect($res->json('data.new_badges'))->pluck('code');
        $this->assertTrue($codes->contains('almost_perfect'));
        $this->assertFalse($codes->contains('perfect_picker'));
    }

    public function test_first_daily_win_awarded_on_first_daily(): void
    {
        $dc = $this->activeDaily();
        $user = User::factory()->create();

        $res = $this->play($user, $dc, 0.9, 0.9); // any completion
        $res->assertOk();

        $codes = collect($res->json('data.new_badges'))->pluck('code');
        $this->assertTrue($codes->contains('first_daily_win'));
    }

    public function test_badge_xp_is_written_to_the_ledger(): void
    {
        $dc = $this->activeDaily();
        $user = User::factory()->create();

        $this->play($user, $dc, 0.5, 0.5)->assertOk();

        // Legendary perfect_picker grants 1000 XP via the ledger.
        $perfectPicker = Badge::where('code', 'perfect_picker')->first();
        $this->assertDatabaseHas('xp_events', [
            'user_id'     => $user->id,
            'source_type' => XpEvent::SOURCE_BADGE_UNLOCK,
            'source_id'   => $perfectPicker->id,
            'amount'      => 1000,
        ]);
    }

    public function test_streak_3_and_7_badges_awarded(): void
    {
        $user = User::factory()->create();
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $challenge = Challenge::create([
            'sport_id' => $sport->id, 'title' => 'C', 'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5,
            'difficulty' => 'easy', 'status' => 'active', 'hidden_image_path' => 'x.jpg',
        ]);

        // Six prior consecutive days already played...
        foreach (range(6, 1) as $daysAgo) {
            $dc = DailyChallenge::create(['challenge_id' => $challenge->id, 'challenge_date' => today()->subDays($daysAgo)->toDateString(), 'status' => 'active']);
            DailyChallengeGuess::create([
                'daily_challenge_id' => $dc->id, 'user_id' => $user->id,
                'guess_x_ratio' => 0.9, 'guess_y_ratio' => 0.9, 'distance' => 0.5, 'score' => 10, 'submitted_at' => now(),
            ]);
        }
        // ...today's guess makes a 7-day streak.
        $today = DailyChallenge::create(['challenge_id' => $challenge->id, 'challenge_date' => today()->toDateString(), 'status' => 'active']);
        $res = $this->play($user, $today, 0.9, 0.9);
        $res->assertOk();

        $codes = collect($res->json('data.new_badges'))->pluck('code');
        $this->assertTrue($codes->contains('streak_3'));
        $this->assertTrue($codes->contains('streak_7'));
    }

    public function test_multi_sport_starter_awarded_on_first_non_football_challenge(): void
    {
        $dc = $this->activeDaily(0.5, 0.5, 'tennis');
        $user = User::factory()->create();

        $res = $this->play($user, $dc, 0.9, 0.9);
        $res->assertOk();

        $codes = collect($res->json('data.new_badges'))->pluck('code');
        $this->assertTrue($codes->contains('multi_sport_starter'));
    }
}
