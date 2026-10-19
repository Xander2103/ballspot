<?php

namespace App\Services\Clubs\ReplayPolicies;

use App\Contracts\ReplayPolicy;
use App\Enums\ReplayMode;
use App\Models\ClubCompetition;
use App\Models\User;
use App\Services\Clubs\CompetitionEligibility;

/**
 * `single_attempt` (default): every active member gets exactly one
 * competition attempt per pack — even after completing the pack normally.
 */
class SingleAttemptPolicy implements ReplayPolicy
{
    public function mode(): ReplayMode
    {
        return ReplayMode::SingleAttempt;
    }

    public function maxAttempts(ClubCompetition $competition): int
    {
        return 1;
    }

    public function blockedReason(ClubCompetition $competition, int $packId, User $user, int $attemptsUsed): ?string
    {
        return $attemptsUsed >= 1 ? CompetitionEligibility::REASON_LIMIT_REACHED : null;
    }
}
