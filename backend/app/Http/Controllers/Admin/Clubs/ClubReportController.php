<?php

namespace App\Http\Controllers\Admin\Clubs;

use App\Models\Club;
use App\Models\ClubContentReport;
use App\Services\Clubs\ClubContentReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Member content reports across all clubs (Wave F, plan §11.9): filter by
 * status (default open) / club / reason, link to the reported club,
 * competition or member, resolve as reviewed / dismissed / actioned with a
 * note. Audit `report.resolved` (platform-only; hidden from the portal).
 */
class ClubReportController extends AdminClubController
{
    public function __construct(private ClubContentReportService $reports) {}

    public function index(Request $request)
    {
        Gate::authorize('admin.manage');

        // A missing status means "open"; ?status=all lists every report.
        $filters = $this->reports->normalizeFilters($request->query());
        $reports = $this->reports->adminList($filters);

        return view('admin.clubs.reports.index', [
            'reports'    => $reports,
            'subjects'   => collect($reports->items())->mapWithKeys(fn (ClubContentReport $r) => [$r->id => $this->reports->subjectFor($r)]),
            'filters'    => $filters,
            'filterClub' => $filters['club'] !== '' ? Club::find((int) $filters['club'], ['id', 'name']) : null,
            'openCount'  => $this->reports->openCount(),
        ]);
    }

    public function resolve(Request $request, ClubContentReport $report)
    {
        $this->authorize('admin.manage', $report->club);

        $data = $request->validate([
            'status'     => ['required', 'string', Rule::in(ClubContentReport::RESOLUTIONS)],
            'admin_note' => ['nullable', 'string', 'max:' . ClubContentReportService::NOTE_MAX],
        ]);

        $this->reports->resolve($report, $request->user(), $data['status'], $data['admin_note'] ?? null);
        $this->logAdmin($request, 'report_resolved', ['club_id' => $report->club_id, 'report_id' => $report->id, 'status' => $data['status']]);

        return back()->with('success', "Report #{$report->id} marked {$data['status']}.");
    }
}
