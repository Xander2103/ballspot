<?php

namespace App\Services\Clubs\ReplayPolicies;

use App\Contracts\ReplayPolicy;
use App\Enums\ReplayMode;
use App\Models\ClubCompetition;
use App\Models\User;
use App\Services\Clubs\CompetitionEligibility;

/**
 * `multiple_attempts`: up to max_attempts (2…clubs.competition.max_attempts_cap)
 * per pack; the score rule (first | best) decides which one counts. A next
 * attempt only starts once the previous one is completed or expired — the
 * generic attempt-in-progress check handles that.
 */
class MultipleAttemptsPolicy implements ReplayPolicy
{
    public function mode(): ReplayMode
    {
        return ReplayMode::MultipleAttempts;
    }

    public function maxAttempts(ClubCompetition $competition): int
    {
        $cap = max(2, (int) config('ballspot.clubs.competition.max_attempts_cap', 10));

        return max(1, min($cap, (int) $competition->max_attempts));
    }

    public function blockedReason(ClubCompetition $competition, int $packId, User $user, int $attemptsUsed): ?string
    {
        return $attemptsUsed >= $this->maxAttempts($competition) ? CompetitionEligibility::REASON_LIMIT_REACHED : null;
    }
}
