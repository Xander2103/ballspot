<?php

namespace App\Services\Clubs;

use App\Models\ChallengePack;
use App\Models\ClubCompetitionAttempt;
use App\Models\ClubPackRewardClaim;
use App\Models\User;
use App\Models\XpEvent;
use App\Services\BadgeService;
use App\Services\XpService;
use App\Support\AppLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Global rewards for CLUB packs at most once per player per pack (D3, plan
 * §5.8, contracts §3.7).
 *
 * The first run that starts on a club pack — a normal pack attempt or a
 * competition attempt — inserts the single club_pack_reward_claims row; the
 * unique (user_id, challenge_pack_id) index settles races. Only that
 * "rewarded run" earns per-guess XP, completion XP and pack badges. Every
 * other run (replays, extra competition attempts, a competition attempt after
 * a completed normal run) earns no global XP, no completion reward and no
 * badge evaluation; it still scores for its own track.
 *
 * Public (BallPicker) packs never call this service.
 *
 * Normal track: PackPlayService claims at attempt start (source
 * `pack_attempt`) and checks isRewarded() on every guess, keeping the
 * existing pack_guess / pack_completion XP sources.
 *
 * Competition track (hook for the Competitions agent):
 *   1. at attempt start, inside the start transaction:
 *        $rewarded = $rewards->claim($user, $pack, ClubPackRewardClaim::SOURCE_COMPETITION_ATTEMPT, $attempt->id);
 *        $attempt->is_rewarded_run = $rewarded;
 *   2. after each committed guess:
 *        $xp = $rewards->awardCompetitionGuessXp($user, $attempt, $guess->id, $score);
 *   3. after the attempt is committed as completed:
 *        ['completion_xp' => $xp, 'new_badges' => $badges] = $rewards->awardCompetitionCompletion($user, $attempt);
 * Steps 2 and 3 are no-ops (0 / [0, []]) for non-rewarded runs, are deduped
 * by the XP ledger's unique (user, source_type, source_id) index, and never
 * throw: reward failures are logged and swallowed like pack completion.
 */
class ClubRewardService
{
    public function __construct(
        private XpService $xp,
        private BadgeService $badges,
    ) {}

