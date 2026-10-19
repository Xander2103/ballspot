<?php

namespace App\Http\Controllers\Api\Clubs;

use App\Exceptions\ClubDomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clubs\Api\ClubAdminRequest;
use App\Http\Requests\Clubs\Api\MemberReasonRequest;
use App\Http\Requests\Clubs\Api\MemberRoleRequest;
use App\Models\Club;
use App\Models\ClubAuditEvent;
use App\Models\ClubMember;
use App\Services\ClubPortal\ClubPortalMemberRules;
use App\Services\Clubs\ClubMemberListQuery;
use App\Services\Clubs\ClubMembershipService;
use Illuminate\Http\JsonResponse;

/**
 * Club-admin API (Wave F): member actions. {userId} is the target's user id
 * (the `user_id` of the member list); only ACTIVE members of the club are
 * addressable (else 404 club_member_not_found). Who-may-touch-whom is
 * ClubPortalMemberRules (no self-actions, owners only touchable by owners,
 * role admin ↔ member owner-only), the last-owner rule, counters and the
 * audit trail (actor kind `club_admin`) are ClubMembershipService — the
 * same code paths as the portal.
 */
class ClubMemberController extends Controller
{
    public function __construct(private ClubMembershipService $memberships) {}

    // PATCH /api/clubs/{club}/members/{userId} { role } → { data: Member }
    public function update(MemberRoleRequest $request, Club $club, string $userId): JsonResponse
    {
        $target = $this->target($request, $club, $userId, ClubPortalMemberRules::ROLE);

        $target = $this->memberships->actAsManager($club, $request->user(), $target, ClubPortalMemberRules::ROLE,
            fn (ClubMember $t) => $this->memberships->changeRole($t, $request->role(), $request->user(), ClubAuditEvent::ACTOR_CLUB_ADMIN));

        $target->refresh()->load('user:id,username,avatar_path');

        return response()->json(['data' => ClubMemberListQuery::item(
            $target, $target->user?->username, $target->user?->avatar_path,
            ClubMemberListQuery::callerAbilities($club, $request->user()),
        )]);
    }

    // POST /api/clubs/{club}/members/{userId}/remove { reason? } → { removed: true }
    public function remove(MemberReasonRequest $request, Club $club, string $userId): JsonResponse
    {
        $target = $this->target($request, $club, $userId, ClubPortalMemberRules::REMOVE);

        $this->memberships->actAsManager($club, $request->user(), $target, ClubPortalMemberRules::REMOVE,
            fn (ClubMember $t) => $this->memberships->remove($t, $request->user(), ClubAuditEvent::ACTOR_CLUB_ADMIN, $request->reason()));

        return response()->json(['removed' => true]);
    }

    // POST /api/clubs/{club}/members/{userId}/ban { reason? } → { banned: true }
    public function ban(MemberReasonRequest $request, Club $club, string $userId): JsonResponse
    {
        $target = $this->target($request, $club, $userId, ClubPortalMemberRules::BAN);

        $this->memberships->actAsManager($club, $request->user(), $target, ClubPortalMemberRules::BAN,
            fn (ClubMember $t) => $this->memberships->ban($t, $request->user(), ClubAuditEvent::ACTOR_CLUB_ADMIN, $request->reason()));

        return response()->json(['banned' => true]);
    }

    /** The active membership of {userId} in {club}, if the caller may apply $action to it. */
    private function target(ClubAdminRequest $request, Club $club, string $userId, string $action): ClubMember
    {
        // whereNumber() on the route; bounded so an overlong id is a plain 404, never a type error.
        $target = strlen($userId) <= 19 && ctype_digit($userId)
            ? ClubMember::where('club_id', $club->id)->where('user_id', (int) $userId)->first()
            : null;
        if ($target === null || !$target->isActive()) {
            throw new ClubDomainException(ClubDomainException::CLUB_MEMBER_NOT_FOUND, 404);
        }
        if (!ClubPortalMemberRules::allows($request->actor(), $target, $action)) {
            throw ClubDomainException::forbidden();
        }
        $target->setRelation('club', $club);

        return $target;
    }
}
