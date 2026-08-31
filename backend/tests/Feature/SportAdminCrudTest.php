<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SportAdminCrudTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'name'          => 'Padel',
            'slug'          => 'padel',
            'emoji'         => '🎾',
            'object_name'   => 'ball',
            'primary_color' => '#ff8800',
            'sort_order'    => 9,
            'status'        => Sport::STATUS_COMING_SOON,
        ], $extra);
    }

    public function test_admin_can_create_sport(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/sports', $this->payload())
            ->assertRedirect('/admin/sports');

        $this->assertDatabaseHas('sports', [
            'slug'          => 'padel',
            'name'          => 'Padel',
            'object_name'   => 'ball',
            'primary_color' => '#ff8800',
            'sort_order'    => 9,
            'status'        => Sport::STATUS_COMING_SOON,
            'is_active'     => false,
        ]);
    }

    public function test_create_and_edit_pages_render(): void
    {
        $admin = $this->admin();
        $sport = Sport::create($this->payload());

        $this->actingAs($admin)->get('/admin/sports/create')->assertOk();
        $this->actingAs($admin)->get("/admin/sports/{$sport->id}/edit")->assertOk();
        // Index shows the New Sport button.
        $this->actingAs($admin)->get('/admin/sports')->assertOk()->assertSee('New Sport');
    }

    public function test_non_admin_cannot_create_sport(): void
    {
        $this->post('/admin/sports', $this->payload())->assertRedirect(route('admin.login'));

        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->post('/admin/sports', $this->payload())->assertForbidden();
        $this->assertDatabaseMissing('sports', ['slug' => 'padel']);
    }

    public function test_duplicate_slug_is_rejected(): void
    {
        Sport::create($this->payload());

        $this->actingAs($this->admin())
            ->post('/admin/sports', $this->payload(['name' => 'Padel Two']))
            ->assertSessionHasErrors('slug');

        $this->assertSame(1, Sport::where('slug', 'padel')->count());
    }

    public function test_invalid_status_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/sports', $this->payload(['status' => 'launched']))
            ->assertSessionHasErrors('status');
    }

    public function test_admin_can_edit_sport(): void
    {
        $sport = Sport::create($this->payload());

        $this->actingAs($this->admin())
            ->put("/admin/sports/{$sport->id}", $this->payload([
                'name'          => 'Padel Pro',
                'primary_color' => '#123456',
                'status'        => Sport::STATUS_ACTIVE,
            ]))
            ->assertRedirect('/admin/sports');

        $this->assertDatabaseHas('sports', [
            'id'            => $sport->id,
            'name'          => 'Padel Pro',
            'primary_color' => '#123456',
            'status'        => Sport::STATUS_ACTIVE,
            'is_active'     => true,
        ]);
    }

    public function test_football_cannot_be_edited_away_from_active(): void
    {
        $football = Sport::create([
            'name' => 'Football', 'slug' => 'football', 'status' => Sport::STATUS_ACTIVE,
        ]);

        $this->actingAs($this->admin())
            ->put("/admin/sports/{$football->id}", $this->payload([
                'name' => 'Football', 'slug' => 'football', 'status' => Sport::STATUS_HIDDEN,
            ]))
            ->assertRedirect('/admin/sports');

        $this->assertDatabaseHas('sports', ['id' => $football->id, 'status' => Sport::STATUS_ACTIVE]);
    }

    public function test_sport_with_challenges_cannot_be_deleted(): void
    {
        $sport = Sport::create($this->payload());
        Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => 'C',
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/test.jpg',
        ]);

        $this->actingAs($this->admin())
            ->delete("/admin/sports/{$sport->id}")
            ->assertRedirect('/admin/sports');

        $this->assertDatabaseHas('sports', ['id' => $sport->id]);
    }

    public function test_sport_without_challenges_can_be_deleted(): void
    {
        $sport = Sport::create($this->payload());

        $this->actingAs($this->admin())
            ->delete("/admin/sports/{$sport->id}")
            ->assertRedirect('/admin/sports');

        $this->assertDatabaseMissing('sports', ['id' => $sport->id]);
    }

    public function test_coming_soon_sport_is_visible_but_not_playable_in_api(): void
    {
        Sport::create($this->payload());
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $res = $this->withToken($token)->getJson('/api/sports');

        $res->assertOk();
        $entry = collect($res->json('data'))->firstWhere('slug', 'padel');
        $this->assertNotNull($entry);
        $this->assertFalse($entry['is_playable']);
        $this->assertTrue($entry['is_coming_soon']);
    }

    public function test_hidden_sport_is_not_shown_to_players(): void
    {
        Sport::create($this->payload(['status' => Sport::STATUS_HIDDEN]));
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $res = $this->withToken($token)->getJson('/api/sports');

        $res->assertOk();
        $this->assertNull(collect($res->json('data'))->firstWhere('slug', 'padel'));
    }
}
