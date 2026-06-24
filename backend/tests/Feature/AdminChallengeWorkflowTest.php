<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\ChallengeCategory;
use App\Models\DailyChallenge;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminChallengeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function makeChallenge(array $attrs = []): Challenge
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        return Challenge::create(array_merge([
            'sport_id'          => $sport->id,
            'title'             => 'Test Challenge',
            'difficulty'        => 'easy',
            'status'            => 'draft',
            'hidden_image_path' => 'challenges/hidden/test.jpg',
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
        ], $attrs));
    }

    // ----- Readiness helpers -----

    public function test_is_ready_true_when_all_fields_set(): void
    {
        $challenge = $this->makeChallenge();
        $this->assertTrue($challenge->isReady());
    }

    public function test_is_ready_false_when_hidden_image_missing(): void
    {
        $challenge = $this->makeChallenge(['hidden_image_path' => '']);
        $this->assertFalse($challenge->isReady());
    }

    public function test_is_ready_for_daily_requires_active_status(): void
    {
        $challenge = $this->makeChallenge(['status' => 'draft']);
        $this->assertFalse($challenge->isReadyForDaily());

        $challenge->update(['status' => 'active']);
        $this->assertTrue($challenge->isReadyForDaily());
    }

    public function test_is_demo_content_returns_true_for_known_titles(): void
    {
        $challenge = $this->makeChallenge(['title' => 'Corner Kick']);
        $this->assertTrue($challenge->isDemoContent());
    }

    public function test_is_demo_content_returns_false_for_custom_title(): void
    {
        $challenge = $this->makeChallenge(['title' => 'My Real Photo']);
        $this->assertFalse($challenge->isDemoContent());
    }

    // ----- Activation guard -----

    public function test_cannot_activate_challenge_without_hidden_image(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();
        $challenge = $this->makeChallenge(['hidden_image_path' => '', 'status' => 'draft']);

        $this->actingAs($admin)->patch("/admin/challenges/{$challenge->id}", [
            'title'       => 'No Image',
            'difficulty'  => 'easy',
            'status'      => 'active',
            'ball_x_ratio' => 0.5,
            'ball_y_ratio' => 0.5,
        ])->assertSessionHasErrors('status');

        $this->assertEquals('draft', $challenge->fresh()->status);
    }

    public function test_can_save_draft_challenge_without_hidden_image(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();
        $challenge = $this->makeChallenge(['hidden_image_path' => '', 'status' => 'draft']);

        $this->actingAs($admin)->patch("/admin/challenges/{$challenge->id}", [
            'title'        => 'Draft No Image',
            'difficulty'   => 'easy',
            'status'       => 'draft',
            'ball_x_ratio' => 0.5,
            'ball_y_ratio' => 0.5,
        ])->assertRedirect('/admin/challenges');

        $this->assertEquals('draft', $challenge->fresh()->status);
    }

    // ----- Quick status actions -----

    public function test_quick_status_archive_works(): void
    {
        $admin = $this->adminUser();
        $challenge = $this->makeChallenge(['status' => 'active']);

        $this->actingAs($admin)->post("/admin/challenges/{$challenge->id}/status", [
            'status' => 'archived',
        ])->assertRedirect();

        $this->assertEquals('archived', $challenge->fresh()->status);
    }

    public function test_quick_activate_blocked_without_hidden_image(): void
    {
        $admin = $this->adminUser();
        $challenge = $this->makeChallenge(['hidden_image_path' => '', 'status' => 'draft']);

        $this->actingAs($admin)->post("/admin/challenges/{$challenge->id}/status", [
            'status' => 'active',
        ])->assertRedirect();

        // Status must NOT change
        $this->assertEquals('draft', $challenge->fresh()->status);
        $this->actingAs($admin); // re-auth to check session
    }

    public function test_archive_does_not_delete_the_challenge(): void
    {
        $admin = $this->adminUser();
        $challenge = $this->makeChallenge(['status' => 'active']);

        $this->actingAs($admin)->post("/admin/challenges/{$challenge->id}/status", [
            'status' => 'archived',
        ]);

        $this->assertDatabaseHas('challenges', ['id' => $challenge->id, 'status' => 'archived']);
    }

    // ----- Preview route -----

    public function test_preview_route_loads_for_existing_challenge(): void
    {
        $admin = $this->adminUser();
        $challenge = $this->makeChallenge();

        $this->actingAs($admin)->get("/admin/challenges/{$challenge->id}/preview")
            ->assertOk()
            ->assertSee($challenge->title);
    }

    // ----- Ball position persists -----

    public function test_ball_position_persists_through_update(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();
        $challenge = $this->makeChallenge(['ball_x_ratio' => 0.3, 'ball_y_ratio' => 0.7]);

        $this->actingAs($admin)->patch("/admin/challenges/{$challenge->id}", [
            'title'        => 'Updated Title',
            'difficulty'   => 'hard',
            'status'       => 'draft',
            'ball_x_ratio' => 0.25,
            'ball_y_ratio' => 0.65,
        ])->assertRedirect('/admin/challenges');

        $fresh = $challenge->fresh();
        $this->assertEqualsWithDelta(0.25, $fresh->ball_x_ratio, 0.001);
        $this->assertEqualsWithDelta(0.65, $fresh->ball_y_ratio, 0.001);
    }

    // ----- Index filters -----

    public function test_index_filter_by_status(): void
    {
        $admin = $this->adminUser();
        $this->makeChallenge(['title' => 'Active One', 'status' => 'active']);
        $this->makeChallenge(['title' => 'Draft One',  'status' => 'draft']);

        $this->actingAs($admin)->get('/admin/challenges?status=active')
            ->assertSee('Active One')
            ->assertDontSee('Draft One');
    }

    public function test_index_filter_by_title_search(): void
    {
        $admin = $this->adminUser();
        $this->makeChallenge(['title' => 'Corner Kick Shot']);
        $this->makeChallenge(['title' => 'Penalty Area']);

        $this->actingAs($admin)->get('/admin/challenges?search=Corner')
            ->assertSee('Corner Kick Shot')
            ->assertDontSee('Penalty Area');
    }

    public function test_index_filter_no_reveal_image(): void
    {
        $admin = $this->adminUser();
        $this->makeChallenge(['title' => 'Has Reveal', 'original_image_path' => 'challenges/original/x.jpg']);
        $this->makeChallenge(['title' => 'No Reveal',  'original_image_path' => null]);

        $this->actingAs($admin)->get('/admin/challenges?has_reveal=no')
            ->assertSee('No Reveal')
            ->assertDontSee('Has Reveal');
    }

    // ----- Set as daily -----

    public function test_set_as_daily_creates_daily_challenge_record(): void
    {
        $admin = $this->adminUser();
        $challenge = $this->makeChallenge(['status' => 'active']);

        $this->actingAs($admin)->post("/admin/challenges/{$challenge->id}/set-as-daily", [
            'date' => '2026-12-01',
        ])->assertRedirect();

        $this->assertDatabaseHas('daily_challenges', [
            'challenge_id'   => $challenge->id,
        ]);
    }

    public function test_set_as_daily_blocked_when_not_active(): void
    {
        $admin = $this->adminUser();
        $challenge = $this->makeChallenge(['status' => 'draft']);

        $this->actingAs($admin)->post("/admin/challenges/{$challenge->id}/set-as-daily", [
            'date' => '2026-12-02',
        ])->assertRedirect();

        $this->assertDatabaseMissing('daily_challenges', ['challenge_id' => $challenge->id]);
    }
}
