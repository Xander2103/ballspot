<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_competition_page_shows_deleted_account_metric(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $deleted = User::factory()->create();
        $deleted->forceFill(['anonymized_at' => now(), 'friend_code' => null])->save();

        $this->actingAs($admin)
            ->get('/admin/competition')
            ->assertOk()
            ->assertSee('Deleted/anonymized accounts')
            ->assertSee('Active accounts: 1', false)
            ->assertSee('Deleted/anonymized accounts: 1', false);
    }
}
