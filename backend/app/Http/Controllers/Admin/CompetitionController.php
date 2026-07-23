<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CompetitionPeriodService;

class CompetitionController extends Controller
{
    public function __construct(private CompetitionPeriodService $period) {}

    // GET /admin/competition — read-only view of the active competition window.
    public function index()
    {
        return view('admin.competition.index', [
            'period' => $this->period->toArray(),
        ]);
    }
}
