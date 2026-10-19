<?php

namespace App\Http\Middleware;

use App\Exceptions\ClubDomainException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `feature:<name>` — the route group exists only while
 * config("ballspot.<name>.enabled") is true (e.g. `feature:clubs` →
 * ballspot.clubs.enabled). Off: API requests get 404 with the stable code
 * `feature_disabled`; web requests a plain 404 (nothing to go "back" to).
 *
 * A 404 rather than 403 so a disabled feature is indistinguishable from a
 * route that does not exist.
 */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (!self::enabled($feature)) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return (new ClubDomainException(ClubDomainException::FEATURE_DISABLED, 404))->toJsonResponse();
            }

            abort(404);
        }

        return $next($request);
    }

    public static function enabled(string $feature): bool
    {
        // Feature names are route-literal, but keep the config lookup to a
        // single plain segment so `feature:` can never read arbitrary keys.
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $feature)) {
            return false;
        }

        return (bool) config("ballspot.{$feature}.enabled", false);
    }
}
