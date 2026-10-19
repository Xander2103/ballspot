<?php

namespace Tests\Feature\Clubs\Api;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Enums\ClubStatus;
use App\Enums\CompetitionState;
use App\Models\Club;
use App\Models\ClubAuditEvent;
use App\Models\ClubContentReport;
use App\Models\User;
use App\Services\AccountDeletionService;

/**
 * Wave F content reports (contracts §9): POST /api/clubs/{club}/reports by
 * any active member; the subject must belong to the club; throttle
 * `club-reports` 5/hour/user; audit `report.created` (platform-only);
 * account deletion nulls the reporter; the data export lists own reports.
 */
class ClubReportApiTest extends ClubApiTestCase
{
    private Club $club;
    private User $owner;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner  = User::factory()->create();
        $this->club   = $this->makeClub(['name' => 'Report FC'], $this->owner);
        $this->member = User::factory()->create();
        $this->addMember($this->club, $this->member);
    }

    private function report(User $user, array $body, ?Club $club = null)
    {
        return $this->api($user)->postJson($this->clubPath($club ?? $this->club, '/reports'), $body);
    }

    public function test_member_reports_the_club_a_competition_and_a_member(): void
    {
        $res = $this->report($this->member, ['subject_type' => 'club', 'reason' => 'offensive', 'details' => '  Bad logo  '])
            ->assertCreated();
        $this->assertSame(['data' => ['id' => $res->json('data.id'), 'status' => 'open']], $res->json());

        $row = ClubContentReport::findOrFail($res->json('data.id'));
        $this->assertSame($this->club->id, $row->club_id);
        $this->assertSame($this->member->id, $row->reporter_user_id);
        $this->assertSame('club', $row->subject_type);
        $this->assertSame($this->club->public_id, $row->subject_ref);
        $this->assertSame('Bad logo', $row->details);

        $competition = $this->makeCompetition($this->club, [$this->makeClubPack($this->club, 1)], ['state' => CompetitionState::Published]);
        $this->report($this->member, ['subject_type' => 'competition', 'subject_id' => $competition->public_id, 'reason' => 'spam'])->assertCreated();
        $this->assertSame($competition->public_id, ClubContentReport::latest('id')->first()->subject_ref);

        // subject_id may arrive as a JSON number for members.
        $this->report($this->member, ['subject_type' => 'member', 'subject_id' => $this->owner->id, 'reason' => 'privacy'])->assertCreated();
        $this->assertSame((string) $this->owner->id, ClubContentReport::latest('id')->first()->subject_ref);

        $event = ClubAuditEvent::where('action', 'report.created')->latest('id')->first();
        $this->assertSame(ClubAuditEvent::ACTOR_MEMBER, $event->actor_kind);
        $this->assertSame($this->member->id, $event->actor_user_id);
        $this->assertSame(['report_id' => ClubContentReport::latest('id')->first()->id, 'subject_kind' => 'member', 'subject_ref' => (string) $this->owner->id, 'reason' => 'privacy'], $event->metadata);
        $this->assertStringNotContainsString('Bad logo', json_encode(ClubAuditEvent::pluck('metadata')));
    }

    public function test_an_identical_open_report_is_not_duplicated(): void
    {
        $first  = $this->report($this->member, ['subject_type' => 'club', 'reason' => 'spam'])->assertCreated()->json('data.id');
        $second = $this->report($this->member, ['subject_type' => 'club', 'reason' => 'spam'])->assertOk()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, ClubContentReport::count());
        $this->assertSame(1, ClubAuditEvent::where('action', 'report.created')->count());
    }

    public function test_validation(): void
    {
        config(['ballspot.clubs.reports.per_user_per_hour' => 100]);
        $this->report($this->member, [])->assertUnprocessable()->assertJsonValidationErrors(['subject_type', 'reason']);
        $this->report($this->member, ['subject_type' => 'pack', 'reason' => 'spam'])->assertUnprocessable()->assertJsonValidationErrors('subject_type');
        $this->report($this->member, ['subject_type' => 'club', 'reason' => 'boring'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->report($this->member, ['subject_type' => 'club', 'reason' => 'other', 'details' => str_repeat('x', 501)])
            ->assertUnprocessable()->assertJsonValidationErrors('details');
        $this->report($this->member, ['subject_type' => 'competition', 'reason' => 'other'])->assertUnprocessable()->assertJsonValidationErrors('subject_id');
        $this->report($this->member, ['subject_type' => 'member', 'subject_id' => $this->member->id, 'reason' => 'other'])
            ->assertUnprocessable()->assertJsonValidationErrors('subject_id');

        $this->assertSame(0, ClubContentReport::count());
    }

    public function test_the_subject_must_belong_to_the_club(): void
    {
        config(['ballspot.clubs.reports.per_user_per_hour' => 100]);
        $other      = $this->makeClub([], User::factory()->create());
        $foreignC   = $this->makeCompetition($other, [$this->makeClubPack($other, 1)], ['state' => CompetitionState::Published]);
        $draft      = $this->makeCompetition($this->club, [$this->makeClubPack($this->club, 1)]);
        $foreignM   = User::factory()->create();
        $this->addMember($other, $foreignM);
        $leftMember = User::factory()->create();
        $this->addMember($this->club, $leftMember, ClubRole::Member, ClubMemberStatus::Left);

        foreach ([
            ['competition', $foreignC->public_id],
            ['competition', $draft->public_id],
            ['competition', '01JBUNKNOWN0000000000000000'],
            ['member', (string) $foreignM->id],
            ['member', (string) $leftMember->id],
            ['member', 'abc'],
        ] as [$type, $id]) {
            $this->report($this->member, ['subject_type' => $type, 'subject_id' => $id, 'reason' => 'other'])
                ->assertUnprocessable()->assertJsonValidationErrors('subject_id')
                ->assertJsonPath('errors.subject_id.0', __('clubs.report_messages.subject_invalid'));
        }
        $this->assertSame(0, ClubContentReport::count());
    }

    public function test_non_members_get_404_before_validation(): void
    {
        $outsider = User::factory()->create();
        $left     = User::factory()->create();
        $this->addMember($this->club, $left, ClubRole::Member, ClubMemberStatus::Left);
        $platform = User::factory()->create(['is_admin' => true]);

        foreach ([$outsider, $left, $platform] as $user) {
            $this->report($user, ['subject_type' => 'nope'])->assertNotFound()->assertJsonPath('code', 'club_not_found');
        }
        $this->assertSame(0, ClubContentReport::count());
    }

    public function test_any_role_may_report_also_on_a_suspended_club(): void
    {
        $admin = User::factory()->create();
        $this->addMember($this->club, $admin, ClubRole::Admin);
        $this->report($admin, ['subject_type' => 'club', 'reason' => 'other'])->assertCreated();

        $this->club->forceFill(['status' => ClubStatus::Suspended])->save();
        app(\App\Services\Clubs\ClubAccessContext::class)->flush();
        $this->report($this->member, ['subject_type' => 'club', 'reason' => 'inappropriate'])->assertCreated();

        $this->club->forceFill(['status' => ClubStatus::Archived])->save();
        app(\App\Services\Clubs\ClubAccessContext::class)->flush();
        $this->report($this->member, ['subject_type' => 'club', 'reason' => 'spam'])->assertNotFound();
    }

    public function test_reports_are_throttled_at_5_per_hour_per_user(): void
    {
        foreach (['inappropriate', 'offensive', 'spam', 'privacy', 'other'] as $reason) {
            $this->report($this->member, ['subject_type' => 'club', 'reason' => $reason])->assertCreated();
        }
        $this->report($this->member, ['subject_type' => 'member', 'subject_id' => $this->owner->id, 'reason' => 'spam'])
            ->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame(5, ClubContentReport::count());

        // Per user: someone else is unaffected.
        $this->report($this->owner, ['subject_type' => 'club', 'reason' => 'spam'])->assertCreated();
    }

    public function test_account_deletion_nulls_the_reporter_and_keeps_the_report(): void
    {
        $id = $this->report($this->member, ['subject_type' => 'club', 'reason' => 'spam'])->assertCreated()->json('data.id');
        $reviewer = User::factory()->create(['is_admin' => true]);
        ClubContentReport::whereKey($id)->update(['reviewed_by_user_id' => $reviewer->id]);

        app(AccountDeletionService::class)->delete($this->member->fresh());
        app(AccountDeletionService::class)->delete($reviewer->fresh());

        $row = ClubContentReport::findOrFail($id);
        $this->assertNull($row->reporter_user_id);
        $this->assertNull($row->reviewed_by_user_id);
        $this->assertSame('spam', $row->reason);
    }

    public function test_data_export_lists_own_reports_only(): void
    {
        $this->report($this->member, ['subject_type' => 'club', 'reason' => 'spam', 'details' => 'mine'])->assertCreated();
        $this->report($this->owner, ['subject_type' => 'member', 'subject_id' => $this->member->id, 'reason' => 'offensive', 'details' => 'about you'])->assertCreated();
        ClubContentReport::where('reporter_user_id', $this->member->id)->update(['admin_note' => 'internal moderator note']);

        $res = $this->api($this->member)->getJson('/api/me/export')->assertOk();
        $reports = $res->json('clubs.content_reports');
        $this->assertCount(1, $reports);
        $this->assertSame(['id' => $this->club->public_id, 'name' => 'Report FC'], $reports[0]['club']);
        $this->assertSame('mine', $reports[0]['details']);
        $this->assertSame('open', $reports[0]['status']);
        $this->assertStringNotContainsString('about you', $res->getContent());
        $this->assertStringNotContainsString('internal moderator note', $res->getContent());
    }
}
