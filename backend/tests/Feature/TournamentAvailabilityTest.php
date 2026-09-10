<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\League;
use App\Models\LeagueMember;
use App\Models\LeagueRound;
use App\Models\Sport;
use App\Models\User;
use App\Services\DiagnosticsService;
use App\Services\TournamentAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * When the tournament-eligible photo pool cannot fill a tournament, create /
 * start answer with a structured, friendly "temporarily unavailable" body
 * (never a bare 422), nothing is written, existing tournaments keep working,
 * and GET /tournaments/availability reports the state up front.
 */
class TournamentAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private const CODE   = 'TOURNAMENTS_TEMPORARILY_UNAVAILABLE';
    private const REASON = 'INSUFFICIENT_TOURNAMENT_CHALLENGES';

    private function sport(string $slug = 'football'): Sport
    {
        return Sport::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug), 'status' => Sport::STATUS_ACTIVE]);
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
            'usage_pool'        => Challenge::POOL_TOURNAMENT,
            'hidden_image_path' => "challenges/hidden/{$title}.jpg",
        ], $overrides));
    }

    private function fill(Sport $sport, int $n, array $overrides = []): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $this->challenge($sport, "p{$i}-" . uniqid(), $overrides);
        }
    }

    private function auth(): array
    {
        $user = User::factory()->create();

        return [$user, ['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken]];
    }

    private function assertUnavailableBody($res, int $required, int $available): void
    {
        $res->assertStatus(422)
            ->assertJsonPath('code', self::CODE)
            ->assertJsonPath('reason', self::REASON)
            ->assertJsonPath('required', $required)
            ->assertJsonPath('available', $available)
            ->assertJsonPath('message', __('messages.tournaments.temporarily_unavailable'));
        // Nothing technical leaks: no exception class, no SQL, no file paths.
        $this->assertStringNotContainsString('Exception', $res->getContent());
        $this->assertStringNotContainsString('SQLSTATE', $res->getContent());
    }

    // ----------------------------------------------------------------- create

    public function test_create_is_refused_with_a_structured_error_when_the_pool_is_short(): void
    {
        $sport = $this->sport();
        $this->fill($sport, 6);
        [$user, $headers] = $this->auth();

        $res = $this->postJson('/api/leagues', ['name' => 'Cup', 'duration_days' => 7], $headers);

        $this->assertUnavailableBody($res, 7, 6);
        $this->assertSame(0, League::count(), 'no lobby is created');
        $this->assertSame(0, LeagueMember::count());
    }

    public function test_create_succeeds_with_enough_eligible_challenges_and_start_creates_all_rounds(): void
    {
        $sport = $this->sport();
        $this->fill($sport, 7);
        [$user, $headers] = $this->auth();

        $id = $this->postJson('/api/leagues', ['name' => 'Cup', 'duration_days' => 7], $headers)
            ->assertCreated()->json('data.id');

        $this->postJson("/api/leagues/{$id}/start", [], $headers)->assertOk();
        $this->assertSame(7, LeagueRound::where('league_id', $id)->count());
        $this->assertSame('active', League::find($id)->status);
    }

    public function test_longer_tournaments_need_more_photos(): void
    {
        $sport = $this->sport();
        $this->fill($sport, 10);
        [$user, $headers] = $this->auth();

        $this->assertUnavailableBody($this->postJson('/api/leagues', ['name' => 'Month', 'duration_days' => 30], $headers), 30, 10);
        $this->postJson('/api/leagues', ['name' => 'Week', 'duration_days' => 7], $headers)->assertCreated();
    }

    // ------------------------------------------------------------------ start

    public function test_start_is_refused_with_the_structured_error_and_writes_nothing(): void
    {
        $sport = $this->sport();
        $photos = [];
        for ($i = 0; $i < 7; $i++) {
            $photos[] = $this->challenge($sport, "s{$i}");
        }
        [$user, $headers] = $this->auth();
        $id = $this->postJson('/api/leagues', ['name' => 'Cup', 'duration_days' => 7], $headers)->assertCreated()->json('data.id');

        // The pool shrinks after the lobby opened: one photo became a Daily.
        DailyChallenge::create(['challenge_id' => $photos[0]->id, 'challenge_date' => '2026-01-01', 'status' => 'archived']);

        $res = $this->postJson("/api/leagues/{$id}/start", [], $headers);

        $this->assertUnavailableBody($res, 7, 6);
        $this->assertSame(0, LeagueRound::where('league_id', $id)->count(), 'no partial rounds');
        $this->assertSame('lobby', League::find($id)->status);
    }

    public function test_existing_tournaments_with_rounds_still_load_when_the_pool_is_empty(): void
    {
        $sport = $this->sport();
        $challenge = $this->challenge($sport, 'old');
        [$user, $headers] = $this->auth();

        $league = League::create([
            'name' => 'Old', 'join_code' => 'OLDOLD', 'owner_user_id' => $user->id,
            'sport_id' => $sport->id, 'duration_days' => 1, 'rounds_per_day' => 1, 'status' => 'active',
        ]);
        LeagueMember::create(['league_id' => $league->id, 'user_id' => $user->id, 'joined_at' => now()]);
        LeagueRound::create(['league_id' => $league->id, 'challenge_id' => $challenge->id, 'round_number' => 1, 'status' => 'open']);

        // Pool is now empty for new tournaments (the only photo is in use as a
        // Daily too), but the running tournament is untouched.
        DailyChallenge::create(['challenge_id' => $challenge->id, 'challenge_date' => '2026-01-01', 'status' => 'archived']);

        $this->getJson('/api/leagues', $headers)->assertOk()->assertJsonPath('data.0.id', $league->id);
        $this->getJson("/api/leagues/{$league->id}", $headers)->assertOk();
        $this->getJson("/api/leagues/{$league->id}/current-round", $headers)->assertOk()
            ->assertJsonPath('has_current_round', true)
            ->assertJsonPath('current_round.challenge.id', $challenge->id);
        $this->getJson('/api/tournaments/availability', $headers)->assertOk()->assertJsonPath('available', false);
    }

    // ------------------------------------------------------------- eligibility

    public function test_daily_used_challenges_are_not_counted_as_eligible(): void
    {
        $sport = $this->sport();
        $this->fill($sport, 7);
        $extra = $this->challenge($sport, 'was-daily');
        DailyChallenge::create(['challenge_id' => $extra->id, 'challenge_date' => '2026-01-01', 'status' => 'archived']);
        [$user, $headers] = $this->auth();

        $this->getJson('/api/tournaments/availability?duration_days=7', $headers)->assertOk()
            ->assertJsonPath('available', true)->assertJsonPath('available_challenges', 7)->assertJsonPath('required', 7);
    }

    public function test_pack_only_and_daily_only_challenges_are_not_counted_as_eligible(): void
    {
        $sport = $this->sport();
        $this->fill($sport, 10, ['usage_pool' => Challenge::POOL_PACK]);
        $this->fill($sport, 10, ['usage_pool' => Challenge::POOL_DAILY]);
        $this->fill($sport, 3, ['usage_pool' => Challenge::POOL_GENERAL]);
        [$user, $headers] = $this->auth();

        $this->getJson('/api/tournaments/availability?duration_days=7', $headers)->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('available_challenges', 3)
            ->assertJsonPath('required', 7)
            ->assertJsonPath('message', __('messages.tournaments.temporarily_unavailable'));

        // Plenty of pack/daily content — the message stays tournament-specific.
        $this->assertUnavailableBody($this->postJson('/api/leagues', ['name' => 'Cup', 'duration_days' => 7], $headers), 7, 3);
    }

    public function test_draft_and_unready_challenges_are_not_counted(): void
    {
        $sport = $this->sport();
        $this->fill($sport, 7, ['status' => 'draft']);
        $this->fill($sport, 7, ['hidden_image_path' => null]);
        [$user, $headers] = $this->auth();

        $this->getJson('/api/tournaments/availability', $headers)->assertOk()
            ->assertJsonPath('available', false)->assertJsonPath('available_challenges', 0);
    }

    // ---------------------------------------------------------------- endpoint

    public function test_availability_endpoint_follows_the_sport_and_ignores_bad_durations(): void
    {
        $football = $this->sport('football');
        $tennis   = $this->sport('tennis');
        $this->fill($football, 7);
        $this->fill($tennis, 2);
        [$user, $headers] = $this->auth();

        $this->getJson('/api/tournaments/availability', $headers)->assertOk()
            ->assertJsonPath('available', true)->assertJsonPath('sport.slug', 'football')->assertJsonPath('duration_days', 7)
            ->assertJsonPath('message', null);
        $this->getJson('/api/tournaments/availability?sport=tennis', $headers)->assertOk()
            ->assertJsonPath('available', false)->assertJsonPath('available_challenges', 2)->assertJsonPath('sport.slug', 'tennis');
        // Unknown duration → the shortest allowed one, never a 500/422.
        $this->getJson('/api/tournaments/availability?duration_days=999', $headers)->assertOk()->assertJsonPath('required', 7);
        // The user's preferred sport is the default when no slug is given.
        $user->forceFill(['preferred_sport_id' => $tennis->id])->save();
        $this->app['auth']->forgetGuards(); // Sanctum caches the resolved user per test app
        $this->getJson('/api/tournaments/availability', $headers)->assertOk()->assertJsonPath('sport.slug', 'tennis');

        $this->app['auth']->forgetGuards(); // drop the cached token user so the anonymous call is really anonymous
        $this->getJson('/api/tournaments/availability')->assertUnauthorized();
    }

    public function test_copy_variant_is_configurable_and_localized(): void
    {
        config(['ballspot.tournaments.unavailable_copy' => 'next_month']);
        $this->assertSame(__('messages.tournaments.temporarily_unavailable_next_month'), TournamentAvailabilityService::message());
        $this->assertStringContainsString('next month', TournamentAvailabilityService::message());

        config(['ballspot.tournaments.unavailable_copy' => 'soon']);
        $this->assertSame('Tournaments are temporarily unavailable while we prepare new challenges. Please try again soon.', TournamentAvailabilityService::message());

        $sport = $this->sport();
        [$user, $headers] = $this->auth();
        // A signed-in user's own language wins over any header.
        $user->forceFill(['preferred_language' => 'nl'])->save();
        $this->postJson('/api/leagues', ['name' => 'Cup', 'duration_days' => 7], $headers)
            ->assertStatus(422)
            ->assertJsonPath('code', self::CODE)
            ->assertJsonPath('message', __('messages.tournaments.temporarily_unavailable', [], 'nl'));
        $this->assertNotSame(__('messages.tournaments.temporarily_unavailable', [], 'nl'), __('messages.tournaments.temporarily_unavailable', [], 'en'));
    }

    // ------------------------------------------------------------- diagnostics

    public function test_diagnostics_still_warns_when_the_tournament_pool_is_low(): void
    {
        $sport = $this->sport();
        $this->fill($sport, DiagnosticsService::TOURNAMENT_POOL_LOW - 1);

        $messages = array_column(app(DiagnosticsService::class)->snapshot()['warnings'], 'message');
        $this->assertTrue(collect($messages)->contains(fn ($m) => str_contains($m, 'tournament-eligible')), implode("\n", $messages));
    }
}
