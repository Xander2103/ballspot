<?php

namespace App\Contracts;

use App\Enums\ReplayMode;
use App\Models\ClubCompetition;
use App\Models\User;

/**
 * The replay-mode specific part of competition eligibility (plan §5.2): one
 * implementation per ReplayMode, resolved by CompetitionEligibility. The
 * generic checks (phase, club status, attempt in progress) run before it.
 */
interface ReplayPolicy
{
    public function mode(): ReplayMode;

    /** Attempts a player may start per linked pack under this mode. */
    public function maxAttempts(ClubCompetition $competition): int;

    /**
     * Why the player may NOT start another attempt on the pack, as a
     * CompetitionEligibility::REASON_* value, or null when allowed.
     * $attemptsUsed counts every attempt already started on the pack in this
     * competition (voided ones included: a void never hands out a new slot).
     */
    public function blockedReason(ClubCompetition $competition, int $packId, User $user, int $attemptsUsed): ?string;
}
