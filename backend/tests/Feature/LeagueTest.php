<?php
namespace Tests\Feature;
use App\Models\Challenge;
use App\Models\League;
use App\Models\Sport;
use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LeagueTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): array
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        return [$user, ['Authorization' => "Bearer $token"]];
    }

    public function test_user_can_create_league(): void
    {
        $sport = Sport::create(['name' => 'Football', 'slug' => 'football']);
        Challenge::create(['sport_id' => $sport->id, 'title' => 'Test', 'hidden_image_path' => 'x.jpg',
            'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5, 'difficulty' => 'easy', 'status' => 'active']);

        [$user, $headers] = $this->actingAsUser();
        $response = $this->postJson('/api/leagues', [
            'name' => 'My League', 'duration_days' => 1, 'rounds_per_day' => 1,
        ], $headers);
        $response->assertStatus(201)->assertJsonFragment(['name' => 'My League']);
    }

    public function test_user_can_join_league_with_code(): void
    {
        $sport = Sport::create(['name' => 'Football', 'slug' => 'football']);
        [$owner, $ownerHeaders] = $this->actingAsUser();
        Challenge::create(['sport_id' => $sport->id, 'title' => 'T2', 'hidden_image_path' => 'x.jpg',
            'ball_x_ratio' => 0.5, 'ball_y_ratio' => 0.5, 'difficulty' => 'easy', 'status' => 'active']);

        $league = League::create([
            'name' => 'Join Me', 'join_code' => 'ABC123', 'owner_user_id' => $owner->id,
            'sport_id' => $sport->id, 'duration_days' => 1, 'rounds_per_day' => 1, 'status' => 'active',
        ]);

        [$joiner, $joinerHeaders] = $this->actingAsUser();
        $this->postJson('/api/leagues/join', ['join_code' => 'ABC123'], $joinerHeaders)
            ->assertOk()->assertJsonFragment(['name' => 'Join Me']);
    }
}
