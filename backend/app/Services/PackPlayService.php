<?php

namespace App\Services;

use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\PackAttempt;
use App\Models\PackAttemptGuess;
use App\Models\User;
use App\Models\XpEvent;
use App\Support\AppLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Orchestrates playing a challenge pack: starting/resuming an attempt, scoring
 * each guess (reusing ScoreService), awarding XP (reusing XpService) and pack
 * badges on completion. All rewards are virtual. The ordered ready-challenge
 * ids are snapshotted at start so admin edits can't shift an in-flight attempt.
 *
 * Launch hardening (v1.9.5):
 *  - a completed pack cannot be started again (the player already knows every
 *    photo) — startOrResume aborts 409 with the completed attempt
 *  - re-submitting the final guess is idempotent: the stored result comes back
 *    with `already_completed` instead of a 422 "not active"
 *  - rewards after the completion commit (badges, completion XP) can never turn
 *    an already-completed attempt into a 500: failures are logged and the
 *    completion payload is still returned
 *  - completed attempts carry a completion summary (overview screen)
 */
class PackPlayService
{
    public const ALREADY_COMPLETED_MESSAGE = 'You have already completed this pack.';

    public function __construct(
        private ScoreService $scoreService,
        private XpService $xpService,
        private BadgeService $badgeService,
        private PlayerRankService $rankService,
    ) {}

    /**
     * Resume the user's active attempt for this pack, or start a new one.
     * Aborts 422 if the pack has no ready challenges and 409 if the user has
     * already completed the pack (replay disabled for launch).
     */
    public function startOrResume(User $user, ChallengePack $pack): PackAttempt
    {
        $active = PackAttempt::where('user_id', $user->id)
            ->where('challenge_pack_id', $pack->id)
            ->where('status', PackAttempt::STATUS_ACTIVE)
            ->first();

        if ($active) {
            return $active;
        }

        $completed = $this->latestCompleted($user, $pack->id);
        if ($completed) {
            AppLog::event('pack.replay_blocked', ['pack_id' => $pack->id, 'user_id' => $user->id, 'attempt_id' => $completed->id]);
            throw new HttpException(409, self::ALREADY_COMPLETED_MESSAGE, null, [], 0);
        }

        $challengeIds = $pack->readyChallenges()->pluck('id')->values()->all();
        if (empty($challengeIds)) {
            AppLog::warn('pack.start_failed', ['pack_id' => $pack->id, 'user_id' => $user->id, 'reason' => 'no_ready_challenges']);
            abort(422, 'This pack has no ready challenges yet.');
        }

        $attempt = PackAttempt::create([
            'user_id'           => $user->id,
            'challenge_pack_id' => $pack->id,
            'status'            => PackAttempt::STATUS_ACTIVE,
            'started_at'        => now(),
            'current_index'     => 0,
            'total_score'       => 0,
            'metadata'          => ['challenge_ids' => $challengeIds],
        ]);

        AppLog::event('pack.started', ['pack_id' => $pack->id, 'user_id' => $user->id, 'attempt_id' => $attempt->id, 'challenge_count' => count($challengeIds)]);

        return $attempt;
    }

    /** The active attempt if any, otherwise the most recent attempt (or null). */
    public function activeOrLatest(User $user, ChallengePack $pack): ?PackAttempt
    {
        return PackAttempt::where('user_id', $user->id)
            ->where('challenge_pack_id', $pack->id)
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->first();
    }

