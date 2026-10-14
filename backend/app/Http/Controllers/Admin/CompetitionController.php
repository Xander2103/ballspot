<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CompetitionAdminStatsService;

class CompetitionController extends Controller
{
    public function __construct(private CompetitionAdminStatsService $stats) {}

    /**
     * GET /admin/competition — read-only dashboard: current + previous period
     * numbers, a live top-10 preview, accounts, growth and abuse summaries.
     * Closing itself stays a deliberate CLI action (no one-click awarding
     * from the panel); nothing on this page writes.
     */
    public function index()
    {
        return view('admin.competition.index', ['d' => $this->stats->dashboard()]);
    }
}
