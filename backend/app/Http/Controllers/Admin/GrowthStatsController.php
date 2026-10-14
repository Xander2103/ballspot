<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\GrowthEventService;

/**
 * GET /admin/growth — read-only share & review-attempt counters.
 * Counts only; never rows, never message text. Review numbers are attempts,
 * not confirmed store reviews (the native APIs cannot confirm a submission).
 */
class GrowthStatsController extends Controller
{
    public function index(GrowthEventService $events)
    {
        return view('admin.growth.index', ['g' => $events->stats()]);
    }
}
