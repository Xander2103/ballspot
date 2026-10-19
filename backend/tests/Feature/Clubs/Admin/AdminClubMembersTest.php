<?php

namespace Tests\Feature\Clubs\Admin;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Models\Club;
use App\Models\ClubAuditEvent;
use App\Models\ClubMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesClubs;
use Tests\TestCase;

/**
 * AT-ADM-05: member add / role / remove / ban / unban / reactivate / transfer
 * ownership are validated, audited and never leave a club without an owner.
 */
class AdminClubMembersTest extends TestCase
{
    use CreatesClubs, RefreshDatabase;

    private User $admin;
    private User $owner;
    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->owner = User::factory()->create(['username' => 'the_owner']);
        $this->club  = $this->makeClub(['name' => 'Members FC'], $this->owner);
    }

    private function tab(): string
    {
        return '/admin/clubs/' . $this->club->id . '?tab=members';
    }

    private function url(string $suffix): string
    {
        return '/admin/clubs/' . $this->club->id . $suffix;
    }

    private function membership(User $user): ClubMember
    {
        return ClubMember::where('club_id', $this->club->id)->where('user_id', $user->id)->firstOrFail();
    }

    public function test_add_existing_user_by_username_or_email(): void
    {
        $byName  = User::factory()->create(['username' => 'lisa_k']);
        $byEmail = User::factory()->create(['email' => 'tom@example.test']);

        $this->actingAs($this->admin)->from($this->tab())->post($this->url('/members'), ['identifier' => 'lisa_k', 'role' => 'member'])
            ->assertRedirect($this->tab())->assertSessionHas('success', 'lisa_k added as member.');
        $this->actingAs($this->admin)->post($this->url('/members'), ['identifier' => 'TOM@example.test', 'role' => 'admin'])->assertRedirect();

        $this->assertSame(ClubRole::Member, $this->membership($byName)->role);
        $this->assertSame(ClubRole::Admin, $this->membership($byEmail)->role);
        $this->assertSame($this->admin->id, $this->membership($byEmail)->added_by_user_id);
        $this->assertSame(3, $this->club->fresh()->members_count);
        $this->assertSame(2, ClubAuditEvent::where('action', 'member.added')->where('actor_kind', 'platform_admin')->count());

        // Already active → warning, nothing written.
        $this->actingAs($this->admin)->post($this->url('/members'), ['identifier' => 'lisa_k', 'role' => 'admin'])->assertSessionHas('warning');
        $this->assertSame(ClubRole::Member, $this->membership($byName)->role);

        // Unknown user / missing role → errors in the addMember bag.
        $this->actingAs($this->admin)->from($this->tab())->post($this->url('/members'), ['identifier' => 'ghost', 'role' => 'member'])
            ->assertSessionHasErrorsIn('addMember', ['identifier']);
        $this->actingAs($this->admin)->from($this->tab())->post($this->url('/members'), ['identifier' => 'lisa_k', 'role' => 'superuser'])
            ->assertSessionHasErrorsIn('addMember', ['role']);

        // Anonymised accounts cannot be added.
        $gone = User::factory()->create(['username' => 'gone_user']);
        $gone->forceFill(['anonymized_at' => now()])->save();
        $this->actingAs($this->admin)->post($this->url('/members'), ['identifier' => 'gone_user', 'role' => 'member'])
            ->assertSessionHasErrorsIn('addMember', ['identifier']);
    }

    public function test_banned_users_must_be_unbanned_before_they_can_be_added(): void
    {
        $user = User::factory()->create(['username' => 'banned_one']);
        $this->addMember($this->club, $user, ClubRole::Member, ClubMemberStatus::Banned);

        $this->actingAs($this->admin)->from($this->tab())->post($this->url('/members'), ['identifier' => 'banned_one', 'role' => 'member'])
            ->assertSessionHas('error', __('clubs.errors.club_member_banned'));
        $this->assertTrue($this->membership($user)->isBanned());
    }

    public function test_role_changes_keep_at_least_one_owner(): void
    {
        $member = User::factory()->create();
        $row = $this->addMember($this->club, $member);

        $this->actingAs($this->admin)->from($this->tab())->patch($this->url('/members/' . $row->id), ['role' => 'admin'])
            ->assertSessionHas('success');
        $this->assertSame(ClubRole::Admin, $row->fresh()->role);
        $event = ClubAuditEvent::where('action', 'member.role_changed')->firstOrFail();
        $this->assertSame(['target_user_id' => $member->id, 'from_role' => 'member', 'to_role' => 'admin'], $event->metadata);

        // Demoting the only owner is refused.
        $ownerRow = $this->membership($this->owner);
        $this->actingAs($this->admin)->from($this->tab())->patch($this->url('/members/' . $ownerRow->id), ['role' => 'member'])
            ->assertRedirect($this->tab())
            ->assertSessionHas('error', __('clubs.errors.club_last_owner'));
        $this->assertSame(ClubRole::Owner, $ownerRow->fresh()->role);

        $this->actingAs($this->admin)->from($this->tab())->patch($this->url('/members/' . $row->id), ['role' => 'king'])->assertSessionHasErrors('role');
    }

    public function test_remove_ban_unban_and_reactivate(): void
    {
        $user = User::factory()->create(['username' => 'mover']);
        $row  = $this->addMember($this->club, $user, ClubRole::Admin);

        $this->actingAs($this->admin)->post($this->url('/members/' . $row->id . '/remove'), ['reason' => 'Left the team'])->assertSessionHas('success');
        $row->refresh();
        $this->assertSame(ClubMemberStatus::Removed, $row->status);
        $this->assertSame('Left the team', $row->removal_reason);
        $this->assertSame($this->admin->id, $row->removed_by_user_id);
        $this->assertSame('Left the team', ClubAuditEvent::where('action', 'member.removed')->firstOrFail()->metadata['reason']);

        // Reactivate keeps the old role.
        $this->actingAs($this->admin)->post($this->url('/members/' . $row->id . '/reactivate'))->assertSessionHas('success', 'mover is an active admin again.');
        $this->assertTrue($row->fresh()->isActive());
        $this->assertSame(ClubRole::Admin, $row->fresh()->role);

        $this->actingAs($this->admin)->post($this->url('/members/' . $row->id . '/ban'), ['reason' => 'Abuse'])->assertSessionHas('success');
        $this->assertTrue($row->fresh()->isBanned());
        $this->assertSame(1, $this->club->fresh()->members_count);

        // A banned row cannot be reactivated directly.
        $this->actingAs($this->admin)->from($this->tab())->post($this->url('/members/' . $row->id . '/reactivate'))
            ->assertSessionHas('error', __('clubs.errors.club_member_banned'));

        $this->actingAs($this->admin)->post($this->url('/members/' . $row->id . '/unban'))->assertSessionHas('success');
        $this->assertSame(ClubMemberStatus::Removed, $row->fresh()->status);

        $this->assertSame(
            ['member.removed', 'member.added', 'member.banned', 'member.unbanned'],
            ClubAuditEvent::where('club_id', $this->club->id)->whereIn('action', ['member.removed', 'member.added', 'member.banned', 'member.unbanned'])->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_the_last_owner_cannot_be_removed_or_banned(): void
    {
        $ownerRow = $this->membership($this->owner);

        foreach (['/remove', '/ban'] as $action) {
            $this->actingAs($this->admin)->from($this->tab())->post($this->url('/members/' . $ownerRow->id . $action), ['reason' => 'x'])
                ->assertSessionHas('error', __('clubs.errors.club_last_owner'));
        }
        $this->assertTrue($ownerRow->fresh()->isOwner());
        $this->assertFalse($this->club->fresh()->needs_owner);
    }

    public function test_former_owner_is_reactivated_as_admin(): void
    {
        $second = User::factory()->create();
        $row = $this->addMember($this->club, $second, ClubRole::Owner);
        $this->actingAs($this->admin)->post($this->url('/members/' . $row->id . '/remove'))->assertSessionHas('success');

        $this->actingAs($this->admin)->post($this->url('/members/' . $row->id . '/reactivate'))->assertSessionHas('success');
        $this->assertSame(ClubRole::Admin, $row->fresh()->role);
    }

    public function test_transfer_ownership(): void
    {
        $next = User::factory()->create(['username' => 'next_owner']);
        $row  = $this->addMember($this->club, $next);

        $this->actingAs($this->admin)->post($this->url('/transfer-ownership'), ['member_id' => $row->id])
            ->assertSessionHas('success', 'next_owner is now the owner. Previous owners became admins.');

        $this->assertSame(ClubRole::Owner, $row->fresh()->role);
        $this->assertSame(ClubRole::Admin, $this->membership($this->owner)->role);
        $event = ClubAuditEvent::where('action', 'member.ownership_transferred')->firstOrFail();
        $this->assertSame($next->id, $event->metadata['new_owner_id']);
        $this->assertSame([$this->owner->id], $event->metadata['previous_owner_ids']);

        // Inactive target / another club's member → club_member_not_found.
        $left = $this->addMember($this->club, User::factory()->create(), ClubRole::Member, ClubMemberStatus::Left);
        $other = $this->addMember($this->makeClub(), User::factory()->create());
        foreach ([$left->id, $other->id] as $id) {
            $this->actingAs($this->admin)->from($this->tab())->post($this->url('/transfer-ownership'), ['member_id' => $id])
                ->assertSessionHas('error', __('clubs.errors.club_member_not_found'));
        }
        $this->actingAs($this->admin)->from($this->tab())->post($this->url('/transfer-ownership'), [])->assertSessionHasErrors('member_id');
    }

    public function test_members_tab_searches_filters_and_paginates(): void
    {
        $this->addMember($this->club, User::factory()->create(['username' => 'alice_w', 'email' => 'alice@example.test']));
        $this->addMember($this->club, User::factory()->create(['username' => 'bob_w', 'email' => 'bobby@example.test']), ClubRole::Admin);
        $this->addMember($this->club, User::factory()->create(['username' => 'carl_left']), ClubRole::Member, ClubMemberStatus::Left);

        $usernames = fn (string $q) => $this->actingAs($this->admin)->get($this->tab() . $q)->assertOk()
            ->viewData('members')->map(fn ($m) => $m->user->username)->all();

        $this->assertSame(['the_owner', 'bob_w', 'alice_w'], $usernames(''), 'active by default, owners first');
        $this->assertSame(['alice_w'], $usernames('&q=ALICE'));
        $this->assertSame(['bob_w'], $usernames('&q=bobby@'));
        $this->assertSame(['bob_w'], $usernames('&role=admin'));
        $this->assertSame(['carl_left'], $usernames('&status=left'));
        $this->assertCount(4, $usernames('&status=any'));
        $this->assertSame([], $usernames('&q=' . urlencode('%')), 'LIKE wildcards are literal');
        $this->assertSame(['bob_w', 'alice_w'], $usernames('&q=' . urlencode('_w')), 'an underscore matches only itself');

        foreach (range(1, 26) as $i) {
            $this->addMember($this->club, User::factory()->create());
        }
        $page = $this->actingAs($this->admin)->get($this->tab() . '&role=member')->viewData('members');
        $this->assertSame(27, $page->total());
        $this->assertStringContainsString('tab=members', $page->url(2));
        $this->assertStringContainsString('role=member', $page->url(2));

        $html = $this->actingAs($this->admin)->get($this->tab())->getContent();
        foreach (['Add an existing player', 'id="member-action-modal"', 'data-action="role"', 'data-action="transfer"', 'data-action="ban"', 'Make sole owner…'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringContainsString('alice@example.test', $this->actingAs($this->admin)->get($this->tab() . '&q=alice')->getContent(), 'platform admins see emails');

        $this->actingAs($this->admin)->get($this->url('/members') . '?q=alice')->assertRedirect($this->tab() . '&q=alice');
    }
}
