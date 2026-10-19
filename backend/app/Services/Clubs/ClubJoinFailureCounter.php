<?php

namespace App\Services\Clubs;

use App\Exceptions\ClubDomainException;
use App\Models\User;
use App\Support\AppLog;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Failure-only counter for joining with an invite code (plan §7.1): every
 * code that does not lead to a membership counts, successful joins do not.
 * At ballspot.clubs.join.failures_per_hour failures in the rolling hour the
 * user gets `club_join_locked` (429 + retry_after) for the rest of the
 * window — the guessing gate. The per-request `club-join` limiter
 * (AppServiceProvider) bounds raw volume per user and per IP on top.
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

    public function limit(): int
    {
        return max(1, (int) config('ballspot.clubs.join.failures_per_hour', 15));
    }

    /** Throws club_join_locked when the failure budget is spent. */
    public function assertNotLocked(User $user): void
    {
        if (!RateLimiter::tooManyAttempts($this->key($user), $this->limit())) {
            return;
        }

        $retry = max(1, (int) RateLimiter::availableIn($this->key($user)));
        AppLog::warn('club.join_locked', ['user_id' => $user->id, 'retry_after' => $retry]);

        throw new ClubDomainException(ClubDomainException::CLUB_JOIN_LOCKED, 429, ['retry_after' => $retry]);
    }

    public function record(User $user): void
    {
        RateLimiter::hit($this->key($user), self::DECAY);
    }

    public function failures(User $user): int
    {
        return (int) RateLimiter::attempts($this->key($user));
    }

    public function clear(User $user): void
    {
        RateLimiter::clear($this->key($user));
    }
}
