<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DiagnosticsService;

/**
 * GET /admin/diagnostics — read-only operational status for beta support.
 * Admin-only (route middleware). Never executes shell commands, never
 * mutates data, never renders secrets (see DiagnosticsService).
 */
class DiagnosticsController extends Controller
{
    public function index(DiagnosticsService $diagnostics)
    {
        return view('admin.diagnostics.index', ['d' => $diagnostics->snapshot()]);
    }
}
