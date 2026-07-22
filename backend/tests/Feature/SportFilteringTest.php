<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\League;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SportFilteringTest extends TestCase
{
    use RefreshDatabase;

    private function sport(string $slug, string $name, bool $active = true): Sport
    {
        return Sport::create([
            'name' => $name, 'slug' => $slug, 'emoji' => '⚽',
            'object_name' => 'ball', 'primary_color' => '#00c853', 'is_active' => $active,
        ]);
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
            'hidden_image_path' => 'challenges/hidden/' . $sport->slug . '.jpg',
        ]);
    }

    private function auth(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    // --- Daily respects selected sport ---

    public function test_daily_today_returns_challenge_for_matching_sport(): void
    {
        $football = $this->sport('football', 'Football');
        $challenge = $this->challenge($football, 'Football Daily');
        DailyChallenge::create(['challenge_id' => $challenge->id, 'challenge_date' => today()->toDateString(), 'status' => 'active']);

        $user = User::factory()->create(['preferred_sport_id' => $football->id]);

        $res = $this->withToken($this->auth($user))->getJson('/api/daily/today');
        $res->assertOk();
        $res->assertJsonPath('has_daily', true);
    }

    public function test_daily_today_returns_no_daily_for_non_matching_sport(): void
    {
        $football = $this->sport('football', 'Football');
        $tennis   = $this->sport('tennis', 'Tennis');
        $challenge = $this->challenge($football, 'Football Daily');
        DailyChallenge::create(['challenge_id' => $challenge->id, 'challenge_date' => today()->toDateString(), 'status' => 'active']);

        // User prefers tennis; today's daily is football -> clean no_daily for tennis.
        $user = User::factory()->create(['preferred_sport_id' => $tennis->id]);

        $res = $this->withToken($this->auth($user))->getJson('/api/daily/today');
        $res->assertOk();
        $res->assertJsonPath('has_daily', false);
        $res->assertJsonPath('reason', 'no_daily_challenge');
        $res->assertJsonPath('sport.slug', 'tennis');
    }

    public function test_daily_today_sport_query_overrides_preference(): void
    {
        $football = $this->sport('football', 'Football');
        $tennis   = $this->sport('tennis', 'Tennis');
        $challenge = $this->challenge($football, 'Football Daily');
        DailyChallenge::create(['challenge_id' => $challenge->id, 'challenge_date' => today()->toDateString(), 'status' => 'active']);

        $user = User::factory()->create(['preferred_sport_id' => $football->id]);

        $res = $this->withToken($this->auth($user))->getJson('/api/daily/today?sport=tennis');
        $res->assertOk();
        $res->assertJsonPath('has_daily', false);
        $res->assertJsonPath('sport.slug', 'tennis');
    }

    public function test_daily_today_defaults_to_football_without_preference(): void
    {
        $football = $this->sport('football', 'Football');
        $challenge = $this->challenge($football, 'Football Daily');
        DailyChallenge::create(['challenge_id' => $challenge->id, 'challenge_date' => today()->toDateString(), 'status' => 'active']);

        $user = User::factory()->create(); // no preferred sport

        $res = $this->withToken($this->auth($user))->getJson('/api/daily/today');
        $res->assertOk();
        $res->assertJsonPath('has_daily', true);
    }

    // --- Tournament creation uses sport ---

    public function test_tournament_uses_explicit_sport(): void
    {
        $football = $this->sport('football', 'Football');
        $tennis   = $this->sport('tennis', 'Tennis');
        $user = User::factory()->create();

        $res = $this->withToken($this->auth($user))->postJson('/api/leagues', [
            'name' => 'My Cup', 'duration_days' => 1, 'rounds_per_day' => 1, 'sport_id' => $tennis->id,
        ]);

        $res->assertCreated();
        $res->assertJsonPath('data.sport.slug', 'tennis');
    }

    public function test_tournament_defaults_to_preferred_sport(): void
    {
        $football = $this->sport('football', 'Football');
        $tennis   = $this->sport('tennis', 'Tennis');
        $user = User::factory()->create(['preferred_sport_id' => $tennis->id]);

        $res = $this->withToken($this->auth($user))->postJson('/api/leagues', [
            'name' => 'My Cup', 'duration_days' => 1, 'rounds_per_day' => 1,
        ]);

        $res->assertCreated();
        $res->assertJsonPath('data.sport.slug', 'tennis');
    }

    public function test_tournament_defaults_to_football_without_preference(): void
    {
        $football = $this->sport('football', 'Football');
        $user = User::factory()->create();

        $res = $this->withToken($this->auth($user))->postJson('/api/leagues', [
            'name' => 'My Cup', 'duration_days' => 1, 'rounds_per_day' => 1,
        ]);

        $res->assertCreated();
        $res->assertJsonPath('data.sport.slug', 'football');
    }

    public function test_tournament_rounds_only_use_tournament_sport_challenges(): void
    {
        $football = $this->sport('football', 'Football');
        $tennis   = $this->sport('tennis', 'Tennis');
        $footballChallenge = $this->challenge($football, 'Football One');
        $tennisChallenge   = $this->challenge($tennis, 'Tennis One');

        $user = User::factory()->create();
        $token = $this->auth($user);

        $create = $this->withToken($token)->postJson('/api/leagues', [
            'name' => 'Tennis Cup', 'duration_days' => 1, 'rounds_per_day' => 1, 'sport_id' => $tennis->id,
        ]);
        $leagueId = $create->json('data.id');

        $this->withToken($token)->postJson("/api/leagues/{$leagueId}/start")->assertOk();

        $league = League::with('rounds.challenge')->find($leagueId);
        foreach ($league->rounds as $round) {
            $this->assertSame($tennis->id, $round->challenge->sport_id);
        }
    }

    // --- Schedule command --sport ---

    public function test_schedule_command_filters_by_sport(): void
    {
        $football = $this->sport('football', 'Football');
        $tennis   = $this->sport('tennis', 'Tennis');
        $this->challenge($football, 'Football One');
        $tennisChallenge = $this->challenge($tennis, 'Tennis One');

        $this->artisan('ballspot:schedule-daily-challenges', ['--sport' => 'tennis', '--days' => 1])
            ->assertSuccessful();

        $daily = DailyChallenge::with('challenge')->first();
        $this->assertNotNull($daily);
        $this->assertSame($tennisChallenge->id, $daily->challenge_id);
        $this->assertSame($tennis->id, $daily->challenge->sport_id);
    }

    public function test_schedule_command_rejects_unknown_sport(): void
    {
        $this->sport('football', 'Football');

        $this->artisan('ballspot:schedule-daily-challenges', ['--sport' => 'quidditch', '--days' => 1])
            ->assertFailed();
    }
}
