<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HealthCheckService;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/health — public, unauthenticated liveness check for deploy scripts
 * and the 5-minute monitor (scripts/monitor-health.sh).
 *
 * Deliberately minimal: status, app name, timestamp, environment name and one
 * boolean per check (database, storage, cache, queue, scheduler — see
 * HealthCheckService). No versions, no counts, no reasons, no config values —
 * anything operational belongs on the admin-only /admin/diagnostics page,
 * which shows the same checks with their reasons.
 *
 * 200 + "ok" when every check passes; 503 + "degraded" otherwise so a deploy
 * check or monitor can act on the status code alone. The JSON shape never
 * changes between the two.
 */
class HealthController extends Controller
{
    public function __invoke(HealthCheckService $health): JsonResponse
    {
        $result = $health->run();

        return response()->json([
            'status'      => $result['ok'] ? 'ok' : 'degraded',
            'app'         => config('ballspot.app_name', 'BallPicker'),
            'timestamp'   => now()->toIso8601String(),
            'environment' => (string) config('app.env'),
            'checks'      => $result['checks'],
        ], $result['ok'] ? 200 : 503);
    }
}
