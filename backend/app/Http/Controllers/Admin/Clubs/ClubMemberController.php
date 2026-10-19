<?php

namespace App\Http\Controllers\Admin\Clubs;

use App\Enums\ClubRole;
use App\Exceptions\ClubDomainException;
use App\Http\Requests\Clubs\Admin\AddClubMemberRequest;
use App\Http\Requests\Clubs\Admin\ClubMemberReasonRequest;
use App\Http\Requests\Clubs\Admin\TransferClubOwnershipRequest;
use App\Http\Requests\Clubs\Admin\UpdateClubMemberRoleRequest;
use App\Models\Club;
use App\Models\ClubMember;
use App\Services\Clubs\ClubMembershipService;
use Illuminate\Http\Request;

/**
 * Club detail → Members tab actions (plan §7.2): add an existing user, change
 * role, remove / ban / unban / reactivate, transfer ownership. Validation in
 * FormRequests; the sole-owner rule (club_last_owner), counters and the audit
 * trail live in ClubMembershipService. {member} is scoped to {club}.
 */
class ClubMemberController extends AdminClubController
{
    public function __construct(private ClubMembershipService $memberships) {}

    /** GET …/members → the Members tab (filters kept). */
    public function index(Request $request, Club $club)
    {
        $this->authorize('admin.manage', $club);

        return redirect()->route('admin.clubs.show', ['club' => $club, 'tab' => 'members'] + $request->only(['q', 'role', 'status', 'page']));
    }

    public function store(AddClubMemberRequest $request, Club $club)
    {
        $this->authorize('admin.manage', $club);

        $target   = $request->target();
        $existing = $this->memberships->membershipRecord($club, $target);
        if ($existing?->isActive()) {
            return $this->toTab($club)->with('warning', "{$target->username} is already an active member ({$existing->role->value}). Change the role in the list instead.");
        }

        $member = $this->memberships->add($club, $target, $request->role(), $request->user(), self::ACTOR);
        $this->logAdmin($request, 'member_added', ['club_id' => $club->id, 'member_id' => $member->id, 'role' => $member->role->value]);

        return $this->toTab($club)->with('success', "{$target->username} added as {$member->role->value}.");
    }

    public function update(UpdateClubMemberRoleRequest $request, Club $club, ClubMember $member)
    {
        $this->authorize('admin.manage', $club);
        $member->setRelation('club', $club);

        $this->memberships->changeRole($member, $request->role(), $request->user(), self::ACTOR);
        $this->logAdmin($request, 'member_role_changed', ['club_id' => $club->id, 'member_id' => $member->id, 'role' => $member->role->value]);

        return back()->with('success', "{$this->name($member)} is now {$member->role->value}.");
    }

    public function remove(ClubMemberReasonRequest $request, Club $club, ClubMember $member)
    {
        $this->authorize('admin.manage', $club);
        $member->setRelation('club', $club);

        $this->memberships->remove($member, $request->user(), self::ACTOR, $request->input('reason'));
        $this->logAdmin($request, 'member_removed', ['club_id' => $club->id, 'member_id' => $member->id]);

        return back()->with('success', "{$this->name($member)} was removed. They can re-join with a valid code.");
    }

    public function ban(ClubMemberReasonRequest $request, Club $club, ClubMember $member)
    {
        $this->authorize('admin.manage', $club);
        $member->setRelation('club', $club);

        $this->memberships->ban($member, $request->user(), self::ACTOR, $request->input('reason'));
        $this->logAdmin($request, 'member_banned', ['club_id' => $club->id, 'member_id' => $member->id]);

        return back()->with('success', "{$this->name($member)} is banned and cannot re-join with any code.");
    }

    public function unban(Request $request, Club $club, ClubMember $member)
    {
        $this->authorize('admin.manage', $club);
        $member->setRelation('club', $club);

        $this->memberships->unban($member, $request->user(), self::ACTOR);
        $this->logAdmin($request, 'member_unbanned', ['club_id' => $club->id, 'member_id' => $member->id]);

        return back()->with('success', "Ban lifted for {$this->name($member)}. They are not a member until they re-join or are reactivated.");
    }

    /**
     * Reactivate a left/removed membership (banned → unban first). The old
     * role is kept, except that a former owner comes back as admin: ownership
     * is only ever granted explicitly.
     */
    public function reactivate(Request $request, Club $club, ClubMember $member)
    {
        $this->authorize('admin.manage', $club);

        if ($member->isBanned()) {
            throw new ClubDomainException(ClubDomainException::CLUB_MEMBER_BANNED, 422);
        }
        if ($member->isActive()) {
            return back()->with('warning', "{$this->name($member)} is already an active member.");
        }

        $role = $member->role === ClubRole::Owner ? ClubRole::Admin : $member->role;
        $this->memberships->add($club, $member->user, $role, $request->user(), self::ACTOR);
        $this->logAdmin($request, 'member_reactivated', ['club_id' => $club->id, 'member_id' => $member->id]);

        return back()->with('success', "{$this->name($member)} is an active {$role->value} again.");
    }

    public function transferOwnership(TransferClubOwnershipRequest $request, Club $club)
    {
        $this->authorize('admin.manage', $club);

        $member = ClubMember::where('club_id', $club->id)->whereKey((int) $request->input('member_id'))->with('user')->first();
        if (!$member?->isActive() || $member->user === null) {
            throw new ClubDomainException(ClubDomainException::CLUB_MEMBER_NOT_FOUND, 404);
        }

        $this->memberships->transferOwnership($club, $member->user, $request->user(), self::ACTOR);
        $this->logAdmin($request, 'ownership_transferred', ['club_id' => $club->id, 'member_id' => $member->id]);

        return back()->with('success', "{$member->user->username} is now the owner. Previous owners became admins.");
    }

    // ------------------------------------------------------------------

    private function toTab(Club $club)
    {
        return redirect()->route('admin.clubs.show', ['club' => $club, 'tab' => 'members']);
    }

    private function name(ClubMember $member): string
    {
        return $member->user?->username ?? ('member #' . $member->id);
    }
}