    /**
     * Try to make this run the player's rewarded run for the club pack.
     * True when this run holds the claim — newly inserted, or already held by
     * exactly this source (idempotent retry). False when another run holds it
     * or for a public pack.
     */
    public function claim(User $user, ChallengePack $pack, string $sourceType, int $sourceId): bool
    {
        if ($pack->club_id === null) {
            return false;
        }
        $this->assertSource($sourceType);

        try {
            // Insert first and let the unique index decide: a savepoint keeps a
            // lost race from aborting the caller's transaction.
            DB::transaction(fn () => ClubPackRewardClaim::create([
                'user_id'           => $user->id,
                'challenge_pack_id' => $pack->id,
                'source_type'       => $sourceType,
                'source_id'         => $sourceId,
                'claimed_at'        => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return $this->isRewarded($user, $pack, $sourceType, $sourceId);
        }

        AppLog::event('club.reward_claimed', [
            'user_id'     => $user->id,
            'pack_id'     => $pack->id,
            'source_type' => $sourceType,
            'source_id'   => $sourceId,
        ]);

        return true;
    }

    /** True when this exact run (source type + id) holds the player's claim on the pack. */
    public function isRewarded(User $user, ChallengePack|int $pack, string $sourceType, int $sourceId): bool
    {
        $packId = $pack instanceof ChallengePack ? $pack->id : $pack;

        return ClubPackRewardClaim::where('user_id', $user->id)
            ->where('challenge_pack_id', $packId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->exists();
    }

    /** The player's claim on a club pack, if any run holds it. */
    public function claimFor(User $user, ChallengePack|int $pack): ?ClubPackRewardClaim
    {
        $packId = $pack instanceof ChallengePack ? $pack->id : $pack;

        return ClubPackRewardClaim::where('user_id', $user->id)->where('challenge_pack_id', $packId)->first();
    }

    // ------------------------------------------------------------------
    // Competition-track hooks (called by the Competitions play service)
    // ------------------------------------------------------------------

    /**
     * Per-guess XP (= score, like pack_guess) for a rewarded competition run,
     * source `club_competition_guess` keyed on the competition guess id.
     * Returns the XP actually awarded (0 for a non-rewarded run, a duplicate
     * or a failure).
     */
    public function awardCompetitionGuessXp(User $user, ClubCompetitionAttempt $attempt, int $guessId, int $score): int
    {
        if (!$this->isRewardedCompetitionRun($user, $attempt)) {
            return 0;
        }

        try {
            $event = $this->xp->awardXp(
                $user,
                XpEvent::SOURCE_CLUB_COMPETITION_GUESS,
                $guessId,
                $score,
                'Club competition challenge completed',
                ['club_competition_attempt_id' => $attempt->id, 'challenge_pack_id' => $attempt->challenge_pack_id],
            );
        } catch (\Throwable $e) {
            $this->failed($e, 'competition_guess_xp', $user, $attempt);

            return 0;
        }

        return (int) ($event?->amount ?? 0);
    }

    /**
     * Completion XP (config ballspot.xp.pack_completion, source
     * `club_competition_completion` keyed on the attempt id) and pack badge
     * evaluation for a rewarded, completed competition run.
     *
     * @return array{completion_xp: int, new_badges: array<int, \App\Models\Badge>}
     */
    public function awardCompetitionCompletion(User $user, ClubCompetitionAttempt $attempt): array
    {
        $none = ['completion_xp' => 0, 'new_badges' => []];
        if (!$this->isRewardedCompetitionRun($user, $attempt)) {
            return $none;
        }

        $completionXp = (int) config('ballspot.xp.pack_completion', 250);
        try {
            $event = $this->xp->awardXp(
                $user,
                XpEvent::SOURCE_CLUB_COMPETITION_COMPLETION,
                $attempt->id,
                $completionXp,
                'Club pack completed',
                ['challenge_pack_id' => $attempt->challenge_pack_id, 'club_competition_id' => $attempt->club_competition_id],
            );
            $completionXp = (int) ($event?->amount ?? 0);
        } catch (\Throwable $e) {
            $completionXp = 0;
            $this->failed($e, 'competition_completion_xp', $user, $attempt);
        }

        $newBadges = [];
        try {
            $pack   = $attempt->pack()->with('completionBadge')->first();
            $scores = $attempt->guesses()->pluck('score')->map(fn ($s) => (int) $s)->all();
            if ($pack) {
                $newBadges = $this->badges->evaluatePackRunCompletion($user, $pack, $scores, [
                    'club_competition_attempt_id' => $attempt->id,
                    'challenge_pack_id'           => $attempt->challenge_pack_id,
                ]);
            }
        } catch (\Throwable $e) {
            $newBadges = [];
            $this->failed($e, 'competition_badge_evaluation', $user, $attempt);
        }

        return ['completion_xp' => $completionXp, 'new_badges' => $newBadges];
    }

    private function isRewardedCompetitionRun(User $user, ClubCompetitionAttempt $attempt): bool
    {
        return $attempt->user_id === $user->id
            && $this->isRewarded($user, (int) $attempt->challenge_pack_id, ClubPackRewardClaim::SOURCE_COMPETITION_ATTEMPT, (int) $attempt->id);
    }

    private function assertSource(string $sourceType): void
    {
        if (!in_array($sourceType, ClubPackRewardClaim::SOURCES, true)) {
            throw new \InvalidArgumentException("Unknown reward claim source [{$sourceType}].");
        }
    }

    private function failed(\Throwable $e, string $stage, User $user, ClubCompetitionAttempt $attempt): void
    {
        AppLog::error('club.reward_failed', [
            'stage'      => $stage,
            'user_id'    => $user->id,
            'attempt_id' => $attempt->id,
            'pack_id'    => $attempt->challenge_pack_id,
            'exception'  => class_basename($e),
        ]);
        report($e);
    }
}
