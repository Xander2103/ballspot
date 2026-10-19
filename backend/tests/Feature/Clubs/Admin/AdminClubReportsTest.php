<?php

namespace Tests\Feature\Clubs\Admin;

use App\Enums\ClubRole;
use App\Enums\CompetitionState;
use App\Models\Club;
use App\Models\ClubAuditEvent;
use App\Models\ClubContentReport;
use App\Models\User;
use App\Services\ClubPortal\ClubPortalSession;
use App\Services\Clubs\ClubContentReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesClubs;
use Tests\TestCase;

/**
 * Wave F admin Reports page (/admin/club-reports): Clubs nav entry with the
 * open-count badge, filters status / club / reason, links to the reported
 * subject, resolve as reviewed / dismissed / actioned with a note (audit
 * report.resolved). Report events never reach the club portal.
 */
class AdminClubReportsTest extends TestCase
{
    use CreatesClubs, RefreshDatabase;

    private User $platform;
    private Club $club;
    private User $owner;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableClubs();
        $this->platform = User::factory()->create(['is_admin' => true]);
        $this->owner    = User::factory()->create(['username' => 'owner_x']);
        $this->club     = $this->makeClub(['name' => 'Reported FC'], $this->owner);
        $this->member   = User::factory()->create(['username' => 'reporter_joe']);
        $this->addMember($this->club, $this->member);
    }

    private function file(User $reporter, string $type, ?string $id, string $reason, ?Club $club = null): ClubContentReport
    {
        return app(ClubContentReportService::class)->create($club ?? $this->club, $reporter, $type, $id, $reason, 'details ' . $reason)['report'];
    }

    public function test_page_lists_open_reports_with_subject_links_and_the_nav_badge(): void
    {
        $competition = $this->makeCompetition($this->club, [$this->makeClubPack($this->club, 1)], ['state' => CompetitionState::Published, 'name' => 'Autumn Cup']);
        $this->file($this->member, 'club', null, 'offensive');
        $this->file($this->member, 'competition', $competition->public_id, 'spam');
        $this->file($this->member, 'member', (string) $this->owner->id, 'privacy');
        $done = $this->file($this->owner, 'club', null, 'other');
        app(ClubContentReportService::class)->resolve($done, $this->platform, 'dismissed', null);

        $res = $this->actingAs($this->platform)->get('/admin/club-reports')->assertOk()
            ->assertSee('Club reports')
            ->assertSee('Reported FC')
            ->assertSee('reporter_joe')
            ->assertSee('details offensive')
            ->assertSee('Autumn Cup')
            ->assertSee(route('admin.clubs.competitions.show', [$this->club->id, $competition->id]), false)
            ->assertSee(route('admin.clubs.show', [$this->club->id, 'tab' => 'members', 'q' => 'owner_x']))
            ->assertDontSee('details other');

        // Nav: Clubs group → Reports with the open count (3).
        $res->assertSee('href="/admin/club-reports"', false)
            ->assertSee('title="3 open reports"', false);

        $this->actingAs($this->platform)->get('/admin/club-reports?status=all')->assertOk()->assertSee('details other');
        $this->actingAs($this->platform)->get('/admin/club-reports?status=dismissed')->assertOk()
            ->assertSee('details other')->assertDontSee('details offensive');
        $this->actingAs($this->platform)->get('/admin/club-reports?reason=spam')->assertOk()
            ->assertSee('details spam')->assertDontSee('details privacy');

        $other = $this->makeClub(['name' => 'Elsewhere FC'], User::factory()->create());
        $otherMember = User::factory()->create();
        $this->addMember($other, $otherMember);
        $this->file($otherMember, 'club', null, 'inappropriate', $other);
        $this->actingAs($this->platform)->get('/admin/club-reports?club=' . $other->id)->assertOk()
            ->assertSee('details inappropriate')->assertDontSee('details spam');

        // The badge also shows on the other club pages.
        $this->actingAs($this->platform)->get('/admin/clubs')->assertOk()->assertSee('title="4 open reports"', false);
    }

    public function test_resolve_records_status_note_reviewer_and_audit(): void
    {
        $report = $this->file($this->member, 'club', null, 'offensive');

        $this->actingAs($this->platform)->from('/admin/club-reports')
            ->post("/admin/club-reports/{$report->id}/resolve", ['status' => 'actioned', 'admin_note' => 'Logo replaced'])
            ->assertRedirect('/admin/club-reports')->assertSessionHas('success');

        $report->refresh();
        $this->assertSame('actioned', $report->status);
        $this->assertSame('Logo replaced', $report->admin_note);
        $this->assertSame($this->platform->id, $report->reviewed_by_user_id);
        $this->assertNotNull($report->reviewed_at);

        $event = ClubAuditEvent::where('action', 'report.resolved')->firstOrFail();
        $this->assertSame(ClubAuditEvent::ACTOR_PLATFORM_ADMIN, $event->actor_kind);
        $this->assertSame(['report_id' => $report->id, 'from_status' => 'open', 'to_status' => 'actioned'], $event->metadata);

        $this->actingAs($this->platform)->post("/admin/club-reports/{$report->id}/resolve", ['status' => 'open'])
            ->assertSessionHasErrors('status');
        $this->assertSame('actioned', $report->fresh()->status);
    }

    public function test_page_and_resolve_are_closed_to_non_admins_and_guests(): void
    {
        $report = $this->file($this->member, 'club', null, 'spam');

        $this->get('/admin/club-reports')->assertRedirect('/admin/login');
        $this->post("/admin/club-reports/{$report->id}/resolve", ['status' => 'dismissed'])->assertRedirect('/admin/login');
        $this->actingAs($this->owner)->get('/admin/club-reports')->assertForbidden();
        $this->actingAs($this->owner)->post("/admin/club-reports/{$report->id}/resolve", ['status' => 'dismissed'])->assertForbidden();

        $this->assertSame('open', $report->fresh()->status);
    }

    public function test_report_events_never_reach_the_club_portal(): void
    {
        $this->file($this->member, 'member', (string) $this->owner->id, 'offensive');
        $this->assertSame(1, ClubAuditEvent::where('action', 'report.created')->count());

        $now = now()->getTimestamp();
        $portal = $this->actingAs($this->owner)->withSession([
            ClubPortalSession::VERIFIED_AT   => $now,
            ClubPortalSession::VERIFIED_USER => $this->owner->id,
            ClubPortalSession::LAST_SEEN     => $now,
        ]);

        $portal->get('/club/' . $this->club->slug . '/audit')->assertOk()
            ->assertDontSee(__('clubs.audit.report.created'))
            ->assertDontSee('reporter_joe')
            ->assertDontSee('value="report.created"', false);
        $portal->get('/club/' . $this->club->slug . '/audit?action=report.created')->assertOk()->assertDontSee('reporter_joe');
        $portal->get('/club/' . $this->club->slug)->assertOk()->assertDontSee(__('clubs.audit.report.created'));

        // Platform admins do see it in the global audit log.
        $this->actingAs($this->platform)->get('/admin/club-audit?action=report.created')->assertOk()->assertSee('reporter_joe');
    }

    public function test_club_admin_role_does_not_grant_the_admin_page(): void
    {
        $clubAdmin = User::factory()->create();
        $this->addMember($this->club, $clubAdmin, ClubRole::Admin);

        $this->actingAs($clubAdmin)->get('/admin/club-reports')->assertForbidden();
    }
}
