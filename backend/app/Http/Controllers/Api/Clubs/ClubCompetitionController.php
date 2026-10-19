<?php

namespace App\Http\Controllers\Api\Clubs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clubs\Api\CompetitionIndexRequest;
use App\Http\Requests\Clubs\Api\CursorPageRequest;
use App\Http\Requests\Clubs\Api\StartCompetitionAttemptRequest;
use App\Http\Resources\Clubs\CompetitionAttemptResource;
use App\Models\ChallengePack;
use App\Models\Club;
use App\Models\ClubCompetition;
use App\Services\Clubs\ClubAccess;
use App\Services\Clubs\ClubCompetitionPlayService;
use App\Services\Clubs\ClubCompetitionQuery;
use App\Services\Clubs\ClubStandingsService;
use App\Services\PackPlayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Club competitions — member API (contracts §5). {competition} binds by
 * public_id within the club and {pack} by slug within the competition's
 * linked packs (scoped bindings): anything outside is 404 club_not_found,
 * like a club the caller is not a member of. Drafts are 404 too.
 */
class ClubCompetitionController extends Controller
{
    public function __construct(
        private ClubCompetitionQuery $query,
        private ClubStandingsService $standings,
        private ClubCompetitionPlayService $play,
        private PackPlayService $packs,
    ) {}

    // GET /api/clubs/{club}/competitions?phase=active|upcoming|completed&cursor=&limit=
    public function index(CompetitionIndexRequest $request, Club $club): JsonResponse
    {
        ClubAccess::requireMember($club, $request->user());

        return response()->json($this->query->page($club, $request->user(), $request->phase(), $request->pageCursor(), $request->pageLimit()));
    }

    // GET /api/clubs/{club}/competitions/{competition}
    public function show(Request $request, Club $club, ClubCompetition $competition): JsonResponse
    {
        ClubAccess::requireMember($club, $request->user());
        $this->query->assertVisible($club, $competition);

        return response()->json(['data' => $this->query->detail($club, $competition, $request->user())]);
    }

    // POST /api/clubs/{club}/competitions/{competition}/packs/{slug}/attempts {attempt_number}
    public function start(StartCompetitionAttemptRequest $request, Club $club, ClubCompetition $competition, ChallengePack $pack): JsonResponse
    {
        ClubAccess::requireMember($club, $request->user());
        $this->query->assertVisible($club, $competition);

        $result  = $this->play->start($request->user(), $club, $competition, $pack, (int) $request->validated('attempt_number'));
        $attempt = $result['attempt'];

        return response()->json([
            'attempt'   => (new CompetitionAttemptResource($attempt))->resolve(),
            'challenge' => $this->packs->challengePayload($this->play->currentChallenge($attempt)),
        ], $result['created'] ? 201 : 200);
    }

    // GET /api/clubs/{club}/competitions/{competition}/leaderboard?cursor=&limit=
    public function leaderboard(CursorPageRequest $request, Club $club, ClubCompetition $competition): JsonResponse
    {
        ClubAccess::requireMember($club, $request->user());
        $this->query->assertVisible($club, $competition);

        $page = $this->standings->page($competition, $request->user(), $request->pageCursor(), $request->pageLimit());
        $page['meta'] += [
            'rules' => [
                'replay_mode'        => $competition->replay_mode->value,
                'max_attempts'       => (int) $competition->max_attempts,
                'score_rule'         => $competition->score_rule->value,
                'normal_play_policy' => $competition->normal_play_policy->value,
            ],
            'phase' => $competition->phase()->value,
        ];

        return response()->json($page);
    }

    // GET /api/clubs/{club}/competitions/{competition}/me/attempts
    public function myAttempts(Request $request, Club $club, ClubCompetition $competition): JsonResponse
    {
        ClubAccess::requireMember($club, $request->user());
        $this->query->assertVisible($club, $competition);

        return response()->json(['data' => $this->query->myAttempts($competition, $request->user())]);
    }

    // GET /api/me/club-finishes
    public function myFinishes(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->myFinishes($request->user())]);
    }
}
