<?php

namespace App\Http\Controllers\ClubPortal;

use App\Models\Club;
use App\Models\ClubCompetition;
use App\Services\Clubs\ClubLeaderboardService;
use App\Services\Clubs\ClubStandingsCsvExporter;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Portal Leaderboard (Wave F): the club's all-time board (D19, the same
 * ranking as the app) and the CSV downloads of it and of a competition's
 * standings — usernames only, audited `export.downloaded` (actor club_admin).
 * Read-only, so also available on a suspended club.
 */
class LeaderboardController extends PortalController
{
    public const PER_PAGE = 50;

    public function index(Request $request, Club $club, ClubLeaderboardService $leaderboard)
    {
        $ranked = $leaderboard->standings($club);
        $page   = max(1, (int) $request->query('page', 1));

        return view('club-portal.leaderboard', [
            'club'    => $club,
            'entries' => new LengthAwarePaginator(
                $ranked->forPage($page, self::PER_PAGE)->values(), $ranked->count(), self::PER_PAGE, $page,
                ['path' => $request->url(), 'query' => $request->query()],
            ),
        ]);
    }

    // GET /club/{club}/leaderboard.csv
    public function csv(Request $request, Club $club, ClubStandingsCsvExporter $csv)
    {
        $this->logPortal($request, 'export_downloaded', ['club_id' => $club->id, 'export' => 'leaderboard']);

        return $csv->leaderboard($club, $request->user(), self::ACTOR);
    }

    // GET /club/{club}/competitions/{competition}/standings.csv
    public function competitionCsv(Request $request, Club $club, ClubCompetition $competition, ClubStandingsCsvExporter $csv)
    {
        $competition->setRelation('club', $club);
        $this->logPortal($request, 'export_downloaded', ['club_id' => $club->id, 'export' => 'competition', 'competition_id' => $competition->id]);

        return $csv->competition($competition, $request->user(), self::ACTOR);
    }
}
