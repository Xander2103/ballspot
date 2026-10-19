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
 * `no_replay`: one attempt, and only for players who have NOT played the
 * pack anywhere before — "played" = any run with at least one guess: a
 * normal pack attempt with current_index > 0 (or completed), or an attempt
 * with a guess (or completed) in ANY other competition, voided ones
 * included. A run that was only started (no guess) reveals nothing and does
 * not count. (Security review 2026-10: completed-only let a player guess
 * most of a pack on the normal track first.)
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

        return $this->playedAnywhere($competition, $packId, $user) ? CompetitionEligibility::REASON_ALREADY_PLAYED : null;
    }

    public function playedAnywhere(ClubCompetition $competition, int $packId, User $user): bool
    {
        $normal = PackAttempt::where('user_id', $user->id)
            ->where('challenge_pack_id', $packId)
            ->where(fn ($q) => $q->where('current_index', '>', 0)->orWhere('status', PackAttempt::STATUS_COMPLETED))
            ->exists();

        return $normal || ClubCompetitionAttempt::where('user_id', $user->id)
            ->where('challenge_pack_id', $packId)
            ->where('club_competition_id', '!=', $competition->id)
            ->where(fn ($q) => $q->where('guesses_count', '>', 0)->orWhere('status', CompetitionAttemptStatus::Completed->value))
            ->exists();
    }
}
