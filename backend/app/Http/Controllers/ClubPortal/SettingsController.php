<?php

namespace App\Http\Controllers\ClubPortal;

use App\Enums\ClubRole;
use App\Http\Requests\Clubs\Portal\PortalSettingsRequest;
use App\Models\Club;
use App\Services\Clubs\ClubService;
use Illuminate\Http\Request;

/** Owner-only club settings (ClubService::updateSettings, optimistic version, audited as club_admin). */
class SettingsController extends PortalController
{
    /** Owners see the page on a suspended club too, read-only (the update needs manageSettings = active club). */
    public function edit(Request $request, Club $club)
    {
        if ($this->actorMember($request)->role !== ClubRole::Owner) {
            abort(403);
        }

        return view('club-portal.settings', [
            'club'     => $club,
            'settings' => $club->resolvedSettings(),
            'canEdit'  => $request->user()->can('manageSettings', $club),
        ]);
    }

    public function update(PortalSettingsRequest $request, Club $club, ClubService $clubs)
    {
        $before = (int) $club->version;
        $clubs->updateSettings($club, $request->settings(), $request->user(), self::ACTOR, (int) $request->input('version'));
        $this->logPortal($request, 'settings_updated', ['club_id' => $club->id]);

        return redirect()->route('club-portal.settings.edit', $club)->with(
            'success',
            (int) $club->version === $before ? __('club_portal.settings.flash.unchanged') : __('club_portal.settings.flash.saved'),
        );
    }
}
