<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\League;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnePhotoPerDayTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): array
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        return [$user, ['Authorization' => "Bearer $token"]];
    }

    private function sportWithChallenges(int $count = 10): Sport
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        for ($i = 0; $i < $count; $i++) {
            Challenge::create([
                'sport_id'          => $sport->id,
                'title'             => "Challenge {$i}",
                'ball_x_ratio'      => 0.5,
                'ball_y_ratio'      => 0.5,
                'difficulty'        => 'easy',
                'status'            => 'active',
                'hidden_image_path' => "challenges/hidden/test{$i}.jpg",
            ]);
        }
        return $sport;
    }

    public function test_malicious_rounds_per_day_is_forced_to_one(): void
    {
        $this->sportWithChallenges();
        [$user, $headers] = $this->actingAsUser();

        $this->postJson('/api/leagues', [
            'name' => 'Cheaty', 'duration_days' => 7, 'rounds_per_day' => 3,
        ], $headers)->assertStatus(201);

        $this->assertSame(1, League::latest('id')->first()->rounds_per_day);
    }

    public function test_missing_rounds_per_day_is_accepted_and_defaults_to_one(): void
    {
        $this->sportWithChallenges();
        [$user, $headers] = $this->actingAsUser();

        $this->postJson('/api/leagues', [
            'name' => 'Clean', 'duration_days' => 7,
        ], $headers)->assertStatus(201);

        $this->assertSame(1, League::latest('id')->first()->rounds_per_day);
    }

    public function test_duration_7_with_malicious_rounds_per_day_generates_exactly_7_rounds_on_start(): void
    {
        $this->sportWithChallenges();
        [$user, $headers] = $this->actingAsUser();

        $leagueId = $this->postJson('/api/leagues', [
            'name' => 'ThreeDays', 'duration_days' => 7, 'rounds_per_day' => 3,
        ], $headers)->json('data.id');

        $this->postJson("/api/leagues/{$leagueId}/start", [], $headers)->assertOk();

        $this->assertSame(7, League::find($leagueId)->rounds()->count());
    }

    public function test_duration_7_generates_exactly_7_rounds(): void
    {
        $this->sportWithChallenges();
        [$user, $headers] = $this->actingAsUser();

        $leagueId = $this->postJson('/api/leagues', [
            'name' => 'OneDay', 'duration_days' => 7,
        ], $headers)->json('data.id');

        $this->postJson("/api/leagues/{$leagueId}/start", [], $headers)->assertOk();

        $this->assertSame(7, League::find($leagueId)->rounds()->count());
    }

    public function test_existing_league_with_3_rounds_per_day_still_serves_rounds(): void
    {
        // Legacy tournaments created before this rule keep rounds_per_day = 3
        // and must keep working (production data is never rewritten).
        $sport = $this->sportWithChallenges();
        [$user, $headers] = $this->actingAsUser();

        $league = League::create([
            'name'           => 'Legacy',
            'join_code'      => 'LEGACY',
            'owner_user_id'  => $user->id,
            'sport_id'       => $sport->id,
            'duration_days'  => 3,
            'rounds_per_day' => 3,
            'status'         => 'lobby',
        ]);
        $league->members()->attach($user->id, ['joined_at' => now()]);

        $this->postJson("/api/leagues/{$league->id}/start", [], $headers)->assertOk();
        $this->assertSame(9, $league->rounds()->count()); // legacy math preserved

        $this->getJson("/api/leagues/{$league->id}/current-round", $headers)
            ->assertOk()
            ->assertJsonPath('rounds_per_day', 3);
    }
}
