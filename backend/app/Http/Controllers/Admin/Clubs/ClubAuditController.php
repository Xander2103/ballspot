<?php

namespace App\Http\Controllers\Admin\Clubs;

use App\Models\Club;
use App\Models\ClubAuditEvent;
use App\Services\Clubs\AdminClubAuditQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Global club audit log across all clubs (plan §7.2): filter club / action / actor / date. Read-only. */
class ClubAuditController extends AdminClubController
{
    public function index(Request $request, AdminClubAuditQuery $audit)
    {
        Gate::authorize('admin.manage');

        $filters = $audit->normalize($request->query());

        return view('admin.clubs.audit.index', [
            'events'        => $audit->paginate($filters),
            'auditFilters'  => $filters,
            'auditFiltered' => $audit->isFiltered($filters),
            'filterClub'    => $filters['club'] !== '' ? Club::find((int) $filters['club'], ['id', 'name', 'slug']) : null,
            'actions'       => ClubAuditEvent::ACTIONS,
        ]);
    }
}
