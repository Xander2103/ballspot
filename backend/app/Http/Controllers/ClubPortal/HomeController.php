<?php

namespace App\Http\Controllers\ClubPortal;

use App\Services\ClubPortal\ClubPortalAccess;
use Illuminate\Http\Request;

/** GET /club — the club switcher (straight to the dashboard when there is one club). */
class HomeController extends PortalController
{
    public function __invoke(Request $request, ClubPortalAccess $access)
    {
        $clubs = $access->clubsFor($request->user());

        if ($clubs->count() === 1) {
            return redirect()->route('club-portal.dashboard', $clubs->first());
        }

        return view('club-portal.home', ['clubs' => $clubs]);
    }
}
