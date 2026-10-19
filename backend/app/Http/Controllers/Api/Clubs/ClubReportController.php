<?php

namespace App\Http\Controllers\Api\Clubs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clubs\Api\StoreClubReportRequest;
use App\Models\Club;
use App\Services\Clubs\ClubContentReportService;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/clubs/{club}/reports (Wave F) — member content report for
 * platform moderation. 201 `{ data: { id, status: "open" } }`; resending an
 * identical open report answers 200 with the existing one. Throttled by
 * `club-reports` (5/hour per user).
 */
class ClubReportController extends Controller
{
    public function store(StoreClubReportRequest $request, Club $club, ClubContentReportService $reports): JsonResponse
    {
        $result = $reports->create(
            $club,
            $request->user(),
            (string) $request->validated('subject_type'),
            $request->validated('subject_id'),
            (string) $request->validated('reason'),
            $request->validated('details'),
        );

        return response()->json(
            ['data' => ['id' => (int) $result['report']->id, 'status' => $result['report']->status]],
            $result['created'] ? 201 : 200,
        );
    }
}
