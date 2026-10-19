<?php

namespace App\Services\Clubs\ReplayPolicies;

use App\Contracts\ReplayPolicy;
use App\Enums\CompetitionAttemptStatus;
use App\Enums\ReplayMode;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionAttempt;
use App\Models\PackAttempt;
use App\Models\User;
use App\Services\Clubs\CompetitionEligibility;

/**
 * `no_replay`: one attempt, and only for players who have NOT completed the
 * pack anywhere before — no completed normal pack attempt and no completed
 * attempt in another competition (they already know every photo).
 */
class NoReplayPolicy implements ReplayPolicy
{
    public function mode(): ReplayMode
    {
        return ReplayMode::NoReplay;
    }

    public function maxAttempts(ClubCompetition $competition): int
    {
        return 1;
    }

    public function blockedReason(ClubCompetition $competition, int $packId, User $user, int $attemptsUsed): ?string
    {
        if ($attemptsUsed >= 1) {
            return CompetitionEligibility::REASON_LIMIT_REACHED;
        }

        return $this->completedAnywhere($competition, $packId, $user) ? CompetitionEligibility::REASON_ALREADY_PLAYED : null;
    }

    public function completedAnywhere(ClubCompetition $competition, int $packId, User $user): bool
    {
        $normal = PackAttempt::where('user_id', $user->id)
            ->where('challenge_pack_id', $packId)
            ->where('status', PackAttempt::STATUS_COMPLETED)
            ->exists();

        return $normal || ClubCompetitionAttempt::where('user_id', $user->id)
            ->where('challenge_pack_id', $packId)
            ->where('club_competition_id', '!=', $competition->id)
            ->where('status', CompetitionAttemptStatus::Completed->value)
            ->exists();
    }
}
