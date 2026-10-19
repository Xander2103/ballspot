<?php

namespace App\Services\Clubs;

use App\Enums\CompetitionAttemptStatus;
use App\Enums\CompetitionPhase;
use App\Enums\CompetitionState;
use App\Exceptions\ClubDomainException;
use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\Club;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionAttempt;
use App\Models\ClubCompetitionAttemptGuess;
use App\Models\ClubCompetitionPack;
use App\Models\ClubPackRewardClaim;
use App\Models\User;
use App\Services\PlayerRankService;
use App\Services\ScoreService;
use App\Services\XpService;
use App\Support\AppLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Competition play (plan §5.3, §5.9): attempts and guesses on the
 * competition track — separate from normal pack progress.
 *
 * Start — POST …/competitions/{c}/packs/{pack}/attempts {attempt_number}
 *  1. transaction + user-row lock (MySQL; the unique indexes are the real guard on SQLite)
 *  2. that attempt_number already exists → return it (idempotent retry)
 *  3. an attempt is active → return it (resume)
 *  4. club active, phase active, eligibility (replay policy), number = used + 1 ≤ max
 *  5. lazy rule lock (snapshot), insert with active_slot = 1 (a unique
 *     violation → re-read and return the winner's attempt)
 *  6. reward claim (insert-or-ignore) → is_rewarded_run
 *
 * Guess — POST /club-competition-attempts/{a}/guess {challenge_id, guessed_x, guessed_y}
 *  1. transaction + user-row lock + attempt-row lock; membership / club checked by the caller
 *  2. a stored guess for challenge_id → its result with replayed = true (before the
 *     window check, so a retry after a timeout at the very end still gets its answer)
 *  3. attempt active, phase active (server clock; no grace), challenge = challenge_ids[current_index]
 *  4. insert guess (unique violation → step 2), attempt totals; last challenge → completed, active_slot NULL
 *  5. recompute the player's pack result + entry from source rows, participants_count
 *  6. after commit: XP (+ completion XP / badges) for the rewarded run only
 */
class ClubCompetitionPlayService
{
    public function __construct(
        private CompetitionEligibility $eligibility,
        private ClubCompetitionRulesGuard $guard,
        private ClubStandingsService $standings,
        private ClubRewardService $rewards,
        private ScoreService $scores,
        private XpService $xp,
        private PlayerRankService $ranks,
        private ClubMembershipService $memberships,
    ) {}

    /**
     * @return array{attempt: ClubCompetitionAttempt, created: bool}
     */
    public function start(User $user, Club $club, ClubCompetition $competition, ChallengePack $pack, int $attemptNumber): array
    {
        if ($competition->isLocked() && $competition->rules_locked_at === null && $competition->state === CompetitionState::Published) {
            // Lazy rule lock (§5.7): the first start after starts_at snapshots the packs.
            $this->guard->lock($competition);
        }

        $result = DB::transaction(function () use ($user, $club, $competition, $pack, $attemptNumber) {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();

            $mine = fn () => ClubCompetitionAttempt::where('club_competition_id', $competition->id)
                ->where('challenge_pack_id', $pack->id)
                ->where('user_id', $user->id);

            if ($same = $mine()->where('attempt_number', $attemptNumber)->first()) {
                return ['attempt' => $same, 'created' => false];
            }
            if ($active = $mine()->where('status', CompetitionAttemptStatus::Active->value)->first()) {
                return ['attempt' => $active, 'created' => false];
            }

            $used   = (int) ($mine()->max('attempt_number') ?? 0);
            $reason = $this->eligibility->blockedReason($competition, $club, $pack->id, $user, $used, false);
            if ($reason !== null) {
                throw CompetitionEligibility::exceptionFor($reason);
            }
            if ($attemptNumber !== $used + 1) {
                throw ValidationException::withMessages(['attempt_number' => 'The next attempt number is ' . ($used + 1) . '.']);
            }

            $link = ClubCompetitionPack::where('club_competition_id', $competition->id)->where('challenge_pack_id', $pack->id)->first();
            $ids  = $link?->snapshotIds() ?? [];
            if ($ids === []) {
                throw new ClubDomainException(ClubDomainException::COMPETITION_INVALID_PACK, 422);
            }

            try {
                $attempt = DB::transaction(fn () => ClubCompetitionAttempt::create([
                    'club_competition_id' => $competition->id,
                    'challenge_pack_id'   => $pack->id,
                    'user_id'             => $user->id,
                    'attempt_number'      => $attemptNumber,
                    'status'              => CompetitionAttemptStatus::Active,
                    'active_slot'         => ClubCompetitionAttempt::ACTIVE_SLOT,
                    'challenge_ids'       => $ids,
                    'started_at'          => now(),
                ]));
            } catch (UniqueConstraintViolationException) {
                // Lost a race: the winner's attempt (same number, or the active one).
                $winner = $mine()->where(fn ($q) => $q->where('attempt_number', $attemptNumber)
                    ->orWhere('status', CompetitionAttemptStatus::Active->value))->orderByDesc('id')->first();
                if ($winner === null) {
                    throw new ClubDomainException(ClubDomainException::COMPETITION_ATTEMPT_IN_PROGRESS, 409);
                }

                return ['attempt' => $winner, 'created' => false];
            }

            $rewarded = $this->rewards->claim($user, $pack, ClubPackRewardClaim::SOURCE_COMPETITION_ATTEMPT, $attempt->id);
            if ($rewarded) {
                $attempt->forceFill(['is_rewarded_run' => true])->save();
            }

            AppLog::event('club.competition_attempt_started', [
                'competition_id' => $competition->id,
                'pack_id'        => $pack->id,
                'user_id'        => $user->id,
                'attempt_id'     => $attempt->id,
                'attempt_number' => $attemptNumber,
                'rewarded'       => $rewarded,
            ]);

            return ['attempt' => $attempt, 'created' => true];
        });

        if ($result['created']) {
            $this->memberships->refreshCounters($club, touchActivity: true);
        }

        return $result;
    }

    /**
     * @return array{attempt: ClubCompetitionAttempt, challenge: Challenge, guess: ClubCompetitionAttemptGuess,
     *               completed: bool, replayed: bool, xp_gained: int, completion_xp: int, new_badges: array,
     *               rank_progress: array, rank_up: ?array}
     */
    public function guess(User $user, ClubCompetitionAttempt $attempt, int $challengeId, float $x, float $y): array
    {
        $competition = $attempt->competition;
        $xpBefore    = $this->xp->getTotalXp($user);

        $outcome = DB::transaction(function () use ($user, $attempt, $competition, $challengeId, $x, $y) {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            /** @var ClubCompetitionAttempt $row */
            $row = ClubCompetitionAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ($stored = ClubCompetitionAttemptGuess::where('attempt_id', $row->id)->where('challenge_id', $challengeId)->first()) {
                return ['attempt' => $row, 'guess' => $stored, 'completed' => $row->status === CompetitionAttemptStatus::Completed, 'replayed' => true];
            }

            if (!$row->isActive()) {
                throw new ClubDomainException(ClubDomainException::ATTEMPT_NOT_ACTIVE, 409);
            }

            $phase = $competition->phase();
            if ($phase !== CompetitionPhase::Active) {
                if ($phase === CompetitionPhase::Upcoming) {
                    throw new ClubDomainException(ClubDomainException::COMPETITION_NOT_STARTED, 422);
                }
                // Window over: the attempt keeps its in-window score and expires now
                // (committed), then the guess is refused.
                return ['expired' => true, 'attempt' => $row];
            }

            $expected = $row->challengeIds()[$row->current_index] ?? null;
            if ($expected === null || $expected !== $challengeId) {
                throw new ClubDomainException(ClubDomainException::UNEXPECTED_CHALLENGE, 422);
            }

            $challenge = Challenge::findOrFail($challengeId);
            $score     = $this->scores->calculate($x, $y, (float) $challenge->ball_x_ratio, (float) $challenge->ball_y_ratio);

            try {
                $guess = DB::transaction(fn () => ClubCompetitionAttemptGuess::create([
                    'attempt_id'   => $row->id,
                    'challenge_id' => $challengeId,
                    'position'     => $row->current_index,
                    'guess_x'      => $x,
                    'guess_y'      => $y,
                    'distance'     => $score['distance'],
                    'score'        => (int) $score['score'],
                    'submitted_at' => now(),
                ]));
            } catch (UniqueConstraintViolationException) {
                $stored = ClubCompetitionAttemptGuess::where('attempt_id', $row->id)->where('challenge_id', $challengeId)->firstOrFail();

                return ['attempt' => $row->fresh(), 'guess' => $stored, 'completed' => $row->fresh()->status === CompetitionAttemptStatus::Completed, 'replayed' => true];
            }

            $row->current_index  += 1;
            $row->guesses_count  += 1;
            $row->total_score    += (int) $score['score'];
            $row->last_scored_at  = $guess->submitted_at;
            $completed = $row->current_index >= $row->totalChallenges();
            if ($completed) {
                $row->markInactive(CompetitionAttemptStatus::Completed);
                $row->completed_at = now();
            }
            $row->save();

            $this->standings->recomputePlayer($competition, (int) $user->id);

            return ['attempt' => $row->fresh(), 'guess' => $guess, 'completed' => $completed, 'replayed' => false];
        });

        if (!empty($outcome['expired'])) {
            app(ClubCompetitionCloseService::class)->expireAttempts($competition);
            throw new ClubDomainException(ClubDomainException::COMPETITION_ENDED, 422);
        }

        /** @var ClubCompetitionAttempt $row */
        $row          = $outcome['attempt'];
        $guess        = $outcome['guess'];
        $completionXp = 0;
        $newBadges    = [];

        if (!$outcome['replayed']) {
            $this->rewards->awardCompetitionGuessXp($user, $row, (int) $guess->id, (int) $guess->score);
            if ($outcome['completed']) {
                ['completion_xp' => $completionXp, 'new_badges' => $newBadges] = $this->rewards->awardCompetitionCompletion($user, $row);
                AppLog::event('club.competition_attempt_completed', [
                    'competition_id' => $competition->id,
                    'attempt_id'     => $row->id,
                    'user_id'        => $user->id,
                    'total_score'    => (int) $row->total_score,
                    'rewarded'       => (bool) $row->is_rewarded_run,
                    'completion_xp'  => $completionXp,
                ]);
            }
        } else {
            AppLog::event('club.competition_duplicate_guess', ['competition_id' => $competition->id, 'attempt_id' => $row->id, 'user_id' => $user->id]);
        }

        $xpAfter = $this->xp->getTotalXp($user);

        return [
            'attempt'       => $row,
            'challenge'     => Challenge::findOrFail($guess->challenge_id),
            'guess'         => $guess,
            'completed'     => $outcome['completed'],
            'replayed'      => $outcome['replayed'],
            'completion_xp' => $completionXp,
            'new_badges'    => $newBadges,
            'rank_progress' => ['xp_gained' => $xpAfter - $xpBefore, 'rank' => $this->ranks->forXp($xpAfter)],
            'rank_up'       => $outcome['replayed'] ? null : $this->ranks->rankUp($xpBefore, $xpAfter),
        ];
    }

    /** The challenge the attempt expects next (null once finished / inactive). */
    public function currentChallenge(ClubCompetitionAttempt $attempt): ?Challenge
    {
        if (!$attempt->isActive()) {
            return null;
        }
        $id = $attempt->challengeIds()[$attempt->current_index] ?? null;

        return $id ? Challenge::find($id) : null;
    }
}
