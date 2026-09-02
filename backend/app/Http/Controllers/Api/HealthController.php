<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/health — public, unauthenticated liveness check for deploy scripts
 * and uptime monitors.
 *
 * Deliberately minimal: status, app name, timestamp, environment name and two
 * boolean checks. No versions, no counts, no job payloads, no config values —
 * anything operational belongs on the admin-only /admin/diagnostics page.
 *
 * 200 + "ok" when every check passes; 503 + "degraded" otherwise so a deploy
 * check or monitor can act on the status code alone. The JSON shape never
 * changes between the two.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->databaseReachable(),
            'storage'  => is_writable(storage_path('app/public')),
        ];

        $healthy = !in_array(false, $checks, true);

        return response()->json([
            'status'      => $healthy ? 'ok' : 'degraded',
            'app'         => config('ballspot.app_name', 'BallPicker'),
            'timestamp'   => now()->toIso8601String(),
            'environment' => (string) config('app.env'),
            'checks'      => $checks,
        ], $healthy ? 200 : 503);
    }

    private function databaseReachable(): bool
    {
        try {
            DB::connection()->getPdo();
            DB::select('select 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
