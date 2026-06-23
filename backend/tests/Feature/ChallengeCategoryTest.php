<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\ChallengeCategory;
use App\Models\League;
use App\Models\LeagueMember;
use App\Models\LeagueRound;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChallengeCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function makeSport(): Sport
    {
        return Sport::create(['name' => 'Football', 'slug' => 'football']);
    }

    private function makeCategory(Sport $sport, array $overrides = []): ChallengeCategory
    {
        return ChallengeCategory::create(array_merge([
            'sport_id'   => $sport->id,
            'name'       => 'General',
            'slug'       => 'general',
            'sort_order' => 0,
            'is_active'  => true,
        ], $overrides));
    }

    public function test_admin_can_create_challenge_with_category(): void
    {
        $sport    = $this->makeSport();
        $category = $this->makeCategory($sport);
        $admin    = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->post('/admin/challenges', [
            'title'                 => 'Test Corner',
            'difficulty'            => 'easy',
            'status'                => 'active',
            'challenge_category_id' => $category->id,
            'ball_x_ratio'          => '0.5',
            'ball_y_ratio'          => '0.5',
            'hidden_image'          => \Illuminate\Http\UploadedFile::fake()->image('test.jpg'),
        ]);

        $response->assertRedirect('/admin/challenges');
        $this->assertDatabaseHas('challenges', [
            'title'                 => 'Test Corner',
            'challenge_category_id' => $category->id,
        ]);
    }

    public function test_admin_can_update_challenge_category(): void
    {
        $sport     = $this->makeSport();
        $cat1      = $this->makeCategory($sport, ['name' => 'General', 'slug' => 'general']);
        $cat2      = $this->makeCategory($sport, ['name' => 'Penalties', 'slug' => 'penalties']);
        $challenge = Challenge::create([
            'sport_id'              => $sport->id,
            'challenge_category_id' => $cat1->id,
            'title'                 => 'Penalty Shot',
            'hidden_image_path'     => 'x.jpg',
            'ball_x_ratio'          => 0.5,
            'ball_y_ratio'          => 0.5,
            'difficulty'            => 'medium',
            'status'                => 'active',
        ]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch("/admin/challenges/{$challenge->id}", [
            'title'                 => 'Penalty Shot',
            'difficulty'            => 'medium',
            'status'                => 'active',
            'challenge_category_id' => $cat2->id,
            'ball_x_ratio'          => '0.5',
            'ball_y_ratio'          => '0.5',
        ]);

        $this->assertDatabaseHas('challenges', [
            'id'                    => $challenge->id,
            'challenge_category_id' => $cat2->id,
        ]);
    }

    public function test_current_round_includes_category_in_challenge(): void
    {
        $sport    = $this->makeSport();
        $category = $this->makeCategory($sport, ['name' => 'Corner Kicks', 'slug' => 'corner-kicks']);
        $challenge = Challenge::create([
            'sport_id'              => $sport->id,
            'challenge_category_id' => $category->id,
            'title'                 => 'Corner Test',
            'hidden_image_path'     => 'x.jpg',
            'ball_x_ratio'          => 0.5,
            'ball_y_ratio'          => 0.5,
            'difficulty'            => 'easy',
            'status'                => 'active',
        ]);
        $user   = User::factory()->create();
        $token  = $user->createToken('test')->plainTextToken;
        $league = League::create([
            'name'           => 'L',
            'join_code'      => 'CATTEST',
            'owner_user_id'  => $user->id,
            'sport_id'       => $sport->id,
            'duration_days'  => 1,
            'rounds_per_day' => 1,
            'status'         => 'active',
        ]);
        LeagueMember::create(['league_id' => $league->id, 'user_id' => $user->id, 'joined_at' => now()]);
        LeagueRound::create([
            'league_id'    => $league->id,
            'challenge_id' => $challenge->id,
            'round_number' => 1,
            'status'       => 'open',
        ]);

        $response = $this->getJson("/api/leagues/{$league->id}/current-round", [
            'Authorization' => "Bearer $token",
        ]);

        $response->assertOk();
        $cat = $response->json('current_round.challenge.category');
        $this->assertNotNull($cat, 'category should be present in current-round challenge');
        $this->assertEquals('Corner Kicks', $cat['name']);
        $this->assertEquals('corner-kicks', $cat['slug']);
    }

    public function test_current_round_category_is_null_when_challenge_has_no_category(): void
    {
        $sport     = $this->makeSport();
        $challenge = Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => 'No Cat',
            'hidden_image_path' => 'x.jpg',
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
        ]);
        $user   = User::factory()->create();
        $token  = $user->createToken('test')->plainTextToken;
        $league = League::create([
            'name'           => 'L2',
            'join_code'      => 'NOCAT1',
            'owner_user_id'  => $user->id,
            'sport_id'       => $sport->id,
            'duration_days'  => 1,
            'rounds_per_day' => 1,
            'status'         => 'active',
        ]);
        LeagueMember::create(['league_id' => $league->id, 'user_id' => $user->id, 'joined_at' => now()]);
        LeagueRound::create([
            'league_id'    => $league->id,
            'challenge_id' => $challenge->id,
            'round_number' => 1,
            'status'       => 'open',
        ]);

        $response = $this->getJson("/api/leagues/{$league->id}/current-round", [
            'Authorization' => "Bearer $token",
        ]);

        $response->assertOk();
        $this->assertNull($response->json('current_round.challenge.category'));
    }
}
