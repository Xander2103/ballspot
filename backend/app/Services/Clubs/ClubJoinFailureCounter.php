<?php

namespace App\Services\Clubs;

use App\Exceptions\ClubDomainException;
use App\Models\User;
use App\Support\AppLog;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Failure-only counters for joining with an invite code (plan §7.1): every
 * code that does not lead to a membership counts, successful joins do not.
 * Two budgets in the rolling hour, either one locks joining with
 * `club_join_locked` (429 + retry_after) for the rest of its window:
 *  - per user: ballspot.clubs.join.failures_per_hour (default 15);
 *  - per IP:   ballspot.clubs.join.failures_per_ip_per_hour (default 60) —
 *    the cross-account guessing gate (many throwaway accounts from one
 *    address). Legitimate onboarding behind one NAT only produces
 *    successes, which never count.
 * The per-request `club-join` limiter (AppServiceProvider) only bounds raw
 * volume on top (10/min per user, 300/h per IP).
 *
 * Runs on the shared ResilientRateLimiter, which inherits the route's
 * fail_closed policy (`club-join` is in the list).
 */
final class ClubJoinFailureCounter
{
    private const DECAY = 3600;

    public function key(User $user): string
    {
        return 'club-join-fail|' . $user->id;
    }

    public function ipKey(string $ip): string
    {
        return 'club-join-fail-ip|' . $ip;
    }

    public function limit(): int
    {
        return max(1, (int) config('ballspot.clubs.join.failures_per_hour', 15));
    }

    public function ipLimit(): int
    {
        return max(1, (int) config('ballspot.clubs.join.failures_per_ip_per_hour', 60));
    }

    /** Throws club_join_locked when the user's or the IP's failure budget is spent. */
    public function assertNotLocked(User $user, ?string $ip = null): void
    {
        $budgets = [[$this->key($user), $this->limit(), 'user']];
        if ($ip !== null && $ip !== '') {
            $budgets[] = [$this->ipKey($ip), $this->ipLimit(), 'ip'];
        }

        foreach ($budgets as [$key, $limit, $scope]) {
            if (!RateLimiter::tooManyAttempts($key, $limit)) {
                continue;
            }

            $retry = max(1, (int) RateLimiter::availableIn($key));
            AppLog::warn('club.join_locked', ['user_id' => $user->id, 'scope' => $scope, 'retry_after' => $retry]);

            throw new ClubDomainException(ClubDomainException::CLUB_JOIN_LOCKED, 429, ['retry_after' => $retry]);
        }
    }

    public function record(User $user, ?string $ip = null): void
    {
        RateLimiter::hit($this->key($user), self::DECAY);
        if ($ip !== null && $ip !== '') {
            RateLimiter::hit($this->ipKey($ip), self::DECAY);
        }
    }

    public function failures(User $user): int
    {
        return (int) RateLimiter::attempts($this->key($user));
    }

    public function ipFailures(string $ip): int
    {
        return (int) RateLimiter::attempts($this->ipKey($ip));
    }

    public function clear(User $user): void
    {
        RateLimiter::clear($this->key($user));
    }
}
