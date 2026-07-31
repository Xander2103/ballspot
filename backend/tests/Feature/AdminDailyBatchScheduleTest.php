<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDailyBatchScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function makeReadyChallenge(string $title = 'Ready Challenge'): Challenge
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);

        return Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => $title,
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/test.jpg',
        ]);
    }

    /** @return array<int, string> dates in scheduling order for the given challenge ids */
    private function scheduledDates(): array
    {
        return DailyChallenge::orderBy('challenge_date')
            ->pluck('challenge_date')
            ->map(fn ($d) => $d instanceof \Carbon\CarbonInterface ? $d->toDateString() : (string) $d)
            ->all();
    }

    // ----- Batch scheduling -----

    public function test_batch_schedules_selected_challenges_on_consecutive_free_dates(): void
    {
        $admin = $this->adminUser();
        $a = $this->makeReadyChallenge('Challenge A');
        $b = $this->makeReadyChallenge('Challenge B');
        $c = $this->makeReadyChallenge('Challenge C');

        $start = today()->addDay()->toDateString();

        $this->actingAs($admin)->post('/admin/daily', [
            'challenge_ids' => [$a->id, $b->id, $c->id],
            'start_date'    => $start,
            'status'        => 'scheduled',
        ])->assertRedirect(route('admin.daily.index'));

        $this->assertDatabaseCount('daily_challenges', 3);
        $this->assertDatabaseHas('daily_challenges', [
            'challenge_id'   => $a->id,
            'challenge_date' => today()->addDay()->toDateString(),
        ]);
        $this->assertDatabaseHas('daily_challenges', [
            'challenge_id'   => $b->id,
            'challenge_date' => today()->addDays(2)->toDateString(),
        ]);
        $this->assertDatabaseHas('daily_challenges', [
            'challenge_id'   => $c->id,
            'challenge_date' => today()->addDays(3)->toDateString(),
        ]);
    }

    public function test_batch_skips_dates_already_occupied(): void
    {
        $admin = $this->adminUser();
        $taken = $this->makeReadyChallenge('Already Scheduled');
        $a = $this->makeReadyChallenge('Challenge A');
        $b = $this->makeReadyChallenge('Challenge B');

        // Day+1 is already occupied — the batch must jump over it.
        DailyChallenge::create([
            'challenge_id'   => $taken->id,
            'challenge_date' => today()->addDay()->toDateString(),
            'status'         => 'scheduled',
        ]);

        $this->actingAs($admin)->post('/admin/daily', [
            'challenge_ids' => [$a->id, $b->id],
            'start_date'    => today()->addDay()->toDateString(),
            'status'        => 'scheduled',
        ])->assertRedirect(route('admin.daily.index'));

        $this->assertDatabaseHas('daily_challenges', [
            'challenge_id'   => $a->id,
            'challenge_date' => today()->addDays(2)->toDateString(),
        ]);
        $this->assertDatabaseHas('daily_challenges', [
            'challenge_id'   => $b->id,
            'challenge_date' => today()->addDays(3)->toDateString(),
        ]);
        $this->assertDatabaseCount('daily_challenges', 3);
    }

    public function test_batch_defaults_to_first_free_date_when_no_start_date_given(): void
    {
        $admin = $this->adminUser();
        $taken = $this->makeReadyChallenge('Already Scheduled');
        $a = $this->makeReadyChallenge('Challenge A');

        DailyChallenge::create([
            'challenge_id'   => $taken->id,
            'challenge_date' => today()->toDateString(),
            'status'         => 'active',
        ]);

        $this->actingAs($admin)->post('/admin/daily', [
            'challenge_ids' => [$a->id],
            'status'        => 'scheduled',
        ])->assertRedirect(route('admin.daily.index'));

        $this->assertDatabaseHas('daily_challenges', [
            'challenge_id'   => $a->id,
            'challenge_date' => today()->addDay()->toDateString(),
        ]);
    }

    public function test_batch_respects_submitted_selection_order(): void
    {
        $admin = $this->adminUser();
        $a = $this->makeReadyChallenge('Challenge A');
        $b = $this->makeReadyChallenge('Challenge B');

        // B submitted first must land on the first free date.
        $this->actingAs($admin)->post('/admin/daily', [
            'challenge_ids' => [$b->id, $a->id],
            'start_date'    => today()->toDateString(),
            'status'        => 'scheduled',
        ])->assertRedirect(route('admin.daily.index'));

        $this->assertDatabaseHas('daily_challenges', [
            'challenge_id'   => $b->id,
            'challenge_date' => today()->toDateString(),
        ]);
        $this->assertDatabaseHas('daily_challenges', [
            'challenge_id'   => $a->id,
            'challenge_date' => today()->addDay()->toDateString(),
        ]);
    }

    public function test_selection_order_field_overrides_checkbox_order(): void
    {
        $admin = $this->adminUser();
        $a = $this->makeReadyChallenge('Challenge A');
        $b = $this->makeReadyChallenge('Challenge B');

        // Checkboxes always post in DOM order; the hidden field carries click order.
        $this->actingAs($admin)->post('/admin/daily', [
            'challenge_ids'   => [$a->id, $b->id],
            'selection_order' => "{$b->id},{$a->id}",
            'start_date'      => today()->toDateString(),
            'status'          => 'scheduled',
        ])->assertRedirect(route('admin.daily.index'));

        $this->assertDatabaseHas('daily_challenges', [
            'challenge_id'   => $b->id,
            'challenge_date' => today()->toDateString(),
        ]);
        $this->assertDatabaseHas('daily_challenges', [
            'challenge_id'   => $a->id,
            'challenge_date' => today()->addDay()->toDateString(),
        ]);
    }

    // ----- Single selection still works -----

    public function test_single_challenge_selection_still_creates_one_daily(): void
    {
        $admin = $this->adminUser();
        $a = $this->makeReadyChallenge('Challenge A');

        $this->actingAs($admin)->post('/admin/daily', [
            'challenge_ids' => [$a->id],
            'start_date'    => today()->toDateString(),
            'status'        => 'active',
        ])->assertRedirect(route('admin.daily.index'));

        $this->assertDatabaseCount('daily_challenges', 1);
        $this->assertDatabaseHas('daily_challenges', [
            'challenge_id'   => $a->id,
            'challenge_date' => today()->toDateString(),
            'status'         => 'active',
        ]);
    }

    // ----- No-reuse rule -----

    public function test_challenge_already_used_as_daily_cannot_be_scheduled_again(): void
    {
        $admin = $this->adminUser();
        $used = $this->makeReadyChallenge('Already Used');
        $fresh = $this->makeReadyChallenge('Fresh Challenge');

        // Used in the past — must never come back as a daily.
        DailyChallenge::create([
            'challenge_id'   => $used->id,
            'challenge_date' => today()->subDays(10)->toDateString(),
            'status'         => 'archived',
        ]);

        $this->actingAs($admin)->post('/admin/daily', [
            'challenge_ids' => [$used->id, $fresh->id],
            'start_date'    => today()->toDateString(),
            'status'        => 'scheduled',
        ])->assertRedirect(route('admin.daily.index'));

        // Only the fresh one is scheduled; the used one gets no second row.
        $this->assertEquals(
            1,
            DailyChallenge::where('challenge_id', $used->id)->count(),
            'A challenge already used as a daily must not be scheduled twice.'
        );
        $this->assertDatabaseHas('daily_challenges', [
            'challenge_id'   => $fresh->id,
            'challenge_date' => today()->toDateString(),
        ]);
    }

    public function test_skipped_challenges_are_reported_in_the_flash_message(): void
    {
        $admin = $this->adminUser();
        $used = $this->makeReadyChallenge('Already Used');
        $a = $this->makeReadyChallenge('Challenge A');
        $b = $this->makeReadyChallenge('Challenge B');

        DailyChallenge::create([
            'challenge_id'   => $used->id,
            'challenge_date' => today()->subDays(5)->toDateString(),
            'status'         => 'archived',
        ]);

        $response = $this->actingAs($admin)->post('/admin/daily', [
            'challenge_ids' => [$a->id, $used->id, $b->id],
            'start_date'    => today()->toDateString(),
            'status'        => 'scheduled',
        ]);

        $response->assertSessionHas('success', fn ($msg) => str_contains($msg, '2 daily challenges scheduled'));
        $response->assertSessionHas('warning', fn ($msg) => str_contains($msg, '1 skipped')
            && str_contains($msg, 'Already Used'));
    }

    public function test_duplicate_ids_in_one_submission_are_only_scheduled_once(): void
    {
        $admin = $this->adminUser();
        $a = $this->makeReadyChallenge('Challenge A');

        $this->actingAs($admin)->post('/admin/daily', [
            'challenge_ids' => [$a->id, $a->id],
            'start_date'    => today()->toDateString(),
            'status'        => 'scheduled',
        ])->assertRedirect(route('admin.daily.index'));

        $this->assertDatabaseCount('daily_challenges', 1);
    }

    public function test_set_as_daily_shortcut_rejects_an_already_used_challenge(): void
    {
        $admin = $this->adminUser();
        $used = $this->makeReadyChallenge('Already Used');

        DailyChallenge::create([
            'challenge_id'   => $used->id,
            'challenge_date' => today()->subDays(7)->toDateString(),
            'status'         => 'archived',
        ]);

        $this->actingAs($admin)->post("/admin/challenges/{$used->id}/set-as-daily", [
            'date' => today()->addDays(3)->toDateString(),
        ])->assertRedirect();

        $this->assertEquals(
            1,
            DailyChallenge::where('challenge_id', $used->id)->count(),
            'The set-as-daily shortcut must honour the single-use rule too.'
        );
    }

    // ----- Readiness gate -----

    public function test_unready_challenge_cannot_be_scheduled(): void
    {
        $admin = $this->adminUser();
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);

        $noBall = Challenge::create([
            'sport_id'          => $sport->id,
            'title'             => 'No Ball Position',
            'difficulty'        => 'easy',
            'status'            => 'active',
            'hidden_image_path' => 'challenges/hidden/test.jpg',
        ]);

        $this->actingAs($admin)->post('/admin/daily', [
            'challenge_ids' => [$noBall->id],
            'start_date'    => today()->toDateString(),
            'status'        => 'scheduled',
        ]);

        $this->assertDatabaseCount('daily_challenges', 0);
    }

    public function test_draft_challenge_cannot_be_scheduled(): void
    {
        $admin = $this->adminUser();
        $draft = $this->makeReadyChallenge('Draft Challenge');
        $draft->update(['status' => 'draft']);

        $this->actingAs($admin)->post('/admin/daily', [
            'challenge_ids' => [$draft->id],
            'start_date'    => today()->toDateString(),
            'status'        => 'scheduled',
        ]);

        $this->assertDatabaseCount('daily_challenges', 0);
    }

    public function test_empty_selection_is_rejected(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->post('/admin/daily', [
            'start_date' => today()->toDateString(),
            'status'     => 'scheduled',
        ])->assertSessionHasErrors('challenge_ids');

        $this->assertDatabaseCount('daily_challenges', 0);
    }

    public function test_unknown_challenge_id_is_rejected(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->post('/admin/daily', [
            'challenge_ids' => [999999],
            'start_date'    => today()->toDateString(),
            'status'        => 'scheduled',
        ])->assertSessionHasErrors('challenge_ids.0');

        $this->assertDatabaseCount('daily_challenges', 0);
    }

    // ----- Create page -----

    public function test_create_page_shows_selectable_and_used_columns(): void
    {
        $admin = $this->adminUser();
        $used = $this->makeReadyChallenge('Already Used');
        $this->makeReadyChallenge('Fresh Challenge');

        DailyChallenge::create([
            'challenge_id'   => $used->id,
            'challenge_date' => today()->subDays(3)->toDateString(),
            'status'         => 'archived',
        ]);

        $this->actingAs($admin)->get('/admin/daily/create')
            ->assertOk()
            ->assertSee('Used as daily')
            ->assertSee('Selectable')
            ->assertSee('Fresh Challenge')
            ->assertSee('Already Used');
    }

    public function test_create_page_only_offers_unused_ready_challenges_as_checkboxes(): void
    {
        $admin = $this->adminUser();
        $used = $this->makeReadyChallenge('Already Used');
        $fresh = $this->makeReadyChallenge('Fresh Challenge');

        DailyChallenge::create([
            'challenge_id'   => $used->id,
            'challenge_date' => today()->subDays(3)->toDateString(),
            'status'         => 'archived',
        ]);

        $html = $this->actingAs($admin)->get('/admin/daily/create')->assertOk()->getContent();

        $this->assertStringContainsString('value="' . $fresh->id . '"', $html);
        $this->assertStringNotContainsString(
            'name="challenge_ids[]" value="' . $used->id . '"',
            $html,
            'An already-used challenge must not be offered as a selectable checkbox.'
        );
    }
}
