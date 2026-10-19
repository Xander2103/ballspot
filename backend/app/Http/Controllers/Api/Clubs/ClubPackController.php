<?php

namespace App\Http\Controllers\Api\Clubs;

use App\Http\Controllers\Api\PackPlayController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clubs\Api\CursorPageRequest;
use App\Models\ChallengePack;
use App\Models\Club;
use App\Services\Clubs\ClubAccess;
use App\Services\Clubs\ClubPackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Club packs on the NORMAL track. {pack} binds by slug inside the club
 * (scoped binding): another club's pack is a 404 by construction. Gameplay
 * is the public pack engine — start/attempt bodies come from
 * PackPlayController, guesses go to the existing POST
 * /api/pack-attempts/{attempt}/guess.
 */
class ClubPackController extends Controller
{
    public function __construct(
        private ClubPackService $packs,
        private PackPlayController $play,
    ) {}

    // GET /api/clubs/{club}/packs?cursor=&limit=
    public function index(CursorPageRequest $request, Club $club): JsonResponse
    {
        ClubAccess::requireMember($club, $request->user());

        $page = $this->packs->page($club, $request->user(), $request->pageCursor(), $request->pageLimit());

        return response()->json([
            'data' => $page['items'],
            'meta' => ['next_cursor' => $page['next_cursor'], 'per_page' => $page['per_page']],
        ]);
    }

    // GET /api/clubs/{club}/packs/{slug}
    public function show(Request $request, Club $club, ChallengePack $pack): JsonResponse
    {
        ClubAccess::requireMember($club, $request->user());
        $this->packs->assertVisible($club, $pack);

        return response()->json(['data' => $this->packs->detail($club, $pack)]);
    }

    // POST /api/clubs/{club}/packs/{slug}/start — 423 when suspended, 409 pack_reserved_for_competition.
    public function start(Request $request, Club $club, ChallengePack $pack): JsonResponse
    {
        ClubAccess::requireMember($club, $request->user());
        $this->packs->assertVisible($club, $pack);

        // PackAccessGate (ClubPackAccess) runs inside: active club, active pack, reservation.
        return $this->play->startFor($request, $pack);
    }

    // GET /api/clubs/{club}/packs/{slug}/attempt — readable in a suspended club.
    public function attempt(Request $request, Club $club, ChallengePack $pack): JsonResponse
    {
        ClubAccess::requireMember($club, $request->user());
        $this->packs->assertVisible($club, $pack);

        return $this->play->attemptFor($request, $pack);
    }
}
