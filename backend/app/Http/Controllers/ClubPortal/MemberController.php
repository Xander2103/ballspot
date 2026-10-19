<?php

namespace App\Http\Controllers\ClubPortal;

use App\Enums\ClubRole;
use App\Exceptions\ClubDomainException;
use App\Http\Requests\Clubs\Portal\PortalMemberReasonRequest;
use App\Http\Requests\Clubs\Portal\PortalMemberRoleRequest;
use App\Http\Requests\Clubs\Portal\PortalTransferOwnershipRequest;
use App\Http\Requests\Clubs\Portal\PortalUnbanRequest;
use App\Models\Club;
use App\Models\ClubMember;
use App\Services\ClubPortal\ClubPortalMemberRules;
use App\Services\Clubs\ClubMembershipService;
use App\Services\Clubs\MyClubsQuery;
use Illuminate\Http\Request;

/**
 * Portal Members page: search + paginate; remove / ban / unban (owners and
 * admins), role admin ↔ member and ownership transfer (owners). Usernames
 * only — never emails (D12). Who-may-touch-whom is ClubPortalMemberRules;
 * the last-owner rule, counters and audit live in ClubMembershipService.
 * {member} is scoped to {club}.
 */
class MemberController extends PortalController
{
    public const PER_PAGE = 25;
    private const STATUSES = ['active', 'banned'];

    public function __construct(private ClubMembershipService $memberships) {}

    public function index(Request $request, Club $club)
    {
        $q      = MyClubsQuery::normaliseQuery(is_string($request->query('q')) ? $request->query('q') : null);
        $status = in_array($request->query('status'), self::STATUSES, true) ? (string) $request->query('status') : 'active';
        $role   = in_array($request->query('role'), ClubRole::values(), true) ? (string) $request->query('role') : '';

        $members = ClubMember::query()
            ->select('club_members.*')
            ->join('users', 'users.id', '=', 'club_members.user_id')
            ->where('club_members.club_id', $club->id)
            ->where('club_members.status', $status)
            ->when($role !== '', fn ($query) => $query->where('club_members.role', $role))
            ->when($q !== null, fn ($query) => $query->whereRaw("users.username LIKE ? ESCAPE '!'", ['%' . MyClubsQuery::escapeLike($q) . '%']))
            ->with('user:id,username,avatar_path')
            ->orderByRaw("CASE club_members.role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END")
            ->orderByRaw('LOWER(users.username)')
            ->orderBy('club_members.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $counts = ClubMember::where('club_id', $club->id)
            ->whereIn('status', self::STATUSES)
            ->selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status');

        return view('club-portal.members', [
            'club'    => $club,
            'members' => $members,
            'actor'   => $this->actorMember($request),
            'filters' => ['q' => $q ?? '', 'status' => $status, 'role' => $role],
            'counts'  => $counts,
        ]);
    }

    public function remove(PortalMemberReasonRequest $request, Club $club, ClubMember $member)
    {
        $this->guard($request, $member, ClubPortalMemberRules::REMOVE);
        $member->setRelation('club', $club);

        $this->memberships->actAsManager($club, $request->user(), $member, ClubPortalMemberRules::REMOVE,
            fn (ClubMember $t) => $this->memberships->remove($t, $request->user(), self::ACTOR, $request->input('reason')));
        $member->refresh();
        $this->logPortal($request, 'member_removed', ['club_id' => $club->id, 'member_id' => $member->id]);

        return back()->with('success', __('club_portal.members.flash.removed', ['name' => $this->name($member)]));
    }

    public function ban(PortalMemberReasonRequest $request, Club $club, ClubMember $member)
    {
        $this->guard($request, $member, ClubPortalMemberRules::BAN);
        $member->setRelation('club', $club);

        $this->memberships->actAsManager($club, $request->user(), $member, ClubPortalMemberRules::BAN,
            fn (ClubMember $t) => $this->memberships->ban($t, $request->user(), self::ACTOR, $request->input('reason')));
        $member->refresh();
        $this->logPortal($request, 'member_banned', ['club_id' => $club->id, 'member_id' => $member->id]);

        return back()->with('success', __('club_portal.members.flash.banned', ['name' => $this->name($member)]));
    }

    public function unban(PortalUnbanRequest $request, Club $club, ClubMember $member)
    {
        $this->guard($request, $member, ClubPortalMemberRules::UNBAN);
        $member->setRelation('club', $club);

        $this->memberships->actAsManager($club, $request->user(), $member, ClubPortalMemberRules::UNBAN,
            fn (ClubMember $t) => $this->memberships->unban($t, $request->user(), self::ACTOR));
        $member->refresh();
        $this->logPortal($request, 'member_unbanned', ['club_id' => $club->id, 'member_id' => $member->id]);

        return back()->with('success', __('club_portal.members.flash.unbanned', ['name' => $this->name($member)]));
    }

    public function role(PortalMemberRoleRequest $request, Club $club, ClubMember $member)
    {
        $this->guard($request, $member, ClubPortalMemberRules::ROLE);
        $member->setRelation('club', $club);

        $this->memberships->actAsManager($club, $request->user(), $member, ClubPortalMemberRules::ROLE,
            fn (ClubMember $t) => $this->memberships->changeRole($t, $request->role(), $request->user(), self::ACTOR));
        $member->refresh();
        $this->logPortal($request, 'member_role_changed', ['club_id' => $club->id, 'member_id' => $member->id, 'role' => $member->role->value]);

        return back()->with('success', __('club_portal.members.flash.role_changed', [
            'name' => $this->name($member),
            'role' => __('club_portal.roles.' . $member->role->value),
        ]));
    }

    public function transfer(PortalTransferOwnershipRequest $request, Club $club)
    {
        $member = ClubMember::where('club_id', $club->id)->whereKey((int) $request->input('member_id'))->with('user')->first();
        if ($member === null || $member->user === null || !$member->isActive()) {
            throw new ClubDomainException(ClubDomainException::CLUB_MEMBER_NOT_FOUND, 404);
        }
        $this->guard($request, $member, ClubPortalMemberRules::TRANSFER);

        if (mb_strtolower(trim((string) $request->input('confirm'))) !== mb_strtolower((string) $member->user->username)) {
            return back()->withErrors(['confirm' => __('club_portal.members.transfer.confirm_mismatch')], 'transfer');
        }

        $this->memberships->transferOwnership($club, $member->user, $request->user(), self::ACTOR);
        $this->logPortal($request, 'ownership_transferred', ['club_id' => $club->id, 'member_id' => $member->id]);

        return redirect()->route('club-portal.members.index', $club)
            ->with('success', __('club_portal.members.flash.transferred', ['name' => $member->user->username]));
    }

    // ------------------------------------------------------------------

    /** Role rules on top of the policy (403-style refusal as an error flash). */
    private function guard(Request $request, ClubMember $target, string $action): void
    {
        if (!ClubPortalMemberRules::allows($this->actorMember($request), $target, $action)) {
            throw ClubDomainException::forbidden();
        }
    }

    private function name(ClubMember $member): string
    {
        return $member->user?->username ?? ('#' . $member->id);
    }
}
