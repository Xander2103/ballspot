<?php

namespace App\Http\Controllers\ClubPortal;

use App\Http\Controllers\Controller;
use App\Models\ClubAuditEvent;
use App\Models\ClubMember;
use App\Support\AppLog;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

/**
 * Base of the club portal controllers (D2). The `club.portal` middleware has
 * already resolved the caller's owner/admin membership of {club}; every
 * action still authorizes its ClubPolicy ability (manageMembers,
 * manageCodes, uploadPhotos, manageSettings, transferOwnership,
 * manageCompetitions — all of which also require an ACTIVE club) and then
 * calls the shared services, which write the audit rows with actorKind
 * `club_admin`. ClubDomainException → back() with the `error` flash.
 */
abstract class PortalController extends Controller
{
    use AuthorizesRequests;

    protected const ACTOR = ClubAuditEvent::ACTOR_CLUB_ADMIN;

    /** The caller's membership of the route's club (set by EnsureClubPortalAccess). */
    protected function actorMember(Request $request): ClubMember
    {
        $member = $request->attributes->get('portal_member');
        if (!$member instanceof ClubMember) {
            abort(404);
        }

        return $member;
    }

    /** AppLog mirror (ids only). */
    protected function logPortal(Request $request, string $event, array $context = []): void
    {
        AppLog::event('club_portal.' . $event, $context + ['user_id' => $request->user()?->id]);
    }
}
