<?php

namespace App\Http\Controllers\Admin\Clubs;

use App\Models\ClubCompetition;
use Illuminate\Support\Facades\Gate;

/**
 * Cross-club competitions list (admin.club-competitions.index).
 *
 * PLACEHOLDER by B2 (Central Admin) so the Clubs navigation is complete.
 * Owned by C1 (Competitions): replace this body and
 * resources/views/admin/clubs/competitions/index.blade.php with the real list +
 * editor routes; keep the route name admin.club-competitions.index stable.
 */
class ClubCompetitionController extends AdminClubController
{
    public function index()
    {
        Gate::authorize('admin.manage');

        return view('admin.clubs.competitions.index', [
            'total' => ClubCompetition::count(),
        ]);
    }
}
