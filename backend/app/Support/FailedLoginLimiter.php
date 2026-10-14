<?php

namespace App\Support;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * IP-independent per-email ceiling on FAILED credential checks.
 *
 * The named `login` / `admin-login` limiters are per IP (and per email+IP) and
 * run as middleware, so they count every request — including successful
 * logins. An IP-independent per-email ceiling is still needed (an attacker
 * with N addresses otherwise gets N × the per-IP budget against one victim),
 * but as middleware it became a lockout tool: 50 junk requests with the
 * victim's address blocked the real user (or the one admin) for an hour,
 * repeatable forever. Counting only failures keeps the brute-force bound and
 * removes the lockout: a correct password clears the bucket.
 *
 * Keys stay `login-email|<email>` / `admin-login-email|<email>` so nothing
 * else changes for dashboards or docs.
 */
final class FailedLoginLimiter
{
    public const SCOPE_LOGIN = 'login';
    public const SCOPE_ADMIN = 'admin-login';

    /** Failed attempts per email before the next attempt is refused. */
    public const MAX_FAILURES = 50;

    /** Window (seconds) a failure stays counted. */
    public const DECAY_SECONDS = 3600;

    public static function key(string $scope, string $email): string
    {
        return $scope . '-email|' . strtolower(trim($email));
    }

    /**
     * Refuse (429, same JSON/headers as the middleware limiters) once the
     * failure budget for this address is spent. Successful logins never count.
     */
    public static function assertNotLocked(string $scope, string $email): void
    {
        $key = self::key($scope, $email);

        if (!RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
            return;
        }

        $retryAfter = max(1, RateLimiter::availableIn($key));

        throw new ThrottleRequestsException('Too Many Attempts.', null, [
            'Retry-After'           => $retryAfter,
            'X-RateLimit-Limit'     => self::MAX_FAILURES,
            'X-RateLimit-Remaining' => 0,
        ]);
    }

    public static function recordFailure(string $scope, string $email): void
    {
        RateLimiter::hit(self::key($scope, $email), self::DECAY_SECONDS);
    }

    public static function clear(string $scope, string $email): void
    {
        RateLimiter::clear(self::key($scope, $email));
    }

    /** Failures currently counted for this address (tests / diagnostics). */
    public static function failures(string $scope, string $email): int
    {
        return (int) RateLimiter::attempts(self::key($scope, $email));
    }
}
