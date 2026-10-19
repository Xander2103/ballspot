<?php

namespace Tests\Feature\Clubs\Admin;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Models\ClubAuditEvent;
use App\Models\ClubPhotoSubmission;
use App\Models\PackAttempt;
use App\Models\User;
use App\Services\Clubs\ClubService;
use App\Services\Clubs\ClubStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesClubs;
use Tests\TestCase;

/**
 * Club detail (tabs + overview statistics), the Clubs navigation group, the
 * placeholder pages and the global audit log. Pinned strings guard the
 * admin-facing copy (AT-ADM-09, string part).
 */
class AdminClubDetailAndNavigationTest extends TestCase
{
    use CreatesClubs, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    public function test_overview_statistics_are_aggregated(): void
    {
        $club = $this->makeClub(['name' => 'Stats FC'], User::factory()->create());
        $recent = $this->addMember($club, $a = User::factory()->create());
        $old    = $this->addMember($club, $b = User::factory()->create(), ClubRole::Admin);
        $old->forceFill(['last_seen_at' => now()->subDays(20), 'joined_at' => now()->subDays(60)])->save();
        $this->addMember($club, User::factory()->create(), ClubRole::Member, ClubMemberStatus::Left);

        $pack = $this->makeClubPack($club, 2);
        PackAttempt::create(['user_id' => $a->id, 'challenge_pack_id' => $pack->id, 'status' => 'completed', 'completed_at' => now(), 'started_at' => now()]);
        $older = PackAttempt::create(['user_id' => $b->id, 'challenge_pack_id' => $pack->id, 'status' => 'active', 'started_at' => now()]);
        $older->forceFill(['created_at' => now()->subDays(10)])->save();
        // Another club's play never counts.
        PackAttempt::create(['user_id' => $a->id, 'challenge_pack_id' => $this->makeClubPack($this->makeClub(), 1)->id, 'status' => 'completed']);

        $this->makeCompetition($club, [$pack], ['name' => 'Autumn Cup', 'state' => 'published', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(3)]);
        $this->makeCompetition($club, [], ['state' => 'published', 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(5)]);
        ClubPhotoSubmission::factory()->create(['club_id' => $club->id, 'status' => 'pending']);
        ClubPhotoSubmission::factory()->create(['club_id' => $club->id, 'status' => 'rejected']);
        app(ClubService::class)->updateSettings($club, ['join_enabled' => false], $this->admin, 'platform_admin');

        $stats = app(ClubStatsService::class)->overview($club->fresh());

        $this->assertSame(3, $stats['members']['total']);       // owner, a, b (the left member is not counted)
        $this->assertSame(1, $stats['members']['owners']);
        $this->assertSame(1, $stats['members']['admins']);
        $this->assertSame(2, $stats['members']['active_7d']);   // b was last seen 20 days ago
        $this->assertSame(3, $stats['members']['active_30d']);
        $this->assertSame(3, $stats['members']['joins_30d']);   // b joined 60 days ago
        $this->assertSame(1, $stats['members']['leaves_30d']);
        $this->assertSame(1, $stats['plays']['plays_7d']);
        $this->assertSame(2, $stats['plays']['plays_30d']);
        $this->assertSame(1, $stats['plays']['pack_completions']);
        $this->assertSame(2, $stats['plays']['players_30d']);
        $this->assertSame(1, $stats['competitions']['live_count']);
        $this->assertSame(1, $stats['competitions']['upcoming']);
        $this->assertSame(1, $stats['photos']['open']);
        $this->assertSame(['active' => 1, 'total' => 1], ['active' => $stats['packs']['active'], 'total' => $stats['packs']['total']]);
        $this->assertSame('club.settings_changed', $stats['recent_events']->first()->action);

        // Fixed query count, whatever the club size.
        DB::enableQueryLog();
        app(ClubStatsService::class)->overview($club->fresh());
        $this->assertLessThanOrEqual(12, count(DB::getQueryLog()));
        DB::disableQueryLog();

        $html = $this->actingAs($this->admin)->get('/admin/clubs/' . $club->id)->assertOk()->getContent();
        foreach (['Active members', 'Seen last 7 days', 'Joins · leaves (30d)', 'Plays last 7 days', 'Pack completions', 'Live competitions',
                  'Open photos', 'Usable invite codes', 'Autumn Cup', 'Recent activity', 'Club settings changed', 'Joining off', 'Club facts'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    public function test_detail_header_and_tabs(): void
    {
        $club = $this->makeClub(['name' => 'Header FC', 'city' => 'Gent', 'country_code' => 'BE', 'external_ref' => 'REF-9']);
        $this->makeClubPack($club, 1);

        $res = $this->actingAs($this->admin)->get('/admin/clubs/' . $club->id . '?tab=nope')->assertOk();
        $this->assertSame('overview', $res->viewData('tab'));
        $html = $res->getContent();

        foreach (['Overview', 'Members', 'Invite codes', 'Packs', 'Competitions', 'Photos', 'Settings', 'Audit'] as $label) {
            $this->assertMatchesRegularExpression('/<a class="nav-link[^"]*" href="[^"]*tab=[a-z]+"[^>]*>\s*' . preg_quote($label, '/') . '/', $html, "tab {$label}");
        }
        foreach (['Edit details', 'data-action="suspend"', 'data-action="archive"', 'Gent, BE', 'ref REF-9', $club->public_id,
                  'This club has no active owner.', 'Needs owner'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }

        $packs = $this->actingAs($this->admin)->get('/admin/clubs/' . $club->id . '?tab=packs')->assertOk();
        $packs->assertSee('+ New club pack')->assertSee('/admin/clubs/' . $club->id . '/packs/create');
        $this->actingAs($this->admin)->get('/admin/clubs/' . $club->id . '/packs/create')->assertRedirect('/admin/packs/create?club=' . $club->id);

        // C1 replaced the read-only list with the editor links (tests/Feature/Clubs/Competitions).
        $this->actingAs($this->admin)->get('/admin/clubs/' . $club->id . '?tab=competitions')->assertSee('+ New competition')
            ->assertSee('/admin/clubs/' . $club->id . '/competitions/create');
        // D1 replaced the read-only list with the upload form + queue links.
        $this->actingAs($this->admin)->get('/admin/clubs/' . $club->id . '?tab=photos')->assertSee('Upload photos for')->assertSee('Open this club in Photo review');
        $this->actingAs($this->admin)->get('/admin/clubs/' . $club->id . '?tab=settings')
            ->assertSee('Joining with invite codes enabled')
            ->assertSee('Owners &amp; admins only (default)', false)
            ->assertSee('Usernames + avatars')
            ->assertSee('Default normal play of linked packs');
        $this->actingAs($this->admin)->get('/admin/clubs/999999')->assertNotFound();
    }

    public function test_navigation_group_and_photo_badge(): void
    {
        $club = $this->makeClub();
        ClubPhotoSubmission::factory()->count(3)->create(['club_id' => $club->id, 'status' => 'pending']);
        ClubPhotoSubmission::factory()->create(['club_id' => $club->id, 'status' => 'converted']);

        $cases = [
            '/admin/clubs'                => 'Clubs',
            '/admin/clubs/create'         => 'Clubs',
            '/admin/clubs/' . $club->id   => 'Clubs',
            '/admin/club-competitions'    => 'Competitions',
            '/admin/club-photos'          => 'Photo review',
            '/admin/club-audit'           => 'Audit log',
        ];
        foreach ($cases as $url => $page) {
            $html = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/<a class="admin-nav-link active"[^>]*>Clubs<\/a>/', $html, $url);
            $this->assertSame(1, preg_match_all('/admin-nav-link active/', $html), $url);
            $this->assertMatchesRegularExpression('/<a class="admin-subnav-link active"[^>]*>' . preg_quote($page, '/') . '(<span[^>]*>\d+<\/span>)?<\/a>/', $html, $url);
            $this->assertSame(1, preg_match_all('/admin-subnav-link active/', $html), $url);
            foreach (['href="/admin/clubs"', 'href="/admin/club-competitions"', 'href="/admin/club-photos"', 'href="/admin/club-audit"'] as $link) {
                $this->assertStringContainsString($link, $html);
            }
        }
        $this->assertStringContainsString('>Photo review<span class="badge rounded-pill bg-danger ms-1" title="3 awaiting review">3</span></a>',
            $this->actingAs($this->admin)->get('/admin/clubs')->getContent());

        // Other admin pages link the Clubs group but do not pay for the badge query.
        $other = $this->actingAs($this->admin)->get('/admin/categories')->getContent();
        $this->assertStringContainsString('href="/admin/clubs"', $other);
        $this->assertStringNotContainsString('title="3 awaiting review"', $other);
    }

    public function test_placeholder_pages_are_explicit(): void
    {
        // C1 replaced the competition placeholder with the cross-club list (tests/Feature/Clubs/Competitions).
        $this->actingAs($this->admin)->get('/admin/club-competitions')->assertOk()
            ->assertSee('Club competitions')->assertSee('No club competitions yet (0).');
        // D1 replaced the photo placeholder with the review queue (tests/Feature/Clubs/Photos).
        $this->actingAs($this->admin)->get('/admin/club-photos')->assertOk()
            ->assertSee('Photo review')->assertSee('No open photos — the review queue is empty.');
    }

    public function test_global_and_club_audit_logs_filter(): void
    {
        $a = $this->makeClub(['name' => 'Audit A']);
        $b = $this->makeClub(['name' => 'Audit B']);
        $actor = User::factory()->create(['username' => 'auditor', 'is_admin' => true]);
        $clubs = app(ClubService::class);
        $clubs->suspend($a, $actor, 'Reason A');
        $clubs->suspend($b, $this->admin, 'Reason B');
        $clubs->reactivate($b, $this->admin);

        $actions = fn (string $url) => $this->actingAs($this->admin)->get($url)->assertOk()->viewData('events')->pluck('action')->all();

        $this->assertCount(3, $actions('/admin/club-audit'));
        $this->assertSame(['club.suspended'], $actions('/admin/club-audit?actor=auditor'));
        $this->assertSame(['club.reactivated', 'club.suspended'], $actions('/admin/club-audit?club=' . $b->id));
        $this->assertSame(['club.suspended', 'club.suspended'], $actions('/admin/club-audit?action=club.suspended'));
        $this->assertCount(3, $actions('/admin/club-audit?action=not.allowed'));
        $this->assertSame([], $actions('/admin/club-audit?from=' . now()->addDay()->toDateString()));
        $this->assertSame(['club.suspended'], $actions('/admin/club-audit?actor=' . $actor->id));

        $html = $this->actingAs($this->admin)->get('/admin/club-audit')->getContent();
        foreach (['Club audit log', 'Audit A', 'Audit B', 'Club suspended', 'reason: Reason A', 'platform admin'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }

        // Club tab: scoped to the club.
        $this->assertSame(['club.reactivated', 'club.suspended'], $actions('/admin/clubs/' . $b->id . '?tab=audit'));
        $this->assertSame(['club.reactivated'], $actions('/admin/clubs/' . $b->id . '?tab=audit&action=club.reactivated'));
        $this->assertSame(3, ClubAuditEvent::count());
    }
}
