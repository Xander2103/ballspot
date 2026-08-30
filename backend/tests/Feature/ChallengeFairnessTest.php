<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\League;
use App\Models\LeagueMember;
use App\Models\LeagueRound;
use App\Models\Sport;
use App\Models\User;
use App\Services\DailyChallengeScheduler;
use App\Services\LeagueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * v1.8.9 challenge fairness rules.
 *
 *  - Daily has priority: a challenge in daily_challenges is permanently
 *    Daily-used and never enters a new tournament.
 *  - Daily scheduler only uses daily|general pool challenges, each once.
 *  - New tournaments draw duration_days unique tournament|general photos and
 *    fail with a clean 422 when there are not enough.
 *  - Old tournaments (whatever their rounds reference) keep working.
 */
class ChallengeFairnessTest extends TestCase
{
    use RefreshDatabase;

    private function sport(string $slug = 'football'): Sport
    {
        return Sport::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
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

    private function auth(): array
    {
        $user = User::factory()->create();
        return [$user, ['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken]];
    }

    private function createLeague(array $headers, int $days): int
    {
        return $this->postJson('/api/leagues', ['name' => 'Cup', 'duration_days' => $days], $headers)
            ->assertCreated()
            ->json('data.id');
    }

    // ------------------------------------------------------------------
    // Migration / backfill
    // ------------------------------------------------------------------

    public function test_usage_pool_column_exists_with_general_default(): void
    {
        $this->assertTrue(Schema::hasColumn('challenges', 'usage_pool'));

        $c = $this->challenge($this->sport(), 'Plain');
        $this->assertSame(Challenge::POOL_GENERAL, $c->fresh()->usage_pool);
    }

    public function test_backfill_marks_daily_used_challenges_as_daily_pool_without_touching_others(): void
    {
        $sport = $this->sport();
        $used  = $this->challenge($sport, 'Was a daily');
        $free  = $this->challenge($sport, 'Never a daily');
        $tour  = $this->challenge($sport, 'Tournament one', ['usage_pool' => Challenge::POOL_TOURNAMENT]);
        DailyChallenge::create(['challenge_id' => $used->id, 'challenge_date' => '2026-01-01', 'status' => 'archived']);

        // Re-run the migration's backfill statement: it must be idempotent and
        // only touch 'general' rows that appear in daily_challenges.
        $migration = require database_path('migrations/2026_08_29_000001_add_usage_pool_to_challenges.php');
        $migration->up();
        $migration->up();

        $this->assertSame(Challenge::POOL_DAILY, $used->fresh()->usage_pool);
        $this->assertSame(Challenge::POOL_GENERAL, $free->fresh()->usage_pool);
        $this->assertSame(Challenge::POOL_TOURNAMENT, $tour->fresh()->usage_pool);
        $this->assertSame(3, Challenge::count(), 'backfill must never delete challenges');
        $this->assertSame(1, DailyChallenge::count(), 'backfill must never delete daily history');
    }

    // ------------------------------------------------------------------
    // Daily scheduler
    // ------------------------------------------------------------------

    public function test_daily_scheduler_only_offers_unused_daily_pool_challenges(): void
    {
        $sport = $this->sport();
        $general    = $this->challenge($sport, 'General');
        $daily      = $this->challenge($sport, 'Daily', ['usage_pool' => Challenge::POOL_DAILY]);
        $tournament = $this->challenge($sport, 'Tournament', ['usage_pool' => Challenge::POOL_TOURNAMENT]);
        $pack       = $this->challenge($sport, 'Pack', ['usage_pool' => Challenge::POOL_PACK]);
        $usedDaily  = $this->challenge($sport, 'Used', ['usage_pool' => Challenge::POOL_DAILY]);
        $this->challenge($sport, 'Draft', ['status' => 'draft']);
        DailyChallenge::create(['challenge_id' => $usedDaily->id, 'challenge_date' => '2026-01-01', 'status' => 'archived']);

        $ids = app(DailyChallengeScheduler::class)->eligibleChallenges()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$general->id, $daily->id], $ids);
        $this->assertNotContains($tournament->id, $ids);
        $this->assertNotContains($pack->id, $ids);
    }

    public function test_daily_scheduler_skips_tournament_pool_and_already_used_challenges(): void
    {
        $sport = $this->sport();
        $ok    = $this->challenge($sport, 'Ok');
        $tour  = $this->challenge($sport, 'Tour', ['usage_pool' => Challenge::POOL_TOURNAMENT]);
        $used  = $this->challenge($sport, 'Used');
        DailyChallenge::create(['challenge_id' => $used->id, 'challenge_date' => '2026-01-01', 'status' => 'archived']);

        $result = app(DailyChallengeScheduler::class)->schedule([$ok->id, $tour->id, $used->id], '2030-01-01');

        $this->assertCount(1, $result['created']);
        $this->assertSame($ok->id, $result['created'][0]['challenge']->id);
        $reasons = collect($result['skipped'])->pluck('reason', 'id')->all();
        $this->assertSame(DailyChallengeScheduler::SKIP_WRONG_POOL, $reasons[$tour->id]);
        $this->assertSame(DailyChallengeScheduler::SKIP_ALREADY_USED, $reasons[$used->id]);
    }

    public function test_daily_challenge_cannot_repeat_even_across_runs(): void
    {
        $sport = $this->sport();
        $only  = $this->challenge($sport, 'Only');
        $scheduler = app(DailyChallengeScheduler::class);

        $first  = $scheduler->schedule([$only->id], '2030-01-01');
        $second = $scheduler->schedule([$only->id], '2030-02-01');

        $this->assertCount(1, $first['created']);
        $this->assertCount(0, $second['created']);
        $this->assertSame(DailyChallengeScheduler::SKIP_ALREADY_USED, $second['skipped'][0]['reason']);
        $this->assertSame(1, DailyChallenge::where('challenge_id', $only->id)->count());
    }

    public function test_schedule_command_ignores_tournament_only_challenges(): void
    {
        $sport = $this->sport();
        $this->challenge($sport, 'Tour only', ['usage_pool' => Challenge::POOL_TOURNAMENT]);

        $code = Artisan::call('ballspot:schedule-daily-challenges', ['--days' => 3, '--start' => '2030-01-01']);

        $this->assertSame(1, $code, 'no daily-pool content should be a failure');
        $this->assertSame(0, DailyChallenge::count());
    }

    public function test_admin_set_as_daily_rejects_tournament_pool_and_reused_challenges(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $sport = $this->sport();
        $tour  = $this->challenge($sport, 'Tour', ['usage_pool' => Challenge::POOL_TOURNAMENT]);
        $used  = $this->challenge($sport, 'Used');
        DailyChallenge::create(['challenge_id' => $used->id, 'challenge_date' => '2026-01-01', 'status' => 'archived']);

        $this->actingAs($admin)->post("/admin/challenges/{$tour->id}/set-as-daily", ['date' => '2030-05-05'])
            ->assertSessionHas('error');
        $this->actingAs($admin)->post("/admin/challenges/{$used->id}/set-as-daily", ['date' => '2030-05-05'])
            ->assertSessionHas('error');

        $this->assertSame(0, DailyChallenge::whereDate('challenge_date', '2030-05-05')->count());
    }

    // ------------------------------------------------------------------
    // Tournament generation
    // ------------------------------------------------------------------

    public function test_tournament_excludes_active_daily_and_uses_exactly_the_free_photos(): void
    {
        $sport = $this->sport();
        $dailyUsed = $this->challenge($sport, 'Daily used');
        DailyChallenge::create(['challenge_id' => $dailyUsed->id, 'challenge_date' => today()->toDateString(), 'status' => 'active']);
        $free = collect(range(1, 7))->map(fn ($i) => $this->challenge($sport, "Free {$i}")->id)->all();
        [$user, $headers] = $this->auth();

        $id = $this->createLeague($headers, 7);
        $this->postJson("/api/leagues/{$id}/start", [], $headers)->assertOk();

        $rounds = LeagueRound::where('league_id', $id)->pluck('challenge_id')->all();
        $this->assertEqualsCanonicalizing($free, $rounds);
        $this->assertNotContains($dailyUsed->id, $rounds);
    }

    public function test_tournament_duration_7_creates_7_unique_non_daily_rounds(): void
    {
        $sport = $this->sport();
        $dailyUsed = $this->challenge($sport, 'Daily used');
        // A FUTURE scheduled daily is also Daily-used.
        DailyChallenge::create(['challenge_id' => $dailyUsed->id, 'challenge_date' => today()->addDays(5)->toDateString(), 'status' => 'scheduled']);
        $eligible = collect(['A', 'B', 'C', 'D', 'E', 'F', 'G'])->map(fn ($t) => $this->challenge($sport, $t)->id)->all();
        [$user, $headers] = $this->auth();

        $id = $this->createLeague($headers, 7);
        $this->postJson("/api/leagues/{$id}/start", [], $headers)->assertOk();

        $rounds = LeagueRound::where('league_id', $id)->orderBy('round_number')->pluck('challenge_id')->all();
        $this->assertCount(7, $rounds);
        $this->assertSame($rounds, array_values(array_unique($rounds)), 'no duplicate challenge in one tournament');
        $this->assertEqualsCanonicalizing($eligible, $rounds);
        $this->assertNotContains($dailyUsed->id, $rounds);
    }

    public function test_tournament_never_repeats_a_challenge_across_many_starts(): void
    {
        $sport = $this->sport();
        for ($i = 0; $i < 10; $i++) {
            $this->challenge($sport, "C{$i}");
        }
        $service = app(LeagueService::class);

        for ($run = 0; $run < 20; $run++) {
            $owner  = User::factory()->create();
            $league = League::create([
                'name' => "Run {$run}", 'join_code' => "R{$run}A00", 'owner_user_id' => $owner->id,
                'sport_id' => $sport->id, 'duration_days' => 7, 'rounds_per_day' => 1, 'status' => 'lobby',
            ]);
            LeagueMember::create(['league_id' => $league->id, 'user_id' => $owner->id, 'joined_at' => now()]);

            $service->start($league, $owner->id);

            $ids = LeagueRound::where('league_id', $league->id)->pluck('challenge_id')->all();
            $this->assertCount(7, $ids);
            $this->assertCount(7, array_unique($ids), "run {$run} repeated a photo");
        }
    }

    public function test_tournament_rejects_start_when_not_enough_unique_eligible_challenges(): void
    {
        $sport = $this->sport();
        $this->challenge($sport, 'One');
        $this->challenge($sport, 'Two');
        [$user, $headers] = $this->auth();

        $id = $this->createLeague($headers, 7);
        $res = $this->postJson("/api/leagues/{$id}/start", [], $headers);

        $res->assertStatus(422)
            ->assertJsonPath('message', 'Not enough unused tournament challenges available. Add more tournament photos first.');
        $this->assertSame(0, LeagueRound::where('league_id', $id)->count(), 'no partial rounds');
        $this->assertSame('lobby', League::find($id)->status);
    }

    public function test_tournament_rejects_start_when_only_daily_used_challenges_exist(): void
    {
        $sport = $this->sport();
        $c = $this->challenge($sport, 'Was daily');
        DailyChallenge::create(['challenge_id' => $c->id, 'challenge_date' => '2026-01-01', 'status' => 'archived']);
        [$user, $headers] = $this->auth();

        $id = $this->createLeague($headers, 7);

        $this->postJson("/api/leagues/{$id}/start", [], $headers)
            ->assertStatus(422)
            ->assertJsonPath('message', LeagueService::NOT_ENOUGH_CHALLENGES_MESSAGE);
    }

    public function test_tournament_ignores_daily_and_pack_pool_challenges(): void
    {
        $sport = $this->sport();
        $this->challenge($sport, 'Daily pool', ['usage_pool' => Challenge::POOL_DAILY]);
        $this->challenge($sport, 'Pack pool',  ['usage_pool' => Challenge::POOL_PACK]);
        $tour = collect(range(1, 7))->map(fn ($i) => $this->challenge($sport, "Tour pool {$i}", ['usage_pool' => Challenge::POOL_TOURNAMENT])->id)->all();
        [$user, $headers] = $this->auth();

        $id = $this->createLeague($headers, 7);
        $this->postJson("/api/leagues/{$id}/start", [], $headers)->assertOk();

        $this->assertEqualsCanonicalizing($tour, LeagueRound::where('league_id', $id)->pluck('challenge_id')->all());
    }

    public function test_tournament_service_aborts_422_without_creating_rounds(): void
    {
        $sport = $this->sport();
        $this->challenge($sport, 'Only one');
        $owner  = User::factory()->create();
        $league = League::create([
            'name' => 'Svc', 'join_code' => 'SVC001', 'owner_user_id' => $owner->id,
            'sport_id' => $sport->id, 'duration_days' => 3, 'rounds_per_day' => 1, 'status' => 'lobby',
        ]);

        try {
            app(LeagueService::class)->start($league, $owner->id);
            $this->fail('expected 422');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, LeagueRound::count());
        $this->assertSame('lobby', $league->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Existing tournaments keep working
    // ------------------------------------------------------------------

    public function test_existing_tournament_with_daily_used_and_repeated_rounds_still_plays(): void
    {
        $sport = $this->sport();
        $c = $this->challenge($sport, 'Legacy');
        // Old data: the same photo twice, and later used as a daily too.
        DailyChallenge::create(['challenge_id' => $c->id, 'challenge_date' => today()->toDateString(), 'status' => 'active']);
        [$user, $headers] = $this->auth();
        $league = League::create([
            'name' => 'Old', 'join_code' => 'OLD001', 'owner_user_id' => $user->id,
            'sport_id' => $sport->id, 'duration_days' => 2, 'rounds_per_day' => 1, 'status' => 'active',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        ]);
        LeagueMember::create(['league_id' => $league->id, 'user_id' => $user->id, 'joined_at' => now()]);
        foreach ([1, 2] as $n) {
            LeagueRound::create(['league_id' => $league->id, 'challenge_id' => $c->id, 'round_number' => $n, 'status' => 'open']);
        }

        $this->getJson('/api/leagues', $headers)->assertOk()->assertJsonPath('data.0.id', $league->id);

        $current = $this->getJson("/api/leagues/{$league->id}/current-round", $headers)->assertOk();
        $this->assertTrue($current->json('has_current_round'));
        $this->assertSame($c->id, $current->json('current_round.challenge.id'));

        $roundId = $current->json('current_round.id');
        $this->postJson("/api/rounds/{$roundId}/guess", ['guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5], $headers)
            ->assertStatus(201);
    }

    // ------------------------------------------------------------------
    // Admin
    // ------------------------------------------------------------------

    public function test_admin_update_stores_usage_pool_and_keeps_it_when_omitted(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $c = $this->challenge($this->sport(), 'Editable');

        $payload = [
            'title' => 'Editable', 'difficulty' => 'easy', 'status' => 'active',
            'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5,
        ];

        $this->actingAs($admin)->patch("/admin/challenges/{$c->id}", $payload + ['usage_pool' => 'tournament'])
            ->assertRedirect('/admin/challenges');
        $this->assertSame('tournament', $c->fresh()->usage_pool);

        // Old form without the field: pool is preserved, not reset.
        $this->actingAs($admin)->patch("/admin/challenges/{$c->id}", $payload)->assertRedirect('/admin/challenges');
        $this->assertSame('tournament', $c->fresh()->usage_pool);

        $this->actingAs($admin)->patch("/admin/challenges/{$c->id}", $payload + ['usage_pool' => 'bogus'])
            ->assertSessionHasErrors('usage_pool');
        $this->assertSame('tournament', $c->fresh()->usage_pool);
    }

    public function test_admin_challenge_pages_show_pool_and_daily_used(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $sport = $this->sport();
        $sport->update(['status' => Sport::STATUS_ACTIVE]); // warning only counts playable sports
        $c = $this->challenge($sport, 'Shown', ['usage_pool' => Challenge::POOL_DAILY]);
        DailyChallenge::create(['challenge_id' => $c->id, 'challenge_date' => '2026-01-01', 'status' => 'archived']);

        $this->actingAs($admin)->get('/admin/challenges')
            ->assertOk()->assertSee('Used as Daily')->assertSee('Low tournament pool');
        $this->actingAs($admin)->get("/admin/challenges/{$c->id}/edit")
            ->assertOk()->assertSee('Usage pool')->assertSee('Already used as a Daily Challenge');
        $this->actingAs($admin)->get('/admin/challenges/create')
            ->assertOk()->assertSee('Usage pool');
    }

    public function test_admin_list_filters_and_summary_reflect_pools_and_eligibility(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $sport = $this->sport();
        $sport->update(['status' => Sport::STATUS_ACTIVE]);
        $eligible  = $this->challenge($sport, 'EligibleTour', ['usage_pool' => Challenge::POOL_TOURNAMENT]);
        $general   = $this->challenge($sport, 'GeneralOne');
        $dailyPool = $this->challenge($sport, 'DailyPoolOne', ['usage_pool' => Challenge::POOL_DAILY]);
        $pack      = $this->challenge($sport, 'PackOne', ['usage_pool' => Challenge::POOL_PACK]);
        $usedGen   = $this->challenge($sport, 'UsedGeneral');
        $draftTour = $this->challenge($sport, 'DraftTour', ['usage_pool' => Challenge::POOL_TOURNAMENT, 'status' => 'draft']);
        DailyChallenge::create(['challenge_id' => $usedGen->id, 'challenge_date' => '2026-01-01', 'status' => 'archived']);

        // Summary + columns on the unfiltered list.
        $page = $this->actingAs($admin)->get('/admin/challenges')->assertOk();
        $page->assertSee('Tournament eligible')->assertSee('Blocked: used as Daily')
            ->assertSee('Daily only')->assertSee('Pack only')->assertSee('Blocked: not active')
            ->assertSee('Used as Daily')->assertSee('Excluded from tournaments')
            ->assertSee('Low tournament pool');
        $this->assertSame(2, $page->viewData('summary')['tournament_eligible']); // EligibleTour + GeneralOne
        $this->assertSame(1, $page->viewData('summary')['daily_pool']);
        $this->assertSame(1, $page->viewData('summary')['used_as_daily']);
        $this->assertSame(3, $page->viewData('summary')['pack_general']); // GeneralOne, PackOne, UsedGeneral

        // v1.9.1: the index is split into the main list ('challenges') and the
        // collapsed Used Daily panel ('usedDaily'). Filters apply to both, so
        // "visible" means present in either section.
        $titles = function ($q) use ($admin) {
            $res  = $this->actingAs($admin)->get('/admin/challenges?' . $q)->assertOk();
            $main = $res->viewData('challenges')->pluck('title');
            $used = $res->viewData('usedDaily')?->pluck('title') ?? collect();
            return $main->merge($used)->sort()->values()->all();
        };

        $this->assertSame(['EligibleTour', 'GeneralOne'], $titles('tournament=eligible'));
        $this->assertSame(['DailyPoolOne', 'DraftTour', 'PackOne', 'UsedGeneral'], $titles('tournament=blocked'));
        $this->assertSame(['DraftTour', 'EligibleTour'], $titles('usage_pool=tournament'));
        $this->assertSame(['DailyPoolOne'], $titles('usage_pool=daily'));
        $this->assertSame(['UsedGeneral'], $titles('used_as_daily=yes'));
        $this->assertCount(5, $titles('used_as_daily=no'));
        $this->assertCount(6, $titles(''), 'every existing challenge stays visible');
    }

    public function test_admin_daily_create_lists_daily_pool_first_and_labels_used_ones(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $sport = $this->sport();
        $this->challenge($sport, 'Alpha general');
        $this->challenge($sport, 'Zulu daily', ['usage_pool' => Challenge::POOL_DAILY]);
        $used = $this->challenge($sport, 'Used one');
        DailyChallenge::create(['challenge_id' => $used->id, 'challenge_date' => '2026-01-01', 'status' => 'archived']);

        $res = $this->actingAs($admin)->get('/admin/daily/create')->assertOk();
        $res->assertSeeInOrder(['Zulu daily', 'Alpha general']);
        $res->assertSee('Already used as Daily');
    }

    public function test_admin_daily_create_does_not_offer_used_or_tournament_pool_challenges(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $sport = $this->sport();
        $ok   = $this->challenge($sport, 'OkForDaily');
        $tour = $this->challenge($sport, 'TourOnlyPhoto', ['usage_pool' => Challenge::POOL_TOURNAMENT]);
        $used = $this->challenge($sport, 'UsedBefore');
        DailyChallenge::create(['challenge_id' => $used->id, 'challenge_date' => '2026-01-01', 'status' => 'archived']);

        $res = $this->actingAs($admin)->get('/admin/daily/create')->assertOk();
        $res->assertSee('name="challenge_ids[]" value="' . $ok->id . '"', false);
        $res->assertDontSee('name="challenge_ids[]" value="' . $tour->id . '"', false);
        $res->assertDontSee('name="challenge_ids[]" value="' . $used->id . '"', false);

        // Posting them anyway: only the eligible one is scheduled.
        $this->actingAs($admin)->post('/admin/daily', [
            'challenge_ids' => [$ok->id, $tour->id, $used->id], 'status' => 'scheduled', 'start_date' => '2030-01-01',
        ])->assertRedirect();
        $this->assertSame(1, DailyChallenge::where('challenge_date', '>=', '2030-01-01')->count());
        $this->assertSame(1, DailyChallenge::where('challenge_id', $used->id)->count());
    }
}
