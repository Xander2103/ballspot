<?php

namespace Tests\Feature\Clubs\Portal;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Models\ClubAuditEvent;
use App\Models\ClubMember;
use App\Models\User;

/**
 * Portal Members page: search/paginate, remove / ban / unban, role changes
 * and ownership transfer with the role rules (owners untouchable by admins,
 * owner-only role/transfer, nobody acts on themselves) and the audit trail
 * (actor_kind club_admin).
 */
class PortalMembersTest extends PortalTestCase
{
    private User $admin;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin  = $this->userWithRole(ClubRole::Admin, null, ['username' => 'the_admin', 'email' => 'admin-secret@example.test']);
        $this->member = $this->userWithRole(ClubRole::Member, null, ['username' => 'plain_player', 'email' => 'player-secret@example.test']);
    }

    private function row(User $user): ClubMember
    {
        return ClubMember::where('club_id', $this->club->id)->where('user_id', $user->id)->firstOrFail();
    }

    public function test_list_shows_usernames_never_emails_and_searches(): void
    {
        $this->portalAs($this->admin)->get($this->url('/members'))->assertOk()
            ->assertSee('club_owner')->assertSee('the_admin')->assertSee('plain_player')
            ->assertDontSee('admin-secret@example.test')->assertDontSee('player-secret@example.test');

        // (the signed-in username is in the navbar, so search as the owner)
        $this->portalAs($this->owner)->get($this->url('/members?q=plain'))->assertOk()
            ->assertSee('plain_player')->assertDontSee('the_admin');

        $this->portalAs($this->admin)->get($this->url('/members?role=owner'))->assertOk()
            ->assertSee('club_owner')->assertDontSee('plain_player');
    }

    public function test_list_paginates(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->userWithRole(ClubRole::Member, null, ['username' => sprintf('bulk_%02d', $i)]);
        }

        $this->portalAs($this->owner)->get($this->url('/members'))->assertOk()->assertSee('?page=2', false);
    }

    public function test_admin_removes_a_member_and_it_is_audited_as_club_admin(): void
    {
        $this->portalAs($this->admin)->from($this->url('/members'))
            ->post($this->url('/members/' . $this->row($this->member)->id . '/remove'), ['reason' => 'left the team'])
            ->assertRedirect($this->url('/members'))
            ->assertSessionHas('success', 'plain_player was removed.');

        $this->assertSame(ClubMemberStatus::Removed, $this->row($this->member)->status);
        $event = ClubAuditEvent::where('action', 'member.removed')->latest('id')->firstOrFail();
        $this->assertSame(ClubAuditEvent::ACTOR_CLUB_ADMIN, $event->actor_kind);
        $this->assertSame($this->admin->id, $event->actor_user_id);
    }

    public function test_admin_bans_and_unbans(): void
    {
        $id = $this->row($this->member)->id;

        $this->portalAs($this->admin)->post($this->url('/members/' . $id . '/ban'))->assertSessionHas('success');
        $this->assertSame(ClubMemberStatus::Banned, $this->row($this->member)->status);

        $this->portalAs($this->admin)->get($this->url('/members?status=banned'))->assertOk()->assertSee('plain_player')->assertSee('Lift ban');

        $this->portalAs($this->admin)->post($this->url('/members/' . $id . '/unban'))->assertSessionHas('success');
        $this->assertSame(ClubMemberStatus::Removed, $this->row($this->member)->status);
        $this->assertSame(ClubAuditEvent::ACTOR_CLUB_ADMIN, ClubAuditEvent::where('action', 'member.unbanned')->firstOrFail()->actor_kind);
    }

    public function test_admin_may_act_on_another_admin_but_never_on_an_owner(): void
    {
        $otherAdmin = $this->userWithRole(ClubRole::Admin);

        $this->portalAs($this->admin)->post($this->url('/members/' . $this->row($otherAdmin)->id . '/remove'))->assertSessionHas('success');
        $this->assertSame(ClubMemberStatus::Removed, $this->row($otherAdmin)->status);

        foreach (['remove', 'ban'] as $action) {
            $this->portalAs($this->admin)->post($this->url('/members/' . $this->row($this->owner)->id . '/' . $action))
                ->assertSessionHas('error', __('clubs.errors.club_forbidden'));
        }
        $this->assertTrue($this->row($this->owner)->isActive());
        $this->assertSame(ClubRole::Owner, $this->row($this->owner)->role);
    }

    public function test_nobody_acts_on_themselves(): void
    {
        $this->portalAs($this->admin)->post($this->url('/members/' . $this->row($this->admin)->id . '/remove'))
            ->assertSessionHas('error', __('clubs.errors.club_forbidden'));
        $this->portalAs($this->owner)->post($this->url('/members/' . $this->row($this->owner)->id . '/ban'))
            ->assertSessionHas('error', __('clubs.errors.club_forbidden'));
        $this->portalAs($this->owner)->patch($this->url('/members/' . $this->row($this->owner)->id . '/role'), ['role' => 'member'])
            ->assertSessionHas('error');

        $this->assertTrue($this->row($this->admin)->isActive());
        $this->assertSame(ClubRole::Owner, $this->row($this->owner)->role);
    }

    public function test_role_changes_are_owner_only_and_never_to_owner(): void
    {
        $id = $this->row($this->member)->id;

        $this->portalAs($this->admin)->patch($this->url('/members/' . $id . '/role'), ['role' => 'admin'])
            ->assertSessionHas('error', __('clubs.errors.club_forbidden'));
        $this->assertSame(ClubRole::Member, $this->row($this->member)->role);

        $this->portalAs($this->owner)->patch($this->url('/members/' . $id . '/role'), ['role' => 'owner'])->assertSessionHasErrors('role');
        $this->assertSame(ClubRole::Member, $this->row($this->member)->role);

        $this->portalAs($this->owner)->patch($this->url('/members/' . $id . '/role'), ['role' => 'admin'])
            ->assertSessionHas('success', 'plain_player is now Admin.');
        $this->assertSame(ClubRole::Admin, $this->row($this->member)->role);

        $this->portalAs($this->owner)->patch($this->url('/members/' . $id . '/role'), ['role' => 'member'])->assertSessionHas('success');
        $this->assertSame(ClubRole::Member, $this->row($this->member)->role);

        $event = ClubAuditEvent::where('action', 'member.role_changed')->latest('id')->firstOrFail();
        $this->assertSame(ClubAuditEvent::ACTOR_CLUB_ADMIN, $event->actor_kind);
    }

    public function test_owner_cannot_demote_a_co_owner_by_role_change(): void
    {
        $coOwner = $this->userWithRole(ClubRole::Owner);

        $this->portalAs($this->owner)->patch($this->url('/members/' . $this->row($coOwner)->id . '/role'), ['role' => 'admin'])
            ->assertSessionHas('error', __('clubs.errors.club_forbidden'));
        $this->assertSame(ClubRole::Owner, $this->row($coOwner)->role);

        // An owner may remove a co-owner (another owner remains).
        $this->portalAs($this->owner)->post($this->url('/members/' . $this->row($coOwner)->id . '/remove'))->assertSessionHas('success');
        $this->assertSame(1, $this->club->owners()->count());
    }

    public function test_transfer_ownership_is_owner_only_and_needs_the_typed_username(): void
    {
        $targetId = $this->row($this->member)->id;

        $this->portalAs($this->admin)->post($this->url('/transfer-ownership'), ['member_id' => $targetId, 'confirm' => 'plain_player'])
            ->assertForbidden();

        $this->portalAs($this->owner)->post($this->url('/transfer-ownership'), ['member_id' => $targetId, 'confirm' => 'someone_else'])
            ->assertSessionHasErrors('confirm', null, 'transfer');
        $this->assertSame(ClubRole::Owner, $this->row($this->owner)->role);

        $this->portalAs($this->owner)->post($this->url('/transfer-ownership'), ['member_id' => $targetId, 'confirm' => 'Plain_Player'])
            ->assertRedirect($this->url('/members'))
            ->assertSessionHas('success');

        $this->assertSame(ClubRole::Owner, $this->row($this->member)->role);
        $this->assertSame(ClubRole::Admin, $this->row($this->owner)->role);
        $this->assertSame(1, $this->club->owners()->count(), 'exactly one owner after a transfer');
        $this->assertSame(ClubAuditEvent::ACTOR_CLUB_ADMIN, ClubAuditEvent::where('action', 'member.ownership_transferred')->firstOrFail()->actor_kind);

        // The former owner lost the owner-only pages.
        $this->portalAs($this->owner)->get($this->url('/settings'))->assertForbidden();
    }

    public function test_transfer_to_a_non_member_or_other_club_is_refused(): void
    {
        $otherOwner = User::factory()->create();
        $other = $this->makeClub(['slug' => 'other-fc'], $otherOwner);
        $foreign = $other->members()->firstOrFail();

        $this->portalAs($this->owner)->post($this->url('/transfer-ownership'), ['member_id' => $foreign->id, 'confirm' => $otherOwner->username])
            ->assertSessionHas('error', __('clubs.errors.club_member_not_found'));
        $this->assertSame(ClubRole::Owner, $this->row($this->owner)->role);
    }

    public function test_last_owner_is_protected_by_the_service(): void
    {
        // Two owners: the second one is removed; the remaining one cannot be
        // removed by an admin, nor by themselves.
        $this->portalAs($this->admin)->post($this->url('/members/' . $this->row($this->owner)->id . '/remove'))->assertSessionHas('error');
        $this->assertSame(1, $this->club->owners()->count());

        // Directly through the service the invariant still holds.
        $this->expectException(\App\Exceptions\ClubDomainException::class);
        app(\App\Services\Clubs\ClubMembershipService::class)->remove($this->row($this->owner)->setRelation('club', $this->club), $this->admin, 'club_admin', null);
    }

    public function test_member_actions_validate_the_reason(): void
    {
        $this->portalAs($this->admin)->post($this->url('/members/' . $this->row($this->member)->id . '/remove'), ['reason' => str_repeat('x', 201)])
            ->assertSessionHasErrors('reason');
        $this->assertTrue($this->row($this->member)->isActive());
    }
}
