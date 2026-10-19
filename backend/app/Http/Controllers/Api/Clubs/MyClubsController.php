<?php

namespace App\Http\Controllers\Api\Clubs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clubs\Api\JoinClubRequest;
use App\Http\Requests\Clubs\Api\MyClubsIndexRequest;
use App\Http\Resources\Clubs\ClubCardResource;
use App\Models\ClubMember;
use App\Services\Clubs\ClubMembershipService;
use App\Services\Clubs\MyClubsQuery;
use Illuminate\Http\JsonResponse;

/**
 * My Clubs + Join (contracts §5). There is deliberately no endpoint that
 * lists or searches clubs the caller has not joined.
 */
class MyClubsController extends Controller
{
    // GET /api/clubs?q=&sort=recent|name&cursor=&limit=
    public function index(MyClubsIndexRequest $request, MyClubsQuery $query): JsonResponse
    {
        $page = $query->page(
            $request->user(),
            $request->validated('q'),
            $request->validated('sort'),
            $request->pageCursor(),
            $request->pageLimit(),
        );

        return response()->json([
            'data' => $page['items']->map(fn (ClubMember $m) => (new ClubCardResource($m->club, $m))->resolve())->values(),
            'meta' => [
                'next_cursor'  => $page['next_cursor'],
                'per_page'     => $page['per_page'],
                'total_joined' => $page['total_joined'],
                'query'        => $page['query'],
            ],
        ]);
    }

    // POST /api/clubs/join {code} — throttle:club-join; idempotent for active members.
    public function join(JoinClubRequest $request, ClubMembershipService $memberships): JsonResponse
    {
        $result = $memberships->joinWithCode($request->user(), (string) $request->validated('code'), $request->ip());

        $club = MyClubsQuery::loadCard($result['club']);

        return response()->json([
            'data'   => (new ClubCardResource($club, $result['member']))->resolve(),
            'joined' => $result['joined'],
        ]);
    }
}
