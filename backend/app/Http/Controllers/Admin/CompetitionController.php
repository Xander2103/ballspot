<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompetitionFinish;
use App\Services\CompetitionPeriodService;

class CompetitionController extends Controller
{
    public function __construct(private CompetitionPeriodService $period) {}

    // GET /admin/competition — read-only view of the competition windows and
    // close status. Closing itself stays a deliberate CLI action (no one-click
    // awarding from the panel).
    public function index()
    {
        $previous = $this->period->previousPeriod();

        $previousClosed = CompetitionFinish::where('period_type', $previous['period_type'])
            ->whereDate('period_start', $previous['period_start'])
            ->whereDate('period_end', $previous['period_end'])
            ->exists();

        $lastClosed = CompetitionFinish::orderByDesc('period_start')->first();

        return view('admin.competition.index', [
            'period'         => $this->period->toArray(),
            'previous'       => $previous,
            'previousClosed' => $previousClosed,
            'lastClosed'     => $lastClosed,
        ]);
    }
}
