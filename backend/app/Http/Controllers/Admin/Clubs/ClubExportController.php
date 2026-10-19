<?php

namespace App\Http\Controllers\Admin\Clubs;

use App\Models\Club;
use App\Models\ClubCompetition;
use App\Services\Clubs\ClubStandingsCsvExporter;
use Illuminate\Http\Request;

/**
 * Central admin CSV downloads (Wave F): a club's all-time leaderboard and a
 * competition's standings — the same exporter as the club portal (usernames
 * only, formula-safe), audited `export.downloaded` as platform_admin.
 */
class ClubExportController extends AdminClubController
{
    public function leaderboard(Request $request, Club $club, ClubStandingsCsvExporter $csv)
    {
        $this->authorize('admin.manage', $club);
        $this->logAdmin($request, 'export_downloaded', ['club_id' => $club->id, 'export' => 'leaderboard']);

        return $csv->leaderboard($club, $request->user(), self::ACTOR);
    }

    public function competition(Request $request, Club $club, ClubCompetition $competition, ClubStandingsCsvExporter $csv)
    {
        $this->authorize('admin.manage', $club);
        $competition->setRelation('club', $club);
        $this->logAdmin($request, 'export_downloaded', ['club_id' => $club->id, 'export' => 'competition', 'competition_id' => $competition->id]);

        return $csv->competition($competition, $request->user(), self::ACTOR);
    }
}