    /** The user's most recent completed attempt for a pack, if any. */
    public function latestCompleted(User $user, int $packId): ?PackAttempt
    {
        return PackAttempt::where('user_id', $user->id)
            ->where('challenge_pack_id', $packId)
            ->where('status', PackAttempt::STATUS_COMPLETED)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * True when the user already finished this pack in an earlier attempt, so
     * the current attempt is a replay and must not pay XP again. Replays can
     * no longer be started, but attempts that were in flight before the change
     * still exist and must keep the no-double-XP rule.
     */
    private function hasCompletedBefore(User $user, PackAttempt $attempt): bool
    {
        return PackAttempt::where('user_id', $user->id)
            ->where('challenge_pack_id', $attempt->challenge_pack_id)
            ->where('status', PackAttempt::STATUS_COMPLETED)
            ->whereKeyNot($attempt->getKey())
            ->exists();
    }

    /** The challenge the attempt currently expects, or null if finished. */
    public function currentChallenge(PackAttempt $attempt): ?Challenge
    {
        $ids = $attempt->challengeIds();
        $id  = $ids[$attempt->current_index] ?? null;

        return $id ? Challenge::find($id) : null;
    }

    /**
     * Submit a guess for the attempt's current challenge. Scores it, stores the
     * pack guess, awards XP, advances progress, and — on the final challenge —
     * completes the attempt with completion XP + pack badges.
     *
     * @return array the full result payload pieces for the controller
     */
    public function submitGuess(User $user, PackAttempt $attempt, int $challengeId, float $x, float $y): array
    {
        if ($attempt->user_id !== $user->id) {
            abort(403, 'This attempt does not belong to you.');
        }

        if ($attempt->status !== PackAttempt::STATUS_ACTIVE) {
            return $this->handleInactiveSubmit($user, $attempt, $challengeId);
        }

        // Packs used to be replayable; only the FIRST completion pays out. The
        // XP ledger dedupes on (user, source_type, source_id) and both pack
        // keys are per-attempt ids, so without this a finished pack could be
        // restarted for full guess + completion XP each time.
        $isReplay = $this->hasCompletedBefore($user, $attempt);

        $ids        = $attempt->challengeIds();
        $expectedId = $ids[$attempt->current_index] ?? null;
        if ($expectedId === null) {
            // Index ran past the end without the status flipping — heal it.
            return $this->handleInactiveSubmit($user, $this->forceComplete($attempt), $challengeId);
        }
        if ($challengeId !== $expectedId) {
            // A stale retry of the guess that was just accepted is idempotent.
            $stored = $attempt->guesses()->where('challenge_id', $challengeId)->first();
            if ($stored) {
                AppLog::event('pack.duplicate_submit', ['pack_id' => $attempt->challenge_pack_id, 'user_id' => $user->id, 'attempt_id' => $attempt->id, 'state' => 'active']);

                return $this->replayStoredGuess($user, $attempt, $stored);
            }
            abort(422, 'That is not the current challenge in this pack.');
        }

        $challenge = Challenge::findOrFail($challengeId);
        $score     = $this->scoreService->calculate($x, $y, (float) $challenge->ball_x_ratio, (float) $challenge->ball_y_ratio);

        $xpBefore = $this->xpService->getTotalXp($user);

        $result = DB::transaction(function () use ($attempt, $challenge, $challengeId, $x, $y, $score, $user, $isReplay) {
            $guess = PackAttemptGuess::create([
                'pack_attempt_id' => $attempt->id,
                'challenge_id'    => $challengeId,
                'score'           => $score['score'],
                'guessed_x'       => $x,
                'guessed_y'       => $y,
                'distance'        => $score['distance'],
                'result'          => ['score' => $score['score'], 'distance' => $score['distance']],
            ]);

            // Per-guess XP (= score). Skipped entirely on a replay of a pack the
            // user has already completed.
            if (!$isReplay) {
                $this->xpService->awardXp(
                    $user,
                    XpEvent::SOURCE_PACK_GUESS,
                    $guess->id,
                    (int) $score['score'],
                    'Pack challenge completed',
                    ['pack_attempt_id' => $attempt->id, 'challenge_pack_id' => $attempt->challenge_pack_id],
                );
            }

            $attempt->total_score += (int) $score['score'];
            $attempt->current_index += 1;
            $completed = $attempt->current_index >= count($attempt->challengeIds());
            if ($completed) {
                $attempt->status = PackAttempt::STATUS_COMPLETED;
                $attempt->completed_at = now();
            }
            $attempt->save();

            return ['guess' => $guess, 'completed' => $completed];
        });

        $completed    = $result['completed'];
        $completionXp = 0;
        $newBadges    = [];

        if ($completed) {
            [$completionXp, $newBadges] = $this->awardCompletion($user, $attempt, $isReplay);
        }

        $xpAfter = $this->xpService->getTotalXp($user);

        return [
            'attempt'           => $attempt,
            'challenge'         => $challenge,
            'guess'             => $result['guess'],
            'score'             => $score,
            'completed'         => $completed,
            'already_completed' => false,
            'completion_xp'     => $completionXp,
            'new_badges'        => $newBadges,
            'completion'        => $completed ? $this->completionSummary($attempt->fresh(), $completionXp) : null,
            'rank_progress'     => [
                'xp_gained' => $xpAfter - $xpBefore,
                'rank'      => $this->rankService->forXp($xpAfter),
            ],
            'rank_up'           => $this->rankService->rankUp($xpBefore, $xpAfter),
        ];
    }

    /**
     * Completion rewards run AFTER the attempt is committed as completed. A
     * failure here (badge table, XP ledger, logging) is logged per stage and
     * swallowed: the player still gets their completion screen, and an admin
     * sees `pack.completion_reward_failed` on /admin/diagnostics.
     *
     * @return array{0:int,1:array}
     */
    private function awardCompletion(User $user, PackAttempt $attempt, bool $isReplay): array
    {
        $packId       = (int) $attempt->challenge_pack_id;
        $completionXp = 0;
        $newBadges    = [];

        if (!$isReplay) {
            $completionXp = (int) config('ballspot.xp.pack_completion', 250);
            try {
                $this->xpService->awardXp(
                    $user,
                    XpEvent::SOURCE_PACK_COMPLETION,
                    $attempt->id,
                    $completionXp,
                    'Pack completed',
                    ['challenge_pack_id' => $packId],
                );
            } catch (\Throwable $e) {
                $completionXp = 0;
                $this->rewardFailed($e, 'completion_xp', $packId, $user->id, $attempt->id);
            }
        }

        try {
            // Badges stay evaluated on replays — they are idempotent, so this
            // only ever back-fills one the user legitimately qualified for.
            $newBadges = $this->badgeService->evaluatePackCompletion($user, $attempt->load('guesses'));
        } catch (\Throwable $e) {
            $newBadges = [];
            $this->rewardFailed($e, 'badge_evaluation', $packId, $user->id, $attempt->id);
        }

        AppLog::event('pack.completed', [
            'pack_id'       => $packId,
            'user_id'       => $user->id,
            'attempt_id'    => $attempt->id,
            'replay'        => $isReplay,
            'total_score'   => (int) $attempt->total_score,
            'completion_xp' => $completionXp,
        ]);

        $packBadgeId = $attempt->pack?->completion_badge_id;
        if ($packBadgeId === null) {
            AppLog::event('pack.trophy_skipped', ['pack_id' => $packId, 'user_id' => $user->id, 'reason' => 'no_trophy_configured']);
        }
        foreach ($newBadges as $badge) {
            AppLog::event('pack.trophy_awarded', [
                'pack_id'    => $packId,
                'user_id'    => $user->id,
                'badge_id'   => $badge->id,
                'badge_code' => $badge->code,
            ]);
        }

        return [$completionXp, $newBadges];
    }

    private function rewardFailed(\Throwable $e, string $stage, int $packId, int $userId, int $attemptId): void
    {
        AppLog::error('pack.completion_reward_failed', [
            'stage'      => $stage,
            'pack_id'    => $packId,
            'user_id'    => $userId,
            'attempt_id' => $attemptId,
            'exception'  => class_basename($e),
        ]);
        report($e);
    }

    /**
     * A submit against a completed/abandoned attempt. If the guess for that
     * challenge is already stored this is a retry of an accepted request
     * (lost response, double tap): answer with the stored result and the
     * completion — idempotent. Anything else is a friendly 409 carrying the
     * completion state so the app can show the overview instead of an error.
     */
    private function handleInactiveSubmit(User $user, PackAttempt $attempt, int $challengeId): array
    {
        $stored = $attempt->guesses()->where('challenge_id', $challengeId)->first();

        if ($stored && $attempt->isCompleted()) {
            AppLog::event('pack.duplicate_submit', ['pack_id' => $attempt->challenge_pack_id, 'user_id' => $user->id, 'attempt_id' => $attempt->id, 'state' => 'completed']);

            return $this->replayStoredGuess($user, $attempt, $stored);
        }

        if ($attempt->isCompleted()) {
            throw new HttpException(409, self::ALREADY_COMPLETED_MESSAGE, null, [], 0);
        }

        abort(422, 'This pack attempt is not active.');
    }

    private function replayStoredGuess(User $user, PackAttempt $attempt, PackAttemptGuess $stored): array
    {
        $challenge = Challenge::findOrFail($stored->challenge_id);
        $xp        = $this->xpService->getTotalXp($user);

        return [
            'attempt'           => $attempt,
            'challenge'         => $challenge,
            'guess'             => $stored,
            'score'             => ['score' => (int) $stored->score, 'distance' => (float) $stored->distance],
            'completed'         => $attempt->isCompleted(),
            'already_completed' => $attempt->isCompleted(),
            'completion_xp'     => 0,
            'new_badges'        => [],
            'completion'        => $attempt->isCompleted() ? $this->completionSummary($attempt) : null,
            'rank_progress'     => ['xp_gained' => 0, 'rank' => $this->rankService->forXp($xp)],
            'rank_up'           => null,
        ];
    }

    private function forceComplete(PackAttempt $attempt): PackAttempt
    {
        $attempt->status       = PackAttempt::STATUS_COMPLETED;
        $attempt->completed_at = $attempt->completed_at ?? now();
        $attempt->save();

        return $attempt;
    }

    /** Progress summary for API responses. */
    public function attemptState(PackAttempt $attempt): array
    {
        $total = $attempt->totalChallenges();

        return [
            'id'               => $attempt->id,
            'status'           => $attempt->status,
            'current_index'    => $attempt->current_index,
            'total_score'      => $attempt->total_score,
            'completed_count'  => min($attempt->current_index, $total),
            'total_challenges' => $total,
            'started_at'       => $attempt->started_at?->toISOString(),
            'completed_at'     => $attempt->completed_at?->toISOString(),
        ];
    }

    /**
     * Overview of a completed attempt for the "Pack completed" screen: totals,
     * average, best guess, the pack's trophy (if configured) and whether the
     * player holds it. Null for attempts that are not completed.
     */
    public function completionSummary(PackAttempt $attempt, ?int $completionXp = null): ?array
    {
        if (!$attempt->isCompleted()) {
            return null;
        }

        $attempt->loadMissing(['guesses', 'pack.completionBadge']);
        $guesses  = $attempt->guesses->sortBy('id')->values();
        $count    = $guesses->count();
        $total    = (int) $attempt->total_score;
        $maxScore = (int) config('ballspot.scoring.max_score', 100);
        $possible = $attempt->totalChallenges() * $maxScore;
        $average  = $count > 0 ? round($total / $count, 1) : 0.0;
        $best     = $guesses->sortByDesc('score')->first();

        if ($completionXp === null) {
            $completionXp = (int) XpEvent::where('user_id', $attempt->user_id)
                ->where('source_type', XpEvent::SOURCE_PACK_COMPLETION)
                ->where('source_id', $attempt->id)
                ->sum('amount');
        }

        $trophy = null;
        if ($badge = $attempt->pack?->completionBadge) {
            $trophy = [
                'code'   => $badge->code,
                'name'   => $badge->name,
                'icon'   => $badge->icon,
                'rarity' => $badge->rarity,
                'earned' => $attempt->user->badges()->where('badges.id', $badge->id)->exists(),
            ];
        }

        return [
            'attempt_id'       => $attempt->id,
            'pack'             => $attempt->pack ? ['id' => $attempt->pack->id, 'name' => $attempt->pack->name, 'slug' => $attempt->pack->slug] : null,
            'total_score'      => $total,
            'max_score'        => $possible,
            'average_score'    => $average,
            'average_pct'      => $possible > 0 ? (int) round($total / $possible * 100) : 0,
            'best_guess'       => $best ? [
                'challenge_id' => $best->challenge_id,
                'title'        => Challenge::find($best->challenge_id)?->title,
                'score'        => (int) $best->score,
            ] : null,
            'completed_count'  => $count,
            'total_challenges' => $attempt->totalChallenges(),
            'is_perfect'       => $count > 0 && $guesses->every(fn ($g) => (int) $g->score >= $maxScore),
            'completion_xp'    => $completionXp,
            'trophy'           => $trophy,
            'completed_at'     => $attempt->completed_at?->toISOString(),
        ];
    }

    /** Safe challenge payload (never the ball position). */
    public function challengePayload(?Challenge $challenge): ?array
    {
        if (! $challenge) {
            return null;
        }
        $challenge->loadMissing('sport', 'category');

        return [
            'id'               => $challenge->id,
            'title'            => $challenge->title,
            'difficulty'       => $challenge->difficulty,
            // SECURITY: never expose ball_x_ratio / ball_y_ratio here.
            'hidden_image_url' => $challenge->hidden_image_path ? asset('storage/' . $challenge->hidden_image_path) : null,
            'sport'            => $challenge->sport ? [
                'slug'  => $challenge->sport->slug,
                'name'  => $challenge->sport->name,
                'emoji' => $challenge->sport->emoji,
            ] : null,
            'category'         => $challenge->category ? ['name' => $challenge->category->name, 'slug' => $challenge->category->slug] : null,
        ];
    }
}
