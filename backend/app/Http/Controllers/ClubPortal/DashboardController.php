<?php

namespace App\Http\Controllers\ClubPortal;

use App\Models\Club;
use App\Models\ClubCompetition;
use App\Services\Clubs\ClubPhotoReviewService;
use App\Services\Clubs\ClubStatsService;
use Illuminate\Http\Request;

/** GET /club/{club} — stats, live/upcoming competitions, photo statuses, recent activity. */
class DashboardController extends PortalController
{
    public function __invoke(Request $request, Club $club, ClubStatsService $stats, ClubPhotoReviewService $photos)
    {
        $overview = $stats->overview($club, 10);

        $upcoming = ClubCompetition::query()->where('club_id', $club->id)->upcoming(now())
            ->orderBy('starts_at')->limit(5)
            ->get(['id', 'public_id', 'club_id', 'name', 'state', 'starts_at', 'ends_at', 'participants_count']);

        return view('club-portal.dashboard', [
            'club'         => $club,
            'stats'        => $overview,
            'upcoming'     => $upcoming,
            'photoCounts'  => $photos->statusCounts($club),
            'events'       => $overview['recent_events'],
        ]);
    }
}
