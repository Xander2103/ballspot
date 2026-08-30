<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /admin/challenges splits into a main "Available challenges" section and a
 * collapsed "Used Daily challenges" <details> panel. Presentation only —
 * fairness / tournament eligibility logic is untouched.
 */
class AdminChallengeUsedDailySectionTest extends TestCase
{
    use RefreshDatabase;

    private const MAIN_MARKER = 'data-section="available"';
    private const USED_MARKER = 'data-section="used-daily"';

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function challenge(string $title, array $overrides = []): Challenge
    {
        $sport = Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football']);
        return Challenge::create(array_merge([
            'sport_id'          => $sport->id,
            'title'             => $title,
            'ball_x_ratio'      => 0.5,
            'ball_y_ratio'      => 0.5,
            'difficulty'        => 'easy',
            'status'            => 'active',
            'usage_pool'        => Challenge::POOL_GENERAL,
            'hidden_image_path' => "challenges/hidden/{$title}.jpg",
        ], $overrides));
    }

    private function markUsed(Challenge $c, string $date = '2026-08-01'): Challenge
    {
        DailyChallenge::create(['challenge_id' => $c->id, 'challenge_date' => $date, 'status' => 'archived']);
        return $c;
    }

    public function test_non_daily_used_challenges_render_in_main_section_and_used_ones_in_collapsed_panel(): void
    {
        $this->challenge('Fresh Photo');
        $this->markUsed($this->challenge('Old Daily Photo'));

        $res = $this->actingAs($this->admin())->get('/admin/challenges')->assertOk();

        // Main section comes first and contains only the fresh photo; the used
        // one is after the used-daily marker.
        $res->assertSeeInOrder([self::MAIN_MARKER, 'Fresh Photo', self::USED_MARKER, 'Old Daily Photo'], false);
        $res->assertSee('Used Daily challenges');
        $res->assertSee('These photos were already used as Daily Challenges and are excluded from new tournaments.');
        $res->assertSee('🔒 Used as Daily');
        $res->assertSee('row-daily-locked');

        // Fresh photo is NOT inside the used section.
        $html = $res->getContent();
        $usedSection = substr($html, strpos($html, self::USED_MARKER));
        $this->assertStringNotContainsString('Fresh Photo', $usedSection);
        $this->assertStringContainsString('Old Daily Photo', $usedSection);
    }

    public function test_used_daily_panel_is_collapsed_by_default_and_shows_count(): void
    {
        $this->challenge('Fresh Photo');
        $this->markUsed($this->challenge('Used A'), '2026-08-01');
        $this->markUsed($this->challenge('Used B'), '2026-08-02');

        $html = $this->actingAs($this->admin())->get('/admin/challenges')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<details[^>]*' . preg_quote(self::USED_MARKER, '/') . '[^>]*>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<details[^>]*' . preg_quote(self::USED_MARKER, '/') . '[^>]*\bopen\b/', $html);
        $this->assertStringContainsString('Used Daily challenges <span class="badge bg-danger">2</span>', $html);
    }

    public function test_used_as_daily_filter_opens_panel_and_shows_only_used_results(): void
    {
        $this->challenge('Fresh Photo');
        $this->markUsed($this->challenge('Used A'));

        $res = $this->actingAs($this->admin())->get('/admin/challenges?used_as_daily=yes')->assertOk();
        $html = $res->getContent();

        $this->assertMatchesRegularExpression('/<details[^>]*' . preg_quote(self::USED_MARKER, '/') . '[^>]*\bopen\b/', $html);
        $res->assertSee('Used A');
        $res->assertDontSee('Fresh Photo');
    }

    public function test_search_matching_only_used_daily_items_auto_opens_panel(): void
    {
        $this->challenge('Fresh Photo');
        $this->markUsed($this->challenge('Stadium Night'));

        $res = $this->actingAs($this->admin())->get('/admin/challenges?search=Stadium')->assertOk();
        $html = $res->getContent();

        $res->assertSee('Stadium Night');
        $res->assertDontSee('Fresh Photo');
        $this->assertMatchesRegularExpression('/<details[^>]*' . preg_quote(self::USED_MARKER, '/') . '[^>]*\bopen\b/', $html);
    }

    public function test_used_as_daily_not_used_filter_keeps_main_list_only(): void
    {
        $this->challenge('Fresh Photo');
        $this->markUsed($this->challenge('Used A'));

        $this->actingAs($this->admin())->get('/admin/challenges?used_as_daily=no')->assertOk()
            ->assertSee('Fresh Photo')
            ->assertDontSee('Used A');
    }

    public function test_used_daily_rows_keep_actions_and_tournament_blocked_badge(): void
    {
        $used = $this->markUsed($this->challenge('Used A'));

        $res = $this->actingAs($this->admin())->get('/admin/challenges')->assertOk();
        $pos = strpos($res->getContent(), self::USED_MARKER);
        $this->assertNotFalse($pos, 'used-daily section marker missing');
        $html = substr($res->getContent(), $pos);

        $this->assertStringContainsString("/admin/challenges/{$used->id}/edit", $html);
        $this->assertStringContainsString("/admin/challenges/{$used->id}/preview", $html);
        $this->assertStringContainsString("/admin/challenges/{$used->id}/status", $html);
        $this->assertStringContainsString('Archive', $html);
        $this->assertStringContainsString($used->tournamentEligibility()['label'], $html);

        // Fairness logic unchanged.
        $this->assertFalse($used->fresh()->isTournamentEligible());
        $this->assertSame(0, Challenge::tournamentEligible()->count());
        $this->assertSame(1, Challenge::dailyUsed()->count());
    }

    public function test_main_section_empty_state_does_not_hide_used_panel(): void
    {
        $this->markUsed($this->challenge('Used A'));

        $res = $this->actingAs($this->admin())->get('/admin/challenges')->assertOk();
        $res->assertSee('Used A');
        $res->assertSee('No available challenges');
    }
}
