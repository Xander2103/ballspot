<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replacement for Laravel's `verified` alias that honours the
 * ballspot.auth.require_email_verification switch.
 *
 * When verification is switched off for launch, accounts created while it was
 * on (and never verified) must not be locked out of every gameplay endpoint —
 * the framework middleware would 403 them forever.
 */
class EnsureEmailIsVerifiedIfRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('ballspot.auth.require_email_verification', true)) {
            return $next($request);
        }

        $user = $request->user();

        if (!$user || ($user instanceof MustVerifyEmail && !$user->hasVerifiedEmail())) {
            abort(403, 'Your email address is not verified.');
        }

        return $next($request);
    }
}
