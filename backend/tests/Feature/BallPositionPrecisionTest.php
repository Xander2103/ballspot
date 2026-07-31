<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BallPositionPrecisionTest extends TestCase
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

    // ----- Rounding on write -----

    public function test_store_rounds_ball_ratios_to_three_decimals(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();
        // store() falls back to the football sport when no sport_id is posted.
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);

        $this->actingAs($admin)->post('/admin/challenges', [
            'sport_id'      => $sport->id,
            'title'         => 'Precision Challenge',
            'difficulty'    => 'easy',
            'status'        => 'draft',
            'ball_x_ratio'  => '0.5150849',
            'ball_y_ratio'  => '0.8359123',
            'hidden_image'  => UploadedFile::fake()->image('hidden.jpg'),
        ])->assertRedirect('/admin/challenges');

        $challenge = Challenge::where('title', 'Precision Challenge')->firstOrFail();

        $this->assertEquals(0.515, (float) $challenge->ball_x_ratio);
        $this->assertEquals(0.836, (float) $challenge->ball_y_ratio);
    }

    public function test_update_rounds_ball_ratios_to_three_decimals(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();
        $challenge = $this->makeChallenge();

        $this->actingAs($admin)->patch("/admin/challenges/{$challenge->id}", [
            'title'        => 'Test Challenge',
            'difficulty'   => 'easy',
            'status'       => 'draft',
            'ball_x_ratio' => '0.5150849',
            'ball_y_ratio' => '0.8359123',
        ])->assertRedirect('/admin/challenges');

        $challenge->refresh();

        $this->assertEquals(0.515, (float) $challenge->ball_x_ratio);
        $this->assertEquals(0.836, (float) $challenge->ball_y_ratio);
    }

    // ----- Comma decimals -----

    public function test_comma_decimal_input_is_accepted_and_normalized(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();
        $challenge = $this->makeChallenge();

        // A Dutch-locale browser (or hand typing) can submit "0,515".
        $this->actingAs($admin)->patch("/admin/challenges/{$challenge->id}", [
            'title'        => 'Test Challenge',
            'difficulty'   => 'easy',
            'status'       => 'draft',
            'ball_x_ratio' => '0,515',
            'ball_y_ratio' => '0,836',
        ])->assertRedirect('/admin/challenges');

        $challenge->refresh();

        $this->assertEquals(0.515, (float) $challenge->ball_x_ratio);
        $this->assertEquals(0.836, (float) $challenge->ball_y_ratio);
    }

    // ----- Range validation still holds -----

    public function test_ratio_above_one_is_still_rejected(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();
        $challenge = $this->makeChallenge();

        $this->actingAs($admin)->patch("/admin/challenges/{$challenge->id}", [
            'title'        => 'Test Challenge',
            'difficulty'   => 'easy',
            'status'       => 'draft',
            'ball_x_ratio' => '1.5',
            'ball_y_ratio' => '0.5',
        ])->assertSessionHasErrors('ball_x_ratio');

        $this->assertEquals(0.5, (float) $challenge->fresh()->ball_x_ratio);
    }

    public function test_negative_ratio_is_still_rejected(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();
        $challenge = $this->makeChallenge();

        $this->actingAs($admin)->patch("/admin/challenges/{$challenge->id}", [
            'title'        => 'Test Challenge',
            'difficulty'   => 'easy',
            'status'       => 'draft',
            'ball_x_ratio' => '-0.1',
            'ball_y_ratio' => '0.5',
        ])->assertSessionHasErrors('ball_x_ratio');
    }

    public function test_boundary_values_zero_and_one_are_accepted(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();
        $challenge = $this->makeChallenge();

        $this->actingAs($admin)->patch("/admin/challenges/{$challenge->id}", [
            'title'        => 'Test Challenge',
            'difficulty'   => 'easy',
            'status'       => 'draft',
            'ball_x_ratio' => '0',
            'ball_y_ratio' => '1',
        ])->assertRedirect('/admin/challenges');

        $challenge->refresh();

        $this->assertEquals(0.0, (float) $challenge->ball_x_ratio);
        $this->assertEquals(1.0, (float) $challenge->ball_y_ratio);
    }

    // ----- Picker precision in the admin views -----

    public function test_create_view_picker_does_not_write_four_decimals(): void
    {
        $admin = $this->adminUser();

        $html = $this->actingAs($admin)->get('/admin/challenges/create')->assertOk()->getContent();

        $this->assertStringNotContainsString('toFixed(4)', $html);
    }

    public function test_edit_view_picker_does_not_write_four_decimals(): void
    {
        $admin = $this->adminUser();
        $challenge = $this->makeChallenge();

        $html = $this->actingAs($admin)->get("/admin/challenges/{$challenge->id}/edit")->assertOk()->getContent();

        $this->assertStringNotContainsString('toFixed(4)', $html);
    }
}
