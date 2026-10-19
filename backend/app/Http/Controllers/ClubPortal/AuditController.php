<?php

namespace App\Http\Controllers\ClubPortal;

use App\Models\Club;
use App\Models\ClubAuditEvent;
use App\Models\User;
use App\Services\Clubs\AdminClubAuditQuery;
use Illuminate\Http\Request;

/**
 * GET /club/{club}/audit — this club's audit trail, newest first, paginated.
 * Filters: action + date range (no actor search: the shared query also
 * matches emails, which club admins must not be able to probe). Actors and
 * target users are shown by username only.
 */
class AuditController extends PortalController
{
    public function __invoke(Request $request, Club $club, AdminClubAuditQuery $audit)
    {
        $filters = $audit->normalize($request->only(['action', 'from', 'to']));
        $filters['actor'] = '';
        $filters['actor_kind'] = '';
        $filters['club'] = '';

        $events = $audit->paginate($filters, $club->id);

        // Usernames of users referenced in metadata (one query per page).
        $ids = collect($events->items())
            ->flatMap(fn (ClubAuditEvent $e) => array_filter([
                $e->metadata['target_user_id'] ?? null,
                $e->metadata['new_owner_id'] ?? null,
            ], 'is_numeric'))
            ->map(fn ($id) => (int) $id)->unique()->values();
        $usernames = $ids->isEmpty() ? collect() : User::whereIn('id', $ids)->pluck('username', 'id');

        return view('club-portal.audit', [
            'club'      => $club,
            'events'    => $events,
            'filters'   => $filters,
            'filtered'  => $filters['action'] !== '' || $filters['from'] !== '' || $filters['to'] !== '',
            'usernames' => $usernames,
            'actions'   => ClubAuditEvent::ACTIONS,
        ]);
    }
}
