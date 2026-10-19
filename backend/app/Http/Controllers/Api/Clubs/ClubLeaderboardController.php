<?php

namespace App\Http\Controllers\Api\Clubs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clubs\Api\CursorPageRequest;
use App\Models\Club;
use App\Services\Clubs\ClubAccess;
use App\Services\Clubs\ClubLeaderboardService;
use Illuminate\Http\JsonResponse;

/** GET /api/clubs/{club}/leaderboard — club all-time board (D19), shared ranks. */
class ClubLeaderboardController extends Controller
{
    public function index(CursorPageRequest $request, Club $club, ClubLeaderboardService $leaderboard): JsonResponse
    {
        ClubAccess::requireMember($club, $request->user());

        return response()->json($leaderboard->page($club, $request->user(), $request->pageCursor(), $request->pageLimit()));
    }
}
