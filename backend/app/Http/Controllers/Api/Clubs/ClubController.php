<?php

namespace App\Http\Controllers\Api\Clubs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clubs\Api\ClubMembersIndexRequest;
use App\Models\Club;
use App\Services\Clubs\ClubAccess;
use App\Services\Clubs\ClubDetailService;
use App\Services\Clubs\ClubMemberListQuery;
use App\Services\Clubs\ClubMembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Club detail, leave and the member list. Every action resolves the caller's
 * ACTIVE membership first: non-members get 404 club_not_found (never 403).
 */
class ClubController extends Controller
{
    // GET /api/clubs/{club}
    public function show(Request $request, Club $club, ClubDetailService $details): JsonResponse
    {
        $member = ClubAccess::requireMember($club, $request->user());

        return response()->json(['data' => $details->detail($club, $member, $request->user())]);
    }

    // POST /api/clubs/{club}/leave — a sole owner gets 422 club_owner_must_transfer.
    public function leave(Request $request, Club $club, ClubMembershipService $memberships): JsonResponse
    {
        ClubAccess::requireMember($club, $request->user());

        $memberships->leave($club, $request->user());

        return response()->json(['left' => true]);
    }

    // GET /api/clubs/{club}/members?q=&cursor=&limit= — 403 club_forbidden when hidden (D9).
    public function members(ClubMembersIndexRequest $request, Club $club, ClubMemberListQuery $query): JsonResponse
    {
        ClubAccess::requireMember($club, $request->user());
        ClubAccess::requireAbility($request->user(), 'viewMembers', $club);

        $page = $query->page($club, $request->validated('q'), $request->pageCursor(), $request->pageLimit());

        return response()->json([
            'data' => $page['items'],
            'meta' => ['next_cursor' => $page['next_cursor'], 'per_page' => $page['per_page'], 'total' => $page['total']],
        ]);
    }
}
