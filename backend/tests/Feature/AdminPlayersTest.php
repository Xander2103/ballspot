<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\CompetitionFinish;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeGuess;
use App\Models\League;
use App\Models\LeagueMember;
use App\Models\Sport;
use App\Models\TournamentFinish;
use App\Models\User;
use App\Services\AccountDeletionService;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminPlayersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $jan;
    private User $eva;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeAdmin();
        $this->jan = User::factory()->create(['username' => 'GoalGetter', 'name' => 'Jan Peeters', 'email' => 'jan.peeters@example.test']);
        $this->eva = User::factory()->create(['username' => 'keeper_eva', 'name' => 'Eva Claes', 'email' => 'eva@other.test']);
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create(['username' => 'boss', 'name' => 'The Boss', 'email' => 'boss@example.test']);
        $admin->is_admin = true;
        $admin->save();

        return $admin;
    }

    private function search(string $q, array $extra = [])
    {
        return $this->actingAs($this->admin)->get(route('admin.players.index', ['q' => $q] + $extra));
    }

    /** @return array<int> ids on the page, from the rendered dossier links */
    private function ids($response): array
    {
        preg_match_all('#/admin/players/(\d+)"#', $response->getContent(), $m);

        return array_map('intval', $m[1]);
    }

    #[DataProvider('janQueries')]
    public function test_search_finds_a_player_by_partial_case_insensitive_input(string $q): void
    {
        $res = $this->search($q)->assertOk();

        $this->assertSame([$this->jan->id], $this->ids($res));
    }

    public static function janQueries(): array
    {
        return [
            'username part'       => ['goalget'],
            'username upper'      => ['GOALGETTER'],
            'first name'          => ['jan'],
            'last name'           => ['PEETERS'],
            'full name'           => ['Jan Peeters'],
            'reversed full name'  => ['peeters jan'],
            'email part'          => ['peeters@exam'],
            'whole email upper'   => ['JAN.PEETERS@EXAMPLE.TEST'],
            'username with @'     => ['@goalgetter'],
        ];
    }

    public function test_search_by_user_id_is_exact_and_ranks_first(): void
    {
        $numeric = User::factory()->create(['username' => 'player' . $this->jan->id, 'name' => 'Numbers']);

        $ids = $this->ids($this->search((string) $this->jan->id)->assertOk());
        $this->assertSame($this->jan->id, $ids[0]);
        $this->assertContains($numeric->id, $ids);

        $this->assertSame([$this->eva->id], $this->ids($this->search('#' . $this->eva->id)->assertOk()));
    }

    public function test_like_wildcards_are_treated_as_plain_text(): void
    {
        $this->assertSame([$this->eva->id], $this->ids($this->search('keeper_')->assertOk()));
        $this->assertSame([], $this->ids($this->search('%')->assertOk()));
        $this->assertSame([], $this->ids($this->search('k_eper')->assertOk()));
    }

    public function test_quotes_in_a_search_are_harmless(): void
    {
        $this->search("' OR 1=1 --")->assertOk()->assertSee('No players match', false);
    }

    public function test_empty_result_message_escapes_the_search_text(): void
    {
        $this->search('<script>alert(1)</script>')->assertOk()
            ->assertSee('No players match', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_results_are_paginated_server_side_and_keep_the_query(): void
    {
        User::factory()->count(30)->create(['name' => 'Bulk Player']);

        $res = $this->search('bulk')->assertOk();

        $this->assertCount(25, $this->ids($res));
        $res->assertSee('q=bulk', false)->assertSee('page=2', false)->assertSee('30 accounts', false);
    }

    public function test_status_filter_separates_unverified_and_deleted_accounts(): void
    {
        $unverified = User::factory()->unverified()->create(['username' => 'fresh']);
        $deleted = User::factory()->create(['username' => 'goner']);
        app(AccountDeletionService::class)->delete($deleted);

        $this->assertSame([$unverified->id], $this->ids($this->search('', ['status' => 'unverified'])));
        $this->assertSame([$deleted->id], $this->ids($this->search('', ['status' => 'deleted'])));
    }

    public function test_over_long_and_malformed_queries_are_refused_cleanly(): void
    {
        $this->search(str_repeat('a', 101))->assertSessionHasErrors('q');
        $this->actingAs($this->admin)->get(route('admin.players.index', ['q' => ['x']]))->assertSessionHasErrors('q');
        $this->search('jan', ['status' => 'nonsense'])->assertSessionHasErrors('status');
    }

    public function test_a_database_failure_shows_an_error_instead_of_a_crash(): void
    {
        $service = Mockery::mock(\App\Services\AdminPlayerService::class);
        $service->shouldReceive('search')->andThrow(new \Illuminate\Database\QueryException('sqlite', 'select 1', [], new \Exception('gone')));
        $this->app->instance(\App\Services\AdminPlayerService::class, $service);

        $res = $this->search('jan')->assertStatus(503);
        $res->assertSee('data-player-search-error', false);
    }

    public function test_guests_and_regular_players_cannot_reach_personal_data(): void
    {
        $indexUrl = route('admin.players.index');
        $showUrl = route('admin.players.show', $this->jan->id);

        $this->get($indexUrl)->assertRedirect(route('admin.login'));
        $this->get($showUrl)->assertRedirect(route('admin.login'));

        foreach ([$indexUrl, $showUrl] as $url) {
            $res = $this->actingAs($this->eva)->get($url);
            $this->assertNotSame(200, $res->getStatusCode());
            $this->assertStringNotContainsString('jan.peeters@example.test', $res->getContent());
        }

        // The API token of a regular player does not open the panel either.
        $this->withToken($this->eva->createToken('t')->plainTextToken)
            ->getJson('/api/users/' . $this->jan->id . '/public-profile')
            ->assertJsonMissingPath('data.email');
    }

    public function test_dossier_shows_stored_data_and_never_secrets(): void
    {
        $this->seed(BadgeSeeder::class);
        $sport = Sport::create(['name' => 'Football', 'slug' => 'football', 'emoji' => '⚽', 'primary_color' => '#00c853', 'status' => 'active']);
        $challenge = Challenge::create(['sport_id' => $sport->id, 'title' => 'T', 'hidden_image_path' => 'x.jpg',
            'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5, 'difficulty' => 'easy', 'status' => 'active']);
        $daily = DailyChallenge::create(['challenge_id' => $challenge->id, 'challenge_date' => now()->toDateString(), 'status' => 'active']);
        DailyChallengeGuess::create(['daily_challenge_id' => $daily->id, 'user_id' => $this->jan->id, 'guess_x_ratio' => 0.5, 'guess_y_ratio' => 0.5,
            'distance' => 0.0, 'score' => 87, 'submitted_at' => now()]);
        $league = League::create(['name' => 'Friday Squad', 'join_code' => 'ABCDEF', 'owner_user_id' => $this->jan->id, 'sport_id' => $sport->id,
            'duration_days' => 1, 'rounds_per_day' => 1, 'status' => 'completed']);
        LeagueMember::create(['league_id' => $league->id, 'user_id' => $this->jan->id, 'joined_at' => now()]);
        TournamentFinish::create(['league_id' => $league->id, 'user_id' => $this->jan->id, 'placement' => 1, 'total_score' => 321, 'rounds_played' => 3]);
        CompetitionFinish::create(['user_id' => $this->jan->id, 'period_type' => 'monthly', 'period_label' => 'June 2026', 'period_start' => '2026-06-01',
            'period_end' => '2026-06-30', 'placement' => 1, 'total_score' => 654, 'total_players' => 40, 'xp_awarded' => 500]);

        $jan = $this->jan->fresh();
        $res = $this->actingAs($this->admin)->get(route('admin.players.show', $jan->id))->assertOk();

        foreach ([(string) $jan->id, 'GoalGetter', 'Jan Peeters', 'jan.peeters@example.test', '87', 'Friday Squad', '321', 'June 2026', '654'] as $expected) {
            $res->assertSee($expected, false);
        }
        foreach (['data-section="account"', 'data-section="daily"', 'data-section="competition"', 'data-section="tournaments"', 'data-section="xp"', 'data-section="badges"'] as $section) {
            $res->assertSee($section, false);
        }
        $this->assertStringNotContainsString($jan->friend_code, $res->getContent());
        $this->assertStringNotContainsString($jan->password, $res->getContent());
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    public function test_a_dossier_without_activity_renders_empty_states(): void
    {
        $this->actingAs($this->admin)->get(route('admin.players.show', $this->eva->id))->assertOk()
            ->assertSee('No closed competition placements.')
            ->assertSee('No tournaments joined.');
    }

    public function test_an_unknown_player_is_a_404(): void
    {
        $this->actingAs($this->admin)->get('/admin/players/999999')->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/players/GoalGetter')->assertNotFound();
    }

    public function test_contact_link_only_for_a_verified_address_of_a_live_account(): void
    {
        $this->actingAs($this->admin)->get(route('admin.players.show', $this->jan->id))
            ->assertSee('href="mailto:jan.peeters@example.test?subject=BallPicker"', false);

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($this->admin)->get(route('admin.players.show', $unverified->id))
            ->assertDontSee('mailto:', false)->assertSee('Email not verified');

        $deleted = User::factory()->create();
        app(AccountDeletionService::class)->delete($deleted);
        $this->actingAs($this->admin)->get(route('admin.players.show', $deleted->id))
            ->assertOk()->assertDontSee('mailto:', false)->assertSee('Deleted account');
    }

    public function test_access_is_logged_by_id_without_personal_data_or_search_text(): void
    {
        $logged = [];
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')->andReturnUsing(function ($level, $message, $context) use (&$logged) {
            $logged[$message] = $context;
        });

        $this->search('jan.peeters@example.test')->assertOk();
        $this->actingAs($this->admin)->get(route('admin.players.show', $this->jan->id))->assertOk();

        $this->assertSame($this->admin->id, $logged['admin.player_search']['admin_user_id']);
        $this->assertSame(1, $logged['admin.player_search']['results']);
        $this->assertSame($this->jan->id, $logged['admin.player_file_viewed']['target_user_id']);

        $encoded = json_encode($logged);
        $this->assertStringNotContainsString('peeters', $encoded);
        $this->assertStringNotContainsString('password', $encoded);
    }

    public function test_competition_dashboard_links_rankings_and_winners_by_user_id(): void
    {
        // The second account uses the winner's real name as its username: the
        // link must still go to the winner's stored id.
        $sameName = User::factory()->create(['username' => 'Jan Peeters', 'name' => 'Someone Else']);
        CompetitionFinish::create(['user_id' => $this->jan->id, 'period_type' => 'monthly', 'period_label' => 'June 2026', 'period_start' => '2026-06-01',
            'period_end' => '2026-06-30', 'placement' => 1, 'total_score' => 654, 'total_players' => 40, 'xp_awarded' => 500]);
        CompetitionFinish::create(['user_id' => null, 'period_type' => 'monthly', 'period_label' => 'June 2026', 'period_start' => '2026-06-01',
            'period_end' => '2026-06-30', 'placement' => 2, 'total_score' => 600, 'total_players' => 40, 'xp_awarded' => 250]);

        $res = $this->actingAs($this->admin)->get('/admin/competition')->assertOk();

        $res->assertSee('data-section="winners"', false)
            ->assertSee('href="' . route('admin.players.show', $this->jan->id) . '"', false)
            ->assertDontSee('href="' . route('admin.players.show', $sameName->id) . '"', false)
            ->assertSee('Account removed')
            ->assertDontSee('jan.peeters@example.test', false);
    }

    public function test_admin_layout_loads_bootstrap_js_with_its_real_integrity_hash(): void
    {
        // A wrong hash makes the browser block the script: dropdowns and alert close
        // buttons on every admin page silently stop working. Official 5.3.3 value.
        $this->actingAs($this->admin)->get(route('admin.players.index'))->assertOk()
            ->assertSee('sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz', false);
    }

    public function test_players_section_is_in_the_navigation(): void
    {
        $this->actingAs($this->admin)->get('/admin/competition')->assertSee('href="/admin/players"', false);
    }
}
