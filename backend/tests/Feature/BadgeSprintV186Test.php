<?php

namespace Tests\Feature;

use App\Models\Badge;
use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeGuess;
use App\Models\FriendRequest;
use App\Models\Friendship;
use App\Models\League;
use App\Models\Sport;
use App\Models\TournamentFinish;
use App\Models\User;
use App\Services\BadgeService;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BadgeSprintV186Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BadgeSeeder::class);
    }

    private function makeLeague(User $owner): League
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);

        return League::create([
            'name'           => 'Badge League ' . uniqid(),
            'join_code'      => strtoupper(substr(uniqid(), -6)),
            'owner_user_id'  => $owner->id,
            'sport_id'       => $sport->id,
            'duration_days'  => 1,
            'rounds_per_day' => 1,
            'status'         => 'lobby',
        ]);
    }

    private function makeActiveDailyFor(string $date): DailyChallenge
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        $challenge = Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => 'Badge Daily ' . $date,
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/test.jpg',
        ]);

        return DailyChallenge::create([
            'challenge_id'   => $challenge->id,
            'challenge_date' => $date,
            'status'         => 'active',
        ]);
    }

    private function makeDailyGuess(User $user, DailyChallenge $daily, int $score): DailyChallengeGuess
    {
        return DailyChallengeGuess::create([
            'daily_challenge_id' => $daily->id,
            'user_id'            => $user->id,
            'guess_x_ratio'      => 0.5,
            'guess_y_ratio'      => 0.5,
            'distance'           => 0.02,
            'score'              => $score,
            'submitted_at'       => now(),
        ]);
    }

    public function test_seeder_contains_39_badges_including_new_codes(): void
    {
        $this->assertSame(39, Badge::count());
        foreach (['social_starter', 'friendly_five', 'host_starter', 'tournament_regular',
                  'sharp_scorer', 'pack_explorer', 'daily_loyalist',
                  'sharpshooter', 'most_consistent'] as $code) {
            $this->assertDatabaseHas('badges', ['code' => $code]);
        }
    }

    public function test_social_starter_awarded_to_both_parties_on_accept(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $req = FriendRequest::create(['requester_id' => $a->id, 'recipient_id' => $b->id, 'status' => 'pending']);

        $this->actingWithToken($b->createToken('t')->plainTextToken)
            ->postJson("/api/friends/requests/{$req->id}/accept")
            ->assertOk();

        $this->assertTrue($a->fresh()->badges()->where('code', 'social_starter')->exists());
        $this->assertTrue($b->fresh()->badges()->where('code', 'social_starter')->exists());
    }

    public function test_friendly_five_awarded_at_five_friends(): void
    {
        $user = User::factory()->create();
        foreach (User::factory()->count(5)->create() as $friend) {
            Friendship::create(['user_id' => $user->id, 'friend_id' => $friend->id]);
            Friendship::create(['user_id' => $friend->id, 'friend_id' => $user->id]);
        }

        app(BadgeService::class)->evaluateFriendAccepted($user->fresh());

        $this->assertTrue($user->badges()->where('code', 'friendly_five')->exists());
        $this->assertTrue($user->badges()->where('code', 'social_starter')->exists());
    }

    public function test_friendly_five_not_awarded_below_five_friends(): void
    {
        $user = User::factory()->create();
        $friend = User::factory()->create();
        Friendship::create(['user_id' => $user->id, 'friend_id' => $friend->id]);
        Friendship::create(['user_id' => $friend->id, 'friend_id' => $user->id]);

        app(BadgeService::class)->evaluateFriendAccepted($user->fresh());

        $this->assertFalse($user->badges()->where('code', 'friendly_five')->exists());
    }

    public function test_host_starter_awarded_on_tournament_creation(): void
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        Challenge::create([
            'sport_id' => $sport->id, 'title' => 'Seed', 'hidden_image_path' => 'x.jpg',
            'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5, 'difficulty' => 'easy', 'status' => 'active',
        ]);

        $user = User::factory()->create();

        $this->actingWithToken($user->createToken('t')->plainTextToken)
            ->postJson('/api/leagues', ['name' => 'Badge Cup', 'duration_days' => 7, 'rounds_per_day' => 1])
            ->assertStatus(201);

        $this->assertTrue($user->fresh()->badges()->where('code', 'host_starter')->exists());
    }

    public function test_tournament_regular_awarded_at_five_finishes(): void
    {
        $user = User::factory()->create();
        $lastLeague = null;
        for ($i = 0; $i < 5; $i++) {
            $lastLeague = $this->makeLeague($user);
            TournamentFinish::create([
                'league_id'   => $lastLeague->id,
                'user_id'     => $user->id,
                'placement'   => 4, // off the podium — isolates the count badge
                'total_score' => 100,
            ]);
        }

        app(BadgeService::class)->evaluateTournamentFinish($user, $lastLeague, 4, 6);

        $this->assertTrue($user->badges()->where('code', 'tournament_regular')->exists());
    }

    public function test_sharp_scorer_awarded_after_ten_90_plus_guesses(): void
    {
        $user = User::factory()->create();

        // Nine historical 90+ daily guesses...
        for ($i = 1; $i <= 9; $i++) {
            $daily = $this->makeActiveDailyFor(now()->subDays($i)->toDateString());
            $this->makeDailyGuess($user, $daily, 93);
        }

        // ...then the tenth arrives through the normal evaluation path.
        $daily = $this->makeActiveDailyFor(now()->toDateString());
        $guess = $this->makeDailyGuess($user, $daily, 91);

        app(BadgeService::class)->evaluateDailyGuess($user, $guess, $daily);

        $this->assertTrue($user->badges()->where('code', 'sharp_scorer')->exists());
    }

    public function test_daily_loyalist_awarded_at_fourteen_dailies(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 13; $i++) {
            $daily = $this->makeActiveDailyFor(now()->subDays($i)->toDateString());
            $this->makeDailyGuess($user, $daily, 50);
        }

        $daily = $this->makeActiveDailyFor(now()->toDateString());
        $guess = $this->makeDailyGuess($user, $daily, 50);

        app(BadgeService::class)->evaluateDailyGuess($user, $guess, $daily);

        $this->assertTrue($user->badges()->where('code', 'daily_loyalist')->exists());
    }

    public function test_backfill_awards_qualifying_historical_users(): void
    {
        $user = User::factory()->create();
        $friend = User::factory()->create();
        Friendship::create(['user_id' => $user->id, 'friend_id' => $friend->id]);
        Friendship::create(['user_id' => $friend->id, 'friend_id' => $user->id]);
        $this->makeLeague($user); // owns a tournament -> host_starter

        $this->artisan('ballspot:backfill-sprint-badges')->assertSuccessful();

        $this->assertTrue($user->fresh()->badges()->where('code', 'social_starter')->exists());
        $this->assertTrue($user->fresh()->badges()->where('code', 'host_starter')->exists());
        // The friend gets social_starter too; nobody gets unearned badges.
        $this->assertTrue($friend->fresh()->badges()->where('code', 'social_starter')->exists());
        $this->assertFalse($user->fresh()->badges()->where('code', 'friendly_five')->exists());
    }

    public function test_backfill_awards_v188_badges_from_history(): void
    {
        $user = User::factory()->create();

        \App\Models\XpEvent::create([
            'user_id'     => $user->id,
            'source_type' => 'test_grant',
            'source_id'   => $user->id,
            'amount'      => 100000, // Ball Master (level 6)
            'reason'      => 'test',
        ]);
        for ($i = 0; $i < 3; $i++) {
            $league = $this->makeLeague($user);
            $league->update(['status' => 'completed']);
            \App\Models\TournamentFinish::create([
                'league_id'   => $league->id,
                'user_id'     => $user->id,
                'placement'   => 1,
                'total_score' => 100,
            ]);
        }

        $this->artisan('ballspot:backfill-sprint-badges')->assertSuccessful();

        foreach (['rising_star', 'golden_touch', 'legend_status', 'tournament_beast'] as $code) {
            $this->assertTrue($user->fresh()->badges()->where('code', $code)->exists(), $code);
        }
    }

    public function test_backfill_skips_anonymized_users_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $ghost = User::factory()->create();
        Friendship::create(['user_id' => $user->id, 'friend_id' => $ghost->id]);
        $ghost->forceFill(['anonymized_at' => now()])->save();

        $this->artisan('ballspot:backfill-sprint-badges')->assertSuccessful();
        $this->artisan('ballspot:backfill-sprint-badges')->assertSuccessful();

        $this->assertSame(1, $user->badges()->where('code', 'social_starter')->count());
        $this->assertFalse($ghost->fresh()->badges()->where('code', 'social_starter')->exists());
    }

    public function test_no_duplicate_awards(): void
    {
        $user = User::factory()->create();
        $friend = User::factory()->create();
        Friendship::create(['user_id' => $user->id, 'friend_id' => $friend->id]);
        Friendship::create(['user_id' => $friend->id, 'friend_id' => $user->id]);

        $svc = app(BadgeService::class);
        $svc->evaluateFriendAccepted($user);
        $svc->evaluateFriendAccepted($user);

        $this->assertSame(1, $user->badges()->where('code', 'social_starter')->count());
    }
}
