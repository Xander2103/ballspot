<?php

namespace App\Services\Clubs;

use App\Contracts\ReplayPolicy;
use App\Enums\CompetitionPhase;
use App\Enums\ReplayMode;
use App\Exceptions\ClubDomainException;
use App\Models\Club;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionAttempt;
use App\Models\ClubCompetitionPackResult;
use App\Models\User;
use App\Services\Clubs\ReplayPolicies\MultipleAttemptsPolicy;
use App\Services\Clubs\ReplayPolicies\NoReplayPolicy;
use App\Services\Clubs\ReplayPolicies\SingleAttemptPolicy;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Per-pack eligibility of a player in a competition (plan §5.2, contracts §5
 * `state`). The backend computes it, the app only renders it, and every
 * start re-checks it through assertCanStart().
 *
 * Order of the checks (first match wins):
 *  1. club suspended                    → club_unavailable
 *  2. phase upcoming / draft            → not_started
 *  3. phase ended / finalized / cancelled / voided → ended
 *  4. an attempt is in progress         → attempt_in_progress (+ active_attempt_id)
 *  5. the replay policy (limit, no-replay "completed anywhere")
 *
 * attempts_used counts every attempt started on the pack in this competition
 * (voided ones included), so next_attempt_number = attempts_used + 1 and a void
 * never hands out an extra slot.
 */
class CompetitionEligibility
{
    public const REASON_NOT_STARTED         = 'not_started';
    public const REASON_ENDED               = 'ended';
    public const REASON_LIMIT_REACHED       = 'limit_reached';
    public const REASON_ALREADY_PLAYED      = 'already_played';
    public const REASON_ATTEMPT_IN_PROGRESS = 'attempt_in_progress';
    public const REASON_CLUB_UNAVAILABLE    = 'club_unavailable';

    public function policyFor(ClubCompetition $competition): ReplayPolicy
    {
        return match ($competition->replay_mode) {
            ReplayMode::NoReplay         => new NoReplayPolicy(),
            ReplayMode::SingleAttempt    => new SingleAttemptPolicy(),
            ReplayMode::MultipleAttempts => new MultipleAttemptsPolicy(),
        };
    }

    public function maxAttempts(ClubCompetition $competition): int
    {
        return $this->policyFor($competition)->maxAttempts($competition);
    }

    /**
     * The `state` block of a competition pack for the player.
     *
     * @param Collection<int, ClubCompetitionAttempt>|null $attempts the player's attempts on this pack (any status), loaded when null
     * @return array{attempts_used: int, attempts_remaining: int, next_attempt_number: ?int, can_start: bool, blocked_reason: ?string,
     *               active_attempt_id: ?int, counted_score: ?int, best_score: ?int, first_score: ?int}
     */
    public function packState(
        ClubCompetition $competition,
        Club $club,
        int $packId,
        User $user,
        ?Collection $attempts = null,
        ?ClubCompetitionPackResult $result = null,
        ?bool $playable = null,
        ?CarbonInterface $now = null,
    ): array {
        $attempts ??= ClubCompetitionAttempt::where('club_competition_id', $competition->id)
            ->where('challenge_pack_id', $packId)
            ->where('user_id', $user->id)
            ->get();

        $used      = (int) ($attempts->max('attempt_number') ?? 0);
        $max       = $this->maxAttempts($competition);
        $phase     = $competition->phase($now);
        $active    = $attempts->first(fn (ClubCompetitionAttempt $a) => $a->isActive());
        $reason    = $this->blockedReason($competition, $club, $packId, $user, $used, $active !== null, $phase);
        $canStart  = $reason === null && ($playable ?? true);

        return [
            'attempts_used'       => $used,
            'attempts_remaining'  => max(0, $max - $used),
            'next_attempt_number' => $used < $max ? $used + 1 : null,
            'can_start'           => $canStart,
            'blocked_reason'      => $reason,
            'active_attempt_id'   => $active !== null && $phase === CompetitionPhase::Active ? (int) $active->id : null,
            'counted_score'       => $result?->counted_attempt_id !== null ? (int) $result->counted_score : null,
            'best_score'          => $result?->best_score,
            'first_score'         => $result?->first_score,
        ];
    }

    /** First failing check (see class doc), or null. */
    public function blockedReason(
        ClubCompetition $competition,
        Club $club,
        int $packId,
        User $user,
        int $attemptsUsed,
        bool $hasActiveAttempt,
        ?CompetitionPhase $phase = null,
    ): ?string {
        $phase ??= $competition->phase();

        if (!$club->isActive()) {
            return self::REASON_CLUB_UNAVAILABLE;
        }
        if ($phase === CompetitionPhase::Upcoming || $phase === CompetitionPhase::Draft) {
            return self::REASON_NOT_STARTED;
        }
        if ($phase !== CompetitionPhase::Active) {
            return self::REASON_ENDED;
        }
        if ($hasActiveAttempt) {
            return self::REASON_ATTEMPT_IN_PROGRESS;
        }

        return $this->policyFor($competition)->blockedReason($competition, $packId, $user, $attemptsUsed);
    }

    /** The domain error for a blocked reason (start re-check). */
    public static function exceptionFor(string $reason): ClubDomainException
    {
        return match ($reason) {
            self::REASON_CLUB_UNAVAILABLE    => ClubDomainException::unavailable(),
            self::REASON_NOT_STARTED         => new ClubDomainException(ClubDomainException::COMPETITION_NOT_STARTED, 422),
            self::REASON_ENDED               => new ClubDomainException(ClubDomainException::COMPETITION_ENDED, 422),
            self::REASON_ATTEMPT_IN_PROGRESS => new ClubDomainException(ClubDomainException::COMPETITION_ATTEMPT_IN_PROGRESS, 409),
            self::REASON_LIMIT_REACHED       => new ClubDomainException(ClubDomainException::COMPETITION_ATTEMPT_LIMIT, 422),
            self::REASON_ALREADY_PLAYED      => new ClubDomainException(ClubDomainException::COMPETITION_PACK_ALREADY_PLAYED, 422),
            default                          => new ClubDomainException(ClubDomainException::COMPETITION_INVALID_PACK, 422),
        };
    }
}
