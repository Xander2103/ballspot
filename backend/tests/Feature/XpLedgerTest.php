<?php

namespace Tests\Feature;

use App\Models\Badge;
use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeGuess;
use App\Models\Guess;
use App\Models\League;
use App\Models\LeagueRound;
use App\Models\Sport;
use App\Models\User;
use App\Models\XpEvent;
use App\Services\BadgeService;
use App\Services\PlayerRankService;
use App\Services\XpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class XpLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function xp(): XpService { return app(XpService::class); }
    private function ranks(): PlayerRankService { return app(PlayerRankService::class); }

    private function activeChallenge(): Challenge
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football', 'status' => Sport::STATUS_ACTIVE]);
        return Challenge::create([
            'sport_id' => $sport->id, 'title' => 'C', 'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5,
            'difficulty' => 'easy', 'status' => 'active', 'hidden_image_path' => 'challenges/hidden/x.jpg',
        ]);
    }

    private function todayDaily(): DailyChallenge
    {
        return DailyChallenge::create([
            'challenge_id' => $this->activeChallenge()->id, 'challenge_date' => today()->toDateString(), 'status' => 'active',
        ]);
    }

    // --- XpService ---

    public function test_xp_event_can_be_created(): void
    {
        $user = User::factory()->create();
        $event = $this->xp()->awardXp($user, XpEvent::SOURCE_DAILY_GUESS, 1, 80, 'Daily challenge completed');

        $this->assertNotNull($event);
        $this->assertDatabaseHas('xp_events', ['user_id' => $user->id, 'source_type' => 'daily_guess', 'source_id' => 1, 'amount' => 80]);
        $this->assertSame(80, $this->xp()->getTotalXp($user));
    }

    public function test_duplicate_source_xp_is_not_awarded_twice(): void
    {
        $user = User::factory()->create();
        $this->xp()->awardXp($user, XpEvent::SOURCE_DAILY_GUESS, 5, 80, 'Daily challenge completed');
        $second = $this->xp()->awardXp($user, XpEvent::SOURCE_DAILY_GUESS, 5, 80, 'Daily challenge completed');

        $this->assertNull($second);
        $this->assertSame(1, XpEvent::where('user_id', $user->id)->count());
        $this->assertSame(80, $this->xp()->getTotalXp($user));
    }

    public function test_recent_events_returns_most_recent_first(): void
    {
        $user = User::factory()->create();
        $this->xp()->awardXp($user, XpEvent::SOURCE_ADMIN_ADJUSTMENT, null, 10, 'a');
        $this->xp()->awardXp($user, XpEvent::SOURCE_ADMIN_ADJUSTMENT, null, 20, 'b');

        $events = $this->xp()->getRecentXpEvents($user, 20);
        $this->assertSame(2, $events->count());
        $this->assertSame(20, $events->first()->amount);
    }

    // --- Rank service uses ledger ---

    public function test_rank_uses_xp_ledger_over_lifetime_score(): void
    {
        $user = User::factory()->create();
        // A lifetime score that would otherwise dominate...
        $challenge = $this->activeChallenge();
        DailyChallengeGuess::create([
            'daily_challenge_id' => $this->todayDaily()->id, 'user_id' => $user->id,
            'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5, 'distance' => 0.0, 'score' => 999, 'submitted_at' => now(),
        ]);
        // ...but a ledger event is the source of truth once any event exists.
        $this->xp()->awardXp($user, XpEvent::SOURCE_ADMIN_ADJUSTMENT, null, 3000, 'seed');

        $this->assertSame(3000, $this->ranks()->totalXpForUser($user));
        $this->assertSame('Amateur', $this->ranks()->forUser($user)['name']);
    }

    public function test_rank_falls_back_to_lifetime_score_when_no_events(): void
    {
        $user = User::factory()->create();
        DailyChallengeGuess::create([
            'daily_challenge_id' => $this->todayDaily()->id, 'user_id' => $user->id,
            'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5, 'distance' => 0.0, 'score' => 500, 'submitted_at' => now(),
        ]);

        // No xp_events yet → fallback to lifetime score.
        $this->assertSame(500, $this->ranks()->totalXpForUser($user));
    }

    // --- Guess flow creates XP ---

    public function test_daily_guess_creates_xp_event(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;
        $daily = $this->todayDaily();

        $res = $this->withToken($token)->postJson("/api/daily/{$daily->id}/guess", [
            'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5,
        ]);

        $res->assertOk()->assertJsonStructure(['data', 'rank_progress' => ['xp_gained', 'rank']]);
        $this->assertDatabaseHas('xp_events', ['user_id' => $user->id, 'source_type' => 'daily_guess']);
    }

    public function test_reopening_daily_result_does_not_double_award(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;
        $daily = $this->todayDaily();

        $this->withToken($token)->postJson("/api/daily/{$daily->id}/guess", ['guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5])->assertOk();
        $this->withToken($token)->getJson("/api/daily/{$daily->id}/result")->assertOk();
        $this->withToken($token)->getJson("/api/daily/{$daily->id}/result")->assertOk();

        $this->assertSame(1, XpEvent::where('user_id', $user->id)->where('source_type', 'daily_guess')->count());
    }

    // --- xp-events limit ---

    public function test_xp_events_limit_returns_at_most_the_requested_rows(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        for ($i = 1; $i <= 12; $i++) {
            $this->xp()->awardXp($user, XpEvent::SOURCE_ADMIN_ADJUSTMENT, $i, 10, "event {$i}");
        }

        $res = $this->withToken($token)->getJson('/api/me/xp-events?limit=5');
        $res->assertOk();
        $this->assertCount(5, $res->json('data'));
    }

    public function test_xp_events_limit_is_capped_safely(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        for ($i = 1; $i <= 60; $i++) {
            $this->xp()->awardXp($user, XpEvent::SOURCE_ADMIN_ADJUSTMENT, $i, 10, "event {$i}");
        }

        // Excessive requested limit is clamped to the server cap (50).
        $res = $this->withToken($token)->getJson('/api/me/xp-events?limit=9999');
        $res->assertOk();
        $this->assertLessThanOrEqual(50, count($res->json('data')));
    }

    // --- Badge XP ---

    public function test_badge_unlock_awards_xp_once(): void
    {
        $user  = User::factory()->create();
        $badge = Badge::create(['code' => 'test_rare', 'name' => 'Test Rare', 'description' => 'A rare test badge', 'icon' => '🏅', 'rarity' => 'rare', 'category' => 'test', 'sort_order' => 1]);

        $service = app(BadgeService::class);
        $service->award($user, 'test_rare');
        $service->award($user, 'test_rare'); // second call — already earned

        $this->assertSame(1, XpEvent::where('user_id', $user->id)->where('source_type', 'badge_unlock')->count());
        $this->assertSame(250, $this->xp()->getTotalXp($user)); // rare = 250
    }

    // --- Streak XP ---

    public function test_streak_milestone_awards_xp_once(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;
        $challenge = $this->activeChallenge();

        // Two prior consecutive days already played...
        foreach ([2, 1] as $daysAgo) {
            $dc = DailyChallenge::create(['challenge_id' => $challenge->id, 'challenge_date' => today()->subDays($daysAgo)->toDateString(), 'status' => 'active']);
            DailyChallengeGuess::create([
                'daily_challenge_id' => $dc->id, 'user_id' => $user->id,
                'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5, 'distance' => 0.0, 'score' => 50, 'submitted_at' => now(),
            ]);
        }
        // ...then today's guess makes it a 3-day streak.
        $today = DailyChallenge::create(['challenge_id' => $challenge->id, 'challenge_date' => today()->toDateString(), 'status' => 'active']);
        $this->withToken($token)->postJson("/api/daily/{$today->id}/guess", ['guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5])->assertOk();

        $this->assertDatabaseHas('xp_events', ['user_id' => $user->id, 'source_type' => 'streak_bonus', 'source_id' => 3, 'amount' => 150]);
        $this->assertSame(1, XpEvent::where('user_id', $user->id)->where('source_type', 'streak_bonus')->count());
    }

    // --- Rank-up payload ---

    public function test_rank_up_payload_appears_when_crossing_threshold(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;
        // Seed just below Amateur (2,500).
        $this->xp()->awardXp($user, XpEvent::SOURCE_ADMIN_ADJUSTMENT, null, 2499, 'seed');

        $daily = $this->todayDaily();
        $res = $this->withToken($token)->postJson("/api/daily/{$daily->id}/guess", ['guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5]);

        $res->assertOk()
            ->assertJsonPath('rank_up.from_rank', 'Rookie')
            ->assertJsonPath('rank_up.to_rank', 'Amateur')
            ->assertJsonPath('rank_up.new_level', 2);
    }

    public function test_no_rank_up_when_not_crossing(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;
        $daily = $this->todayDaily();

        $res = $this->withToken($token)->postJson("/api/daily/{$daily->id}/guess", ['guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5]);
        $res->assertOk()->assertJsonPath('rank_up', null);
    }

    // --- XP events endpoint ---

    public function test_xp_events_endpoint_returns_recent_activity(): void
    {
        $user  = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;
        $this->xp()->awardXp($user, XpEvent::SOURCE_DAILY_GUESS, 1, 80, 'Daily challenge completed');
        $this->xp()->awardXp($user, XpEvent::SOURCE_BADGE_UNLOCK, 2, 100, 'Badge unlocked: First Daily');

        $res = $this->withToken($token)->getJson('/api/me/xp-events');

        $res->assertOk()
            ->assertJsonStructure(['data' => [['amount', 'reason', 'source_type', 'created_at']], 'total_xp', 'rank'])
            ->assertJsonPath('total_xp', 180);
        $this->assertCount(2, $res->json('data'));
    }

    // --- Backfill command ---

    public function test_backfill_dry_run_does_not_write(): void
    {
        $user = User::factory()->create();
        DailyChallengeGuess::create([
            'daily_challenge_id' => $this->todayDaily()->id, 'user_id' => $user->id,
            'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5, 'distance' => 0.0, 'score' => 60, 'submitted_at' => now(),
        ]);

        $this->artisan('ballspot:backfill-xp', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('xp_events', 0);
    }

    public function test_backfill_creates_missing_events_without_duplicates(): void
    {
        $user = User::factory()->create();
        $challenge = $this->activeChallenge();

        DailyChallengeGuess::create([
            'daily_challenge_id' => $this->todayDaily()->id, 'user_id' => $user->id,
            'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5, 'distance' => 0.0, 'score' => 60, 'submitted_at' => now(),
        ]);
        $league = League::create(['name' => 'L', 'join_code' => 'ABCDEF', 'owner_user_id' => $user->id, 'sport_id' => $challenge->sport_id, 'duration_days' => 1, 'rounds_per_day' => 1, 'status' => 'active']);
        $round = LeagueRound::create(['league_id' => $league->id, 'challenge_id' => $challenge->id, 'round_number' => 1, 'status' => 'open']);
        Guess::create(['league_round_id' => $round->id, 'user_id' => $user->id, 'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5, 'distance' => 0.0, 'score' => 90, 'submitted_at' => now()]);

        $this->artisan('ballspot:backfill-xp')->assertSuccessful();
        $this->assertDatabaseHas('xp_events', ['user_id' => $user->id, 'source_type' => 'daily_guess', 'amount' => 60]);
        $this->assertDatabaseHas('xp_events', ['user_id' => $user->id, 'source_type' => 'tournament_guess', 'amount' => 90]);
        $this->assertSame(150, $this->xp()->getTotalXp($user));

        // Re-running does not duplicate.
        $this->artisan('ballspot:backfill-xp')->assertSuccessful();
        $this->assertSame(2, XpEvent::where('user_id', $user->id)->count());
    }
}
