<?php

namespace App\Http\Middleware;

use App\Support\AppLog;
use App\Support\AuthError;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs right after `auth:sanctum`: a token that resolves to a deleted
 * (anonymized) account must never yield a usable session.
 *
 * AccountDeletionService revokes every token inside the deletion transaction,
 * so this only fires for a token that survived by some other route (an older
 * backend, a manual DB edit, a restored backup). Without it the app would read
 * a 200 with "Deleted User" and sit on a half-working Home screen; with it the
 * app gets the stable `account_deleted` code, clears its stored token and
 * returns to Login. The surviving token is revoked here as well so the next
 * request is a clean 401.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->anonymized_at !== null) {
            $token = $user->currentAccessToken();
            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }

            // Category + id only — never the email, never the token.
            AppLog::warn($request->is('api/me') ? 'me.failed_anonymized' : 'auth.failed_anonymized', [
                'user_id' => $user->id,
                'route'   => $request->path(),
            ]);

            AuthError::throw(AuthError::ACCOUNT_DELETED, 401);
        }

        return $next($request);
    }
}
