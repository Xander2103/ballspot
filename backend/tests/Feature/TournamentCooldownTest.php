<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeGuess;
use App\Models\GameplaySetting;
use App\Models\Guess;
use App\Models\League;
use App\Models\LeagueMember;
use App\Models\LeagueRound;
use App\Models\PackAttempt;
use App\Models\PackAttemptGuess;
use App\Models\Sport;
use App\Models\User;
use App\Services\LeagueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v1.9.0 tournament rules:
 *  - fixed durations only (7 / 14 / 30 days, one photo per day)
 *  - admin-configurable soft challenge cooldown (default 90 days)
 *  - selection prefers photos no member guessed within the cooldown window,
 *    falls back to seen (never Daily-used) photos, never repeats in one
 *    tournament, and 422s only when the whole eligible pool is too small.
 */
class TournamentCooldownTest extends TestCase
{
    use RefreshDatabase;

    private function sport(): Sport
    {
        return Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
    }

    private function challenge(Sport $sport, string $title, array $overrides = []): Challenge
    {
        return Challenge::create(array_merge([
            'sport_id'          => $sport->id,
            'title'             => $title,
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => "challenges/hidden/{$title}.jpg",
        ], $overrides));
    }

    /** @return int[] challenge ids */
    private function challenges(Sport $sport, int $count, string $prefix = 'C'): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->challenge($sport, "{$prefix}{$i}")->id;
        }
        return $ids;
    }

    private function auth(?User $user = null): array
    {
        $user ??= User::factory()->create();
        return [$user, ['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken]];
    }

    private function lobby(User $owner, int $days = 7, array $members = []): League
    {
        $league = League::create([
            'name' => 'Cup', 'join_code' => strtoupper(substr(md5((string) mt_rand()), 0, 6)),
            'owner_user_id' => $owner->id, 'sport_id' => $this->sport()->id,
            'duration_days' => $days, 'rounds_per_day' => 1, 'status' => 'lobby',
        ]);
        foreach (array_merge([$owner], $members) as $u) {
            LeagueMember::create(['league_id' => $league->id, 'user_id' => $u->id, 'joined_at' => now()]);
        }
        return $league;
    }

    private function roundIds(League $league): array
    {
        return LeagueRound::where('league_id', $league->id)->pluck('challenge_id')->map(fn ($i) => (int) $i)->all();
    }

    /** Record a daily guess by $user on $challengeId at $when (via a daily_challenges row). */
    private function dailyGuess(User $user, int $challengeId, \DateTimeInterface $when): void
    {
        $daily = DailyChallenge::create([
            'challenge_id' => $challengeId, 'challenge_date' => $when->format('Y-m-d'), 'status' => 'archived',
        ]);
        DailyChallengeGuess::create([
            'daily_challenge_id' => $daily->id, 'user_id' => $user->id,
            'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5, 'distance' => 0, 'score' => 100, 'submitted_at' => $when,
        ]);
    }

    /** Record a tournament guess by $user on $challengeId in an old (completed) league. */
    private function tournamentGuess(User $user, int $challengeId, \DateTimeInterface $when): void
    {
        $old = League::create([
            'name' => 'Old', 'join_code' => strtoupper(substr(md5((string) mt_rand()), 0, 6)),
            'owner_user_id' => $user->id, 'sport_id' => $this->sport()->id,
            'duration_days' => 3, 'rounds_per_day' => 1, 'status' => 'completed',
        ]);
        $round = LeagueRound::create(['league_id' => $old->id, 'challenge_id' => $challengeId, 'round_number' => 1, 'status' => 'closed']);
        Guess::create([
            'league_round_id' => $round->id, 'user_id' => $user->id,
            'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5, 'distance' => 0, 'score' => 100, 'submitted_at' => $when,
        ]);
    }

    /** Record a pack guess by $user on $challengeId at $when. */
    private function packGuess(User $user, int $challengeId, \DateTimeInterface $when): void
    {
        $pack = ChallengePack::create(['name' => 'P', 'slug' => 'p-' . mt_rand(), 'status' => 'active']);
        $attempt = PackAttempt::create(['user_id' => $user->id, 'challenge_pack_id' => $pack->id, 'status' => 'completed']);
        $guess = PackAttemptGuess::create(['pack_attempt_id' => $attempt->id, 'challenge_id' => $challengeId, 'score' => 100]);
        $guess->forceFill(['created_at' => $when, 'updated_at' => $when])->saveQuietly();
    }

    // ------------------------------------------------------------------
    // Fixed durations
    // ------------------------------------------------------------------

    public function test_duration_7_creates_7_unique_rounds_when_7_eligible_exist(): void
    {
        $this->challenges($this->sport(), 7);
        [$user, $headers] = $this->auth();

        $id = $this->postJson('/api/leagues', ['name' => 'Cup', 'duration_days' => 7], $headers)->assertCreated()->json('data.id');
        $this->postJson("/api/leagues/{$id}/start", [], $headers)->assertOk();

        $ids = $this->roundIds(League::find($id));
        $this->assertCount(7, $ids);
        $this->assertCount(7, array_unique($ids));
    }

    public function test_duration_14_creates_14_unique_rounds_when_14_eligible_exist(): void
    {
        $this->challenges($this->sport(), 14);
        [$user, $headers] = $this->auth();

        $id = $this->postJson('/api/leagues', ['name' => 'Cup', 'duration_days' => 14], $headers)->assertCreated()->json('data.id');
        $this->postJson("/api/leagues/{$id}/start", [], $headers)->assertOk();

        $ids = $this->roundIds(League::find($id));
        $this->assertCount(14, $ids);
        $this->assertCount(14, array_unique($ids));
    }

    public function test_duration_30_creates_30_unique_rounds_when_30_eligible_exist(): void
    {
        $this->challenges($this->sport(), 30);
        [$user, $headers] = $this->auth();

        $id = $this->postJson('/api/leagues', ['name' => 'Cup', 'duration_days' => 30], $headers)->assertCreated()->json('data.id');
        $this->postJson("/api/leagues/{$id}/start", [], $headers)->assertOk();

        $ids = $this->roundIds(League::find($id));
        $this->assertCount(30, $ids);
        $this->assertCount(30, array_unique($ids));
    }

    public function test_other_durations_are_rejected(): void
    {
        $this->sport();
        [$user, $headers] = $this->auth();

        foreach ([0, 1, 3, 365, -1, 29, 31] as $days) {
            $this->postJson('/api/leagues', ['name' => 'Cup', 'duration_days' => $days], $headers)
                ->assertStatus(422)
                ->assertJsonValidationErrors('duration_days');
        }
        $this->postJson('/api/leagues', ['name' => 'Cup', 'duration_days' => 'seven'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('duration_days');
        $this->postJson('/api/leagues', ['name' => 'Cup'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('duration_days');

        $this->assertSame(0, League::count());
    }

    public function test_start_returns_422_when_eligible_pool_smaller_than_duration(): void
    {
        $this->challenges($this->sport(), 6);
        [$user, $headers] = $this->auth();

        $id = $this->postJson('/api/leagues', ['name' => 'Cup', 'duration_days' => 7], $headers)->assertCreated()->json('data.id');
        $this->postJson("/api/leagues/{$id}/start", [], $headers)
            ->assertStatus(422)
            ->assertJsonPath('message', LeagueService::NOT_ENOUGH_CHALLENGES_MESSAGE);

        $this->assertSame(0, LeagueRound::count());
        $this->assertSame('lobby', League::find($id)->status);
    }

    // ------------------------------------------------------------------
    // Cooldown setting + admin
    // ------------------------------------------------------------------

    public function test_default_cooldown_is_90_days(): void
    {
        $this->assertSame(90, GameplaySetting::tournamentChallengeCooldownDays());
    }

    public function test_admin_can_view_and_update_cooldown(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/admin/settings')
            ->assertOk()->assertSee('Tournament challenge cooldown')->assertSee('value="90"', false);

        $this->actingAs($admin)->put('/admin/settings', ['tournament_challenge_cooldown_days' => 30])
            ->assertRedirect('/admin/settings')->assertSessionHas('success');

        $this->assertSame(30, GameplaySetting::tournamentChallengeCooldownDays());
        $this->assertDatabaseHas('gameplay_settings', ['key' => 'tournament_challenge_cooldown_days', 'value' => '30']);

        $this->actingAs($admin)->put('/admin/settings', ['tournament_challenge_cooldown_days' => 0])
            ->assertRedirect('/admin/settings');
        $this->assertSame(0, GameplaySetting::tournamentChallengeCooldownDays());
    }

    public function test_admin_cooldown_rejects_invalid_values(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        foreach ([-1, 366, 'abc', null, 1.5] as $bad) {
            $this->actingAs($admin)->from('/admin/settings')
                ->put('/admin/settings', ['tournament_challenge_cooldown_days' => $bad])
                ->assertRedirect('/admin/settings')
                ->assertSessionHasErrors('tournament_challenge_cooldown_days');
        }
        $this->assertSame(90, GameplaySetting::tournamentChallengeCooldownDays(), 'invalid values must not be stored');
    }

    public function test_settings_page_requires_admin(): void
    {
        $this->get('/admin/settings')->assertRedirect();
        $player = User::factory()->create(['is_admin' => false]);
        $this->actingAs($player)->put('/admin/settings', ['tournament_challenge_cooldown_days' => 5]);
        $this->assertSame(90, GameplaySetting::tournamentChallengeCooldownDays());
    }

    // ------------------------------------------------------------------
    // Selection: seen history
    // ------------------------------------------------------------------

    public function test_challenges_seen_within_cooldown_are_avoided_when_enough_fresh_exist(): void
    {
        $sport = $this->sport();
        $fresh = $this->challenges($sport, 7, 'F');
        [$seenDaily, $seenTour, $seenPack] = $this->challenges($sport, 3, 'S');
        $owner  = User::factory()->create();
        $member = User::factory()->create();

        // Each history source, spread across different members.
        $this->dailyGuess($owner, $seenDaily, now()->subDays(10));
        $this->tournamentGuess($member, $seenTour, now()->subDays(20));
        $this->packGuess($member, $seenPack, now()->subDays(30));

        // All three history sources are traced (the daily one is also
        // Daily-used, so it is hard-excluded regardless — asserted below).
        $this->assertEqualsCanonicalizing(
            [$seenDaily, $seenTour, $seenPack],
            app(LeagueService::class)->recentlySeenChallengeIds([$owner->id, $member->id], 90)
        );

        $league = $this->lobby($owner, 7, [$member]);
        app(LeagueService::class)->start($league, $owner->id);

        $ids = $this->roundIds($league);
        $this->assertEqualsCanonicalizing($fresh, $ids);
        $this->assertNotContains($seenDaily, $ids);
        $this->assertNotContains($seenTour, $ids);
        $this->assertNotContains($seenPack, $ids);
    }

    public function test_challenges_seen_outside_cooldown_can_be_used(): void
    {
        $sport = $this->sport();
        $this->challenges($sport, 6, 'F');
        $old = $this->challenge($sport, 'OldSeen')->id;
        $owner = User::factory()->create();
        $this->tournamentGuess($owner, $old, now()->subDays(91));

        // Only 6 fresh + 1 seen-long-ago: the old one must be treated as fresh.
        $seen = app(LeagueService::class)->recentlySeenChallengeIds([$owner->id], 90);
        $this->assertSame([], $seen);

        $league = $this->lobby($owner, 7);
        app(LeagueService::class)->start($league, $owner->id);
        $this->assertContains($old, $this->roundIds($league));
    }

    public function test_cooldown_zero_disables_history_avoidance(): void
    {
        $sport = $this->sport();
        $this->challenges($sport, 7, 'F');
        $seen = $this->challenge($sport, 'Seen')->id;
        $owner = User::factory()->create();
        $this->tournamentGuess($owner, $seen, now()->subDay());
        GameplaySetting::put(GameplaySetting::TOURNAMENT_CHALLENGE_COOLDOWN_DAYS, 0);

        $this->assertSame([], app(LeagueService::class)->recentlySeenChallengeIds([$owner->id], 0));

        // With avoidance off the seen photo is a normal candidate: over many
        // draws it must show up at least once (8 candidates, 7 picked).
        $picked = false;
        for ($i = 0; $i < 30 && !$picked; $i++) {
            $league = $this->lobby(User::factory()->create(), 7, [$owner]);
            $ids = app(LeagueService::class)->selectTournamentChallenges($league, 7)->pluck('id')->all();
            $picked = in_array($seen, $ids, true);
        }
        $this->assertTrue($picked, 'cooldown 0 must not exclude previously seen photos');
    }

    public function test_admin_cooldown_value_is_used_by_selection(): void
    {
        $sport = $this->sport();
        $this->challenges($sport, 7, 'F');
        $seen = $this->challenge($sport, 'Seen')->id;
        $owner = User::factory()->create();
        $this->tournamentGuess($owner, $seen, now()->subDays(45));

        // Default 90 → avoided.
        $league = $this->lobby($owner, 7);
        app(LeagueService::class)->start($league, $owner->id);
        $this->assertNotContains($seen, $this->roundIds($league));

        // Admin lowers to 30 → 45-day-old guess no longer counts.
        GameplaySetting::put(GameplaySetting::TOURNAMENT_CHALLENGE_COOLDOWN_DAYS, 30);
        $this->assertSame([], app(LeagueService::class)->recentlySeenChallengeIds([$owner->id], GameplaySetting::tournamentChallengeCooldownDays()));
    }

    public function test_history_of_every_member_counts_not_only_the_owner(): void
    {
        $sport = $this->sport();
        $fresh = $this->challenges($sport, 7, 'F');
        $seenByMember = $this->challenge($sport, 'SeenByMember')->id;
        $owner  = User::factory()->create();
        $member = User::factory()->create();
        $this->tournamentGuess($member, $seenByMember, now()->subDays(5));

        $league = $this->lobby($owner, 7, [$member]);
        app(LeagueService::class)->start($league, $owner->id);

        $this->assertEqualsCanonicalizing($fresh, $this->roundIds($league));
    }

    // ------------------------------------------------------------------
    // Fallback
    // ------------------------------------------------------------------

    public function test_falls_back_to_seen_challenges_when_not_enough_fresh(): void
    {
        $sport = $this->sport();
        $fresh = $this->challenges($sport, 4, 'F');
        $seen  = $this->challenges($sport, 5, 'S');
        $owner = User::factory()->create();
        foreach ($seen as $id) {
            $this->tournamentGuess($owner, $id, now()->subDays(3));
        }

        $league = $this->lobby($owner, 7);
        app(LeagueService::class)->start($league, $owner->id);

        $ids = $this->roundIds($league);
        $this->assertCount(7, $ids);
        $this->assertCount(7, array_unique($ids), 'no repeats even in fallback');
        foreach ($fresh as $f) {
            $this->assertContains($f, $ids, 'all fresh photos are used first');
        }
        $this->assertCount(3, array_intersect($ids, $seen), 'exactly the shortfall is topped up from seen photos');
        $this->assertSame('active', $league->fresh()->status);
    }

    public function test_fallback_never_uses_daily_used_challenges(): void
    {
        $sport = $this->sport();
        $this->challenges($sport, 3, 'F');
        $seenOk = $this->challenges($sport, 4, 'S');
        $owner  = User::factory()->create();
        foreach ($seenOk as $id) {
            $this->tournamentGuess($owner, $id, now()->subDays(3));
        }
        // Daily-used (any status, any date) — never a candidate, seen or not.
        $dailyUsed = $this->challenges($sport, 5, 'D');
        foreach ($dailyUsed as $i => $id) {
            DailyChallenge::create(['challenge_id' => $id, 'challenge_date' => now()->subDays(200 + $i)->toDateString(), 'status' => 'archived']);
        }

        $league = $this->lobby($owner, 7);
        app(LeagueService::class)->start($league, $owner->id);

        $ids = $this->roundIds($league);
        $this->assertCount(7, $ids);
        $this->assertCount(7, array_unique($ids));
        $this->assertSame([], array_intersect($ids, $dailyUsed));
    }

    public function test_422_only_when_total_unique_eligible_non_daily_is_insufficient(): void
    {
        $sport = $this->sport();
        // 3 fresh + 3 seen = 6 eligible < 7 needed; daily-used photos don't help.
        $this->challenges($sport, 3, 'F');
        $seen = $this->challenges($sport, 3, 'S');
        $owner = User::factory()->create();
        foreach ($seen as $id) {
            $this->tournamentGuess($owner, $id, now()->subDays(3));
        }
        $daily = $this->challenge($sport, 'Daily')->id;
        DailyChallenge::create(['challenge_id' => $daily, 'challenge_date' => '2026-01-01', 'status' => 'archived']);
        [$owner, $headers] = $this->auth($owner);

        $id = $this->postJson('/api/leagues', ['name' => 'Cup', 'duration_days' => 7], $headers)->assertCreated()->json('data.id');
        $this->postJson("/api/leagues/{$id}/start", [], $headers)
            ->assertStatus(422)
            ->assertJsonPath('message', LeagueService::NOT_ENOUGH_CHALLENGES_MESSAGE);
        $this->assertSame(0, LeagueRound::where('league_id', $id)->count());

        // One more eligible (even a seen one) tips it over: starts fine.
        $extra = $this->challenge($sport, 'Extra')->id;
        $this->tournamentGuess($owner, $extra, now()->subDays(2));
        $this->postJson("/api/leagues/{$id}/start", [], $headers)->assertOk();
        $this->assertCount(7, array_unique($this->roundIds(League::find($id))));
    }

    public function test_same_challenge_never_repeats_within_one_tournament_across_many_starts(): void
    {
        $sport = $this->sport();
        $pool  = $this->challenges($sport, 8);
        $owner = User::factory()->create();
        // Owner has seen most of the pool recently → heavy fallback each time.
        foreach (array_slice($pool, 0, 6) as $id) {
            $this->tournamentGuess($owner, $id, now()->subDays(1));
        }

        for ($run = 0; $run < 15; $run++) {
            $league = $this->lobby(User::factory()->create(), 7, [$owner]);
            $ids = app(LeagueService::class)->selectTournamentChallenges($league, 7)->pluck('id')->all();
            $this->assertCount(7, $ids);
            $this->assertCount(7, array_unique($ids), "run {$run} repeated a photo");
        }
    }

    // ------------------------------------------------------------------
    // Legacy tournaments
    // ------------------------------------------------------------------

    public function test_old_tournaments_with_other_durations_still_load_and_play(): void
    {
        $sport = $this->sport();
        $c1 = $this->challenge($sport, 'L1');
        $c2 = $this->challenge($sport, 'L2');
        [$user, $headers] = $this->auth();

        foreach ([1, 2, 3, 5] as $days) {
            $league = League::create([
                'name' => "Old {$days}", 'join_code' => "OLD00{$days}", 'owner_user_id' => $user->id,
                'sport_id' => $sport->id, 'duration_days' => $days, 'rounds_per_day' => 1, 'status' => 'active',
                'starts_at' => now()->subDay(), 'ends_at' => now()->addDays($days),
            ]);
            LeagueMember::create(['league_id' => $league->id, 'user_id' => $user->id, 'joined_at' => now()]);
            LeagueRound::create(['league_id' => $league->id, 'challenge_id' => $c1->id, 'round_number' => 1, 'status' => 'open']);
            LeagueRound::create(['league_id' => $league->id, 'challenge_id' => $c2->id, 'round_number' => 2, 'status' => 'open']);
        }

        $list = $this->getJson('/api/leagues', $headers)->assertOk();
        $this->assertCount(4, $list->json('data'));

        $league = League::where('duration_days', 3)->first();
        $this->getJson("/api/leagues/{$league->id}", $headers)->assertOk()->assertJsonPath('data.duration_days', 3);
        $current = $this->getJson("/api/leagues/{$league->id}/current-round", $headers)->assertOk();
        $this->assertTrue($current->json('has_current_round'));
        $this->postJson('/api/rounds/' . $current->json('current_round.id') . '/guess', ['guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5], $headers)
            ->assertStatus(201);
    }
}
