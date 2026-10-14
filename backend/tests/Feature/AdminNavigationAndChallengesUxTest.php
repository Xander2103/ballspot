<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v1.9.14 admin UX sprint: the shared two-level navigation and the Challenges
 * page redesign. Presentation only — every assertion here is about what the
 * admin sees; pools, eligibility, scoring and routes are untouched.
 */
class AdminNavigationAndChallengesUxTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'name' => 'Ops Admin']);
    }

    private function sport(): Sport
    {
        return Sport::firstOrCreate(['slug' => 'football'], ['name' => 'Football', 'status' => Sport::STATUS_ACTIVE]);
    }

    private function challenge(string $title, array $overrides = []): Challenge
    {
        return Challenge::create(array_merge([
            'sport_id'          => $this->sport()->id,
            'title'             => $title,
            'ball_x_ratio'      => 0.57,
            'ball_y_ratio'      => 0.14,
            'difficulty'        => 'medium',
            'status'            => 'active',
            'usage_pool'        => Challenge::POOL_GENERAL,
            'hidden_image_path' => 'challenges/hidden/' . str_replace(' ', '-', strtolower($title)) . '.jpg',
        ], $overrides));
    }

    // ------------------------------------------------------------------
    // Navigation shell
    // ------------------------------------------------------------------

    public function test_primary_and_secondary_navigation_follow_the_current_route(): void
    {
        $admin = $this->admin();
        $cases = [
            // url => [primary group, secondary page or null when the group has no sub-pages]
            '/admin/challenges'    => ['Challenges', null],
            '/admin/categories'    => ['Content', 'Categories'],
            '/admin/subcategories' => ['Content', 'Subcategories'],
            '/admin/packs'         => ['Content', 'Packs'],
            '/admin/sports'        => ['Content', 'Sports'],
            '/admin/daily'         => ['Game', 'Daily'],
            '/admin/competition'   => ['Game', 'Competition'],
            '/admin/notifications' => ['Communication', 'Notifications'],
            '/admin/notices'       => ['Communication', 'Notices'],
            '/admin/settings'      => ['System', 'Settings'],
            '/admin/diagnostics'   => ['System', 'Diagnostics'],
            '/admin/growth'        => ['System', 'Growth'],
            '/admin/media-export'  => ['System', 'Media Export'],
        ];

        foreach ($cases as $url => [$group, $page]) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();

            $this->assertMatchesRegularExpression(
                '/<a class="admin-nav-link active"[^>]*>' . preg_quote($group, '/') . '<\/a>/',
                $html, "{$url}: primary group {$group} should be active",
            );
            $this->assertSame(1, preg_match_all('/admin-nav-link active/', $html), "{$url}: exactly one active primary item");

            if ($page === null) {
                $this->assertStringNotContainsString('class="admin-subnav', $html, "{$url}: no secondary bar for a group without sub-pages");
            } else {
                $this->assertMatchesRegularExpression(
                    '/<a class="admin-subnav-link active"[^>]*>' . preg_quote($page, '/') . '<\/a>/',
                    $html, "{$url}: secondary page {$page} should be active",
                );
                $this->assertSame(1, preg_match_all('/admin-subnav-link active/', $html), "{$url}: exactly one active secondary item");
            }
        }
    }

    public function test_navigation_keeps_every_existing_admin_url_and_shows_the_admin_name(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin/categories')->assertOk()->getContent();

        foreach (['/admin/challenges', '/admin/categories', '/admin/subcategories', '/admin/packs', '/admin/sports', '/admin/daily',
                  '/admin/competition', '/admin/notifications', '/admin/notices', '/admin/settings', '/admin/diagnostics', '/admin/media-export'] as $url) {
            // The primary bar links group landing pages; the secondary bar links the rest.
            $linked = str_contains($html, 'href="' . $url . '"');
            $inAnotherGroup = in_array($url, ['/admin/competition', '/admin/notices', '/admin/diagnostics', '/admin/media-export'], true);
            $this->assertTrue($linked || $inAnotherGroup, "{$url} should be reachable from the navigation");
        }
        $this->assertStringContainsString('Ops Admin', $html);
        $this->assertStringContainsString('Logout', $html);
        $this->assertStringNotContainsString('dropdown-toggle', substr($html, 0, strpos($html, '<div class="container pb-5">')), 'no dropdown menus in the navigation');
    }

    // ------------------------------------------------------------------
    // Challenges page
    // ------------------------------------------------------------------

    public function test_summary_tiles_and_segments_keep_pack_and_general_separate(): void
    {
        $this->challenge('General A');
        $this->challenge('General B');
        $this->challenge('Pack A', ['usage_pool' => Challenge::POOL_PACK]);
        $this->challenge('Daily A', ['usage_pool' => Challenge::POOL_DAILY]);
        $this->challenge('Tour A', ['usage_pool' => Challenge::POOL_TOURNAMENT]);
        $this->challenge('Old A', ['status' => 'archived']);
        $used = $this->challenge('Used A');
        DailyChallenge::create(['challenge_id' => $used->id, 'challenge_date' => '2026-01-01', 'status' => 'archived']);

        $res = $this->actingAs($this->admin())->get('/admin/challenges')->assertOk();
        $summary = $res->viewData('summary');
        $this->assertSame(7, $summary['total']);
        $this->assertSame(1, $summary['daily_pool']);
        $this->assertSame(1, $summary['pack']);
        $this->assertSame(4, $summary['general']);
        $this->assertSame(1, $summary['used_as_daily']);
        $this->assertSame(1, $summary['archived']);
        $this->assertSame(3, $summary['tournament_eligible']); // General A, General B, Tour A (Old A archived, Used A daily-used)
        $this->assertArrayNotHasKey('pack_general', $summary);

        $html = $res->getContent();
        $this->assertStringNotContainsString('Pack / General', $html);
        foreach (['Total challenges', 'Daily pool', 'Tournament eligible', '>Pack<', '>General<', 'Used as Daily'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        // Segments link to the existing query filters only.
        foreach (['/admin/challenges?usage_pool=daily', '/admin/challenges?tournament=eligible', '/admin/challenges?usage_pool=pack',
                  '/admin/challenges?usage_pool=general', '/admin/challenges?status=archived'] as $url) {
            $this->assertStringContainsString('href="' . $url . '"', $html);
        }
        $this->assertMatchesRegularExpression('/admin-segments[\s\S]*?<a class="nav-link active" href="\/admin\/challenges"/', $html);

        $packPage = $this->actingAs($this->admin())->get('/admin/challenges?usage_pool=pack')->assertOk();
        $this->assertSame(['Pack A'], $packPage->viewData('challenges')->pluck('title')->all());
        $this->assertMatchesRegularExpression('/<a class="nav-link active" href="\/admin\/challenges\?usage_pool=pack"/', $packPage->getContent());

        $generalPage = $this->actingAs($this->admin())->get('/admin/challenges?usage_pool=general')->assertOk();
        $this->assertEqualsCanonicalizing(['General A', 'General B', 'Old A'], $generalPage->viewData('challenges')->pluck('title')->all());
        $this->assertSame(['Used A'], $generalPage->viewData('usedDaily')->pluck('title')->all());

        $archivedPage = $this->actingAs($this->admin())->get('/admin/challenges?status=archived')->assertOk();
        $this->assertSame(['Old A'], $archivedPage->viewData('challenges')->pluck('title')->all());
    }

    public function test_filters_are_grouped_and_more_filters_opens_when_one_is_set(): void
    {
        $admin = $this->admin();

        $closed = $this->actingAs($admin)->get('/admin/challenges')->assertOk()->getContent();
        $this->assertStringContainsString('id="more-filters"', $closed);
        $this->assertMatchesRegularExpression('/<div class="collapse  mt-2" id="more-filters">/', $closed);
        $this->assertStringContainsString('aria-expanded="false"', $closed);
        $this->assertStringNotContainsString('Reset filters', $closed);

        $open = $this->actingAs($admin)->get('/admin/challenges?difficulty=hard')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<div class="collapse show mt-2" id="more-filters">/', $open);
        $this->assertStringContainsString('aria-expanded="true"', $open);
        $this->assertStringContainsString('Reset filters', $open);
        // Every pre-existing filter is still present somewhere on the page.
        foreach (['name="search"', 'name="status"', 'name="category"', 'name="usage_pool"', 'name="difficulty"', 'name="has_reveal"', 'name="used_as_daily"', 'name="tournament"'] as $field) {
            $this->assertStringContainsString($field, $open);
        }
    }

    public function test_sorting_is_presentation_only_and_defaults_to_newest(): void
    {
        $admin = $this->admin();
        $b = $this->challenge('Bravo'); $b->forceFill(['created_at' => now()->subDays(2)])->save();
        $a = $this->challenge('Alpha'); $a->forceFill(['created_at' => now()->subDay()])->save();
        $c = $this->challenge('Charlie');

        $titles = fn (string $q) => $this->actingAs($admin)->get('/admin/challenges' . $q)->assertOk()->viewData('challenges')->pluck('title')->all();

        $this->assertSame(['Charlie', 'Alpha', 'Bravo'], $titles(''));
        $this->assertSame(['Charlie', 'Alpha', 'Bravo'], $titles('?sort=newest'));
        $this->assertSame(['Bravo', 'Alpha', 'Charlie'], $titles('?sort=oldest'));
        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $titles('?sort=title'));
        $this->assertSame(['Charlie', 'Alpha', 'Bravo'], $titles('?sort=hack'));

        // The sort form carries the active filters so changing the order never drops them.
        $html = $this->actingAs($admin)->get('/admin/challenges?status=active&search=al&sort=title')->assertOk()->getContent();
        $this->assertStringContainsString('<input type="hidden" name="status" value="active">', $html);
        $this->assertStringContainsString('<input type="hidden" name="search" value="al">', $html);
        $this->assertMatchesRegularExpression('/<option value="title" selected>/', $html);
    }

    public function test_row_shows_labelled_images_clickable_title_availability_and_tidy_actions(): void
    {
        $general = $this->challenge('Cristiano Bicycle', ['original_image_path' => 'challenges/original/cr.jpg']);
        $pack    = $this->challenge('AppStore3', ['usage_pool' => Challenge::POOL_PACK]);
        $daily   = $this->challenge('Daily Only One', ['usage_pool' => Challenge::POOL_DAILY]);
        $draft   = $this->challenge('Draft Tour', ['usage_pool' => Challenge::POOL_TOURNAMENT, 'status' => 'draft']);
        $broken  = $this->challenge('Broken One', ['hidden_image_path' => null, 'ball_x_ratio' => null, 'ball_y_ratio' => null]); // active but incomplete

        $html = $this->actingAs($this->admin())->get('/admin/challenges')->assertOk()->getContent();

        // Images: both thumbnails, marked H and R, never stretched (fixed box + object-fit in CSS).
        $this->assertStringContainsString('alt="Hidden image for Cristiano Bicycle"', $html);
        $this->assertStringContainsString('alt="Reveal image for Cristiano Bicycle"', $html);
        $this->assertStringContainsString('<span class="thumb-tag" aria-hidden="true">H</span>', $html);
        $this->assertStringContainsString('<span class="thumb-tag" aria-hidden="true">R</span>', $html);
        $this->assertStringContainsString('No reveal', $html);
        $this->assertStringContainsString('No hidden', $html);

        // Title links to edit; Edit stays in the actions too.
        $this->assertStringContainsString('<a href="/admin/challenges/' . $general->id . '/edit" class="challenge-title">Cristiano Bicycle</a>', $html);
        $this->assertStringContainsString('<a href="/admin/challenges/' . $general->id . '/edit" class="btn btn-outline-secondary">Edit</a>', $html);
        $this->assertStringContainsString('<a href="/admin/challenges/' . $general->id . '/preview" class="btn btn-outline-secondary">Preview</a>', $html);

        // Availability: pool badge always; eligibility badge only when it adds information.
        $this->assertMatchesRegularExpression('/bg-pool-general[^>]*>General<\/span>\s*<span class="badge badge-soft-ok"[^>]*>Tournament eligible<\/span>/', $html);
        $this->assertMatchesRegularExpression('/bg-pool-pack" title="Pack only[^"]*">Pack<\/span>/', $html);
        $this->assertMatchesRegularExpression('/bg-pool-daily" title="Daily only[^"]*">Daily<\/span>/', $html);
        $this->assertStringContainsString('>Blocked: not active</span>', $html);
        $this->assertStringContainsString('>Blocked: incomplete</span>', $html);
        $this->assertSame(1, substr_count($html, '>Tournament eligible</span>'), 'only the eligible general challenge gets the eligibility badge');

        // Readiness problems stand out and say what is missing.
        $this->assertStringContainsString('Missing hidden image, ball position', $html);
        $this->assertSame(4, substr_count($html, '>Ready</span>'));

        // Ball position unchanged, with a tooltip.
        $this->assertStringContainsString('title="Ball position — X: 57% · Y: 14%">57%, 14%</span>', $html);

        // Actions: Preview + Edit visible, state changes in the overflow menu, Archive confirmed.
        $this->assertStringContainsString('aria-label="More actions for AppStore3"', $html);
        $this->assertStringContainsString('<button type="submit" class="dropdown-item text-danger" onclick="return confirm(\'Archive this challenge?\')">Archive</button>', $html);
        $this->assertStringContainsString('>Move to Draft</button>', $html);
        $this->assertStringContainsString('>Activate</button>', $html);
        $this->assertStringNotContainsString('btn btn-outline-warning btn-sm">Archive', $html, 'Archive is no longer a permanent bright button');
        // Status quick-change still posts to the same route.
        $this->assertStringContainsString('action="/admin/challenges/' . $draft->id . '/status"', $html);
    }

    public function test_empty_state_names_the_search_and_offers_to_clear_filters(): void
    {
        $this->challenge('Only One');

        $this->actingAs($this->admin())->get('/admin/challenges?search=zzz&status=active')
            ->assertOk()
            ->assertSee('No available challenges match these filters')
            ->assertSee('for &ldquo;zzz&rdquo;', false)
            ->assertSee('Clear filters');

        $this->actingAs($this->admin())->get('/admin/challenges?search=Only')
            ->assertOk()
            ->assertSee('1 challenge')
            ->assertSee('Only One');
    }

    public function test_safety_reminder_keeps_its_message_on_index_create_and_edit(): void
    {
        $admin = $this->admin();
        $c = $this->challenge('Edit Me');

        foreach (['/admin/challenges', '/admin/challenges/create', '/admin/challenges/' . $c->id . '/edit'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()
                ->assertSee('Content safety reminder')
                ->assertSee('php artisan ballspot:backup-content')
                ->assertSee('migrate:fresh')
                ->assertSee('to avoid losing real content');
        }
    }
}
