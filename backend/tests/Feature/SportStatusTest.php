<?php

namespace Tests\Feature;

use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SportStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeSport(string $slug, string $name, string $status): Sport
    {
        return Sport::create([
            'name' => $name, 'slug' => $slug, 'emoji' => '⚽',
            'object_name' => 'ball', 'primary_color' => '#00c853', 'status' => $status,
        ]);
    }

    public function test_tournament_cannot_be_created_for_coming_soon_sport(): void
    {
        $this->makeSport('football', 'Football', Sport::STATUS_ACTIVE);
        $golf = $this->makeSport('golf', 'Golf', Sport::STATUS_COMING_SOON);

        $token = User::factory()->create()->createToken('test')->plainTextToken;

        $res = $this->withToken($token)->postJson('/api/leagues', [
            'name' => 'Golf Cup', 'duration_days' => 1, 'rounds_per_day' => 1, 'sport_id' => $golf->id,
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors('sport_id');
    }

    public function test_tournament_cannot_be_created_for_hidden_sport(): void
    {
        $this->makeSport('football', 'Football', Sport::STATUS_ACTIVE);
        $hidden = $this->makeSport('cricket', 'Cricket', Sport::STATUS_HIDDEN);

        $token = User::factory()->create()->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/leagues', [
            'name' => 'X', 'duration_days' => 1, 'rounds_per_day' => 1, 'sport_id' => $hidden->id,
        ])->assertStatus(422);
    }

    public function test_setting_status_keeps_is_active_in_sync(): void
    {
        $sport = $this->makeSport('golf', 'Golf', Sport::STATUS_COMING_SOON);
        $this->assertFalse($sport->is_active);

        $sport->update(['status' => Sport::STATUS_ACTIVE]);
        $this->assertTrue($sport->fresh()->is_active);

        $sport->update(['status' => Sport::STATUS_HIDDEN]);
        $this->assertFalse($sport->fresh()->is_active);
    }

    public function test_admin_can_update_sport_status(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $golf  = $this->makeSport('golf', 'Golf', Sport::STATUS_COMING_SOON);

        $this->actingAs($admin)
            ->post("/admin/sports/{$golf->id}/status", ['status' => Sport::STATUS_ACTIVE])
            ->assertRedirect('/admin/sports');

        $this->assertSame(Sport::STATUS_ACTIVE, $golf->fresh()->status);
        $this->assertTrue($golf->fresh()->is_active);
    }

    public function test_admin_cannot_hide_football(): void
    {
        $admin    = User::factory()->create(['is_admin' => true]);
        $football = $this->makeSport('football', 'Football', Sport::STATUS_ACTIVE);

        $this->actingAs($admin)
            ->post("/admin/sports/{$football->id}/status", ['status' => Sport::STATUS_HIDDEN])
            ->assertRedirect('/admin/sports');

        // Still active — football is protected.
        $this->assertSame(Sport::STATUS_ACTIVE, $football->fresh()->status);
    }

    public function test_admin_rejects_invalid_status(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $golf  = $this->makeSport('golf', 'Golf', Sport::STATUS_COMING_SOON);

        $this->actingAs($admin)
            ->post("/admin/sports/{$golf->id}/status", ['status' => 'bogus'])
            ->assertSessionHasErrors('status');
    }
}
