<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\ChallengeSubcategory;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubcategoryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function sport(): Sport
    {
        return Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
    }

    private function challenge(): Challenge
    {
        return Challenge::create([
            'sport_id'          => $this->sport()->id,
            'title'             => 'Sub Test',
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/test.jpg',
        ]);
    }

    public function test_admin_can_create_a_subcategory(): void
    {
        $this->actingAs($this->admin())->post('/admin/subcategories', [
            'name'      => 'FC Barcelona',
            'type'      => 'club',
            'sport_id'  => $this->sport()->id,
            'is_active' => '1',
        ])->assertRedirect('/admin/subcategories');

        $this->assertDatabaseHas('challenge_subcategories', [
            'name' => 'FC Barcelona',
            'slug' => 'fc-barcelona',
            'type' => 'club',
        ]);
    }

    public function test_admin_pages_render(): void
    {
        $admin = $this->admin();
        $sub = ChallengeSubcategory::create(['name' => 'Render', 'slug' => 'render', 'type' => 'team']);

        $this->actingAs($admin)->get('/admin/subcategories')->assertOk();
        $this->actingAs($admin)->get('/admin/subcategories/create')->assertOk();
        $this->actingAs($admin)->get("/admin/subcategories/{$sub->id}/edit")->assertOk();

        // Challenge create/edit forms now include the subcategories multi-select.
        $challenge = $this->challenge();
        $this->actingAs($admin)->get('/admin/challenges/create')->assertOk();
        $this->actingAs($admin)->get("/admin/challenges/{$challenge->id}/edit")->assertOk();
    }

    public function test_invalid_type_is_rejected(): void
    {
        $this->actingAs($this->admin())->post('/admin/subcategories', [
            'name' => 'Bad',
            'type' => 'not-a-type',
        ])->assertSessionHasErrors('type');
    }

    public function test_non_admin_cannot_manage_subcategories(): void
    {
        // Guest → redirected to admin login.
        $this->get('/admin/subcategories')->assertRedirect(route('admin.login'));

        // Authenticated non-admin → forbidden.
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->get('/admin/subcategories')->assertForbidden();
        $this->actingAs($user)->post('/admin/subcategories', [
            'name' => 'X', 'type' => 'team',
        ])->assertForbidden();

        $this->assertDatabaseCount('challenge_subcategories', 0);
    }

    public function test_challenge_can_be_assigned_multiple_subcategories(): void
    {
        $challenge = $this->challenge();
        $a = ChallengeSubcategory::create(['name' => 'Belgium', 'slug' => 'belgium', 'type' => 'country']);
        $b = ChallengeSubcategory::create(['name' => 'Pro League', 'slug' => 'pro-league', 'type' => 'league']);

        $challenge->subcategories()->sync([$a->id, $b->id]);

        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            $challenge->fresh()->subcategories->pluck('id')->all()
        );
        // Removing the subcategory detaches only — the challenge survives.
        $a->delete();
        $this->assertDatabaseHas('challenges', ['id' => $challenge->id]);
        $this->assertSame([$b->id], $challenge->fresh()->subcategories->pluck('id')->all());
    }

    public function test_active_scope_excludes_inactive_subcategories(): void
    {
        ChallengeSubcategory::create(['name' => 'On', 'slug' => 'on', 'type' => 'team', 'is_active' => true]);
        ChallengeSubcategory::create(['name' => 'Off', 'slug' => 'off', 'type' => 'team', 'is_active' => false]);

        $active = ChallengeSubcategory::active()->pluck('name')->all();

        $this->assertContains('On', $active);
        $this->assertNotContains('Off', $active);
    }

    public function test_slug_is_unique_within_sport_and_type(): void
    {
        $admin = $this->admin();
        $sport = $this->sport();

        $this->actingAs($admin)->post('/admin/subcategories', ['name' => 'Legends', 'type' => 'team', 'sport_id' => $sport->id]);
        $this->actingAs($admin)->post('/admin/subcategories', ['name' => 'Legends', 'type' => 'team', 'sport_id' => $sport->id]);

        $slugs = ChallengeSubcategory::where('type', 'team')->pluck('slug')->all();
        $this->assertContains('legends', $slugs);
        $this->assertContains('legends-2', $slugs); // de-duplicated
    }
}
