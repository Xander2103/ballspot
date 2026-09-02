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

/**
 * Orchestrates playing a challenge pack: starting/resuming an attempt, scoring
 * each guess (reusing ScoreService), awarding XP (reusing XpService) and pack
 * badges on completion. All rewards are virtual. The ordered ready-challenge
 * ids are snapshotted at start so admin edits can't shift an in-flight attempt.
 */
class PackPlayService
{
    public function __construct(
        private ScoreService $scoreService,
        private XpService $xpService,
        private BadgeService $badgeService,
        private PlayerRankService $rankService,
    ) {}

    /**
     * Resume the user's active attempt for this pack, or start a new one.
     * Aborts 422 if the pack has no ready challenges.
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

    /**
     * True when the user already finished this pack in an earlier attempt, so
     * the current attempt is a replay and must not pay XP again.
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
            abort(422, 'This pack attempt is not active.');
        }

        // Packs stay replayable, but only the FIRST completion pays out. The XP
        // ledger dedupes on (user, source_type, source_id) and both pack keys
        // are per-attempt ids, so without this a finished pack could be
        // restarted indefinitely — with every ball position already known — for
        // full guess + completion XP each time.
        $isReplay = $this->hasCompletedBefore($user, $attempt);

        $ids        = $attempt->challengeIds();
        $expectedId = $ids[$attempt->current_index] ?? null;
        if ($expectedId === null) {
            abort(422, 'This pack attempt is already complete.');
        }
        if ($challengeId !== $expectedId) {
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
            if (!$isReplay) {
                $completionXp = (int) config('ballspot.xp.pack_completion', 250);
                $this->xpService->awardXp(
                    $user,
                    XpEvent::SOURCE_PACK_COMPLETION,
                    $attempt->id,
                    $completionXp,
                    'Pack completed',
                    ['challenge_pack_id' => $attempt->challenge_pack_id],
                );
            }
            // Badges stay evaluated on replays — they are idempotent, so this
            // only ever back-fills one the user legitimately qualified for.
            $newBadges = $this->badgeService->evaluatePackCompletion($user, $attempt->load('guesses'));

            $packId = (int) $attempt->challenge_pack_id;
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
        }

        $xpAfter = $this->xpService->getTotalXp($user);

        return [
            'attempt'       => $attempt,
            'challenge'     => $challenge,
            'guess'         => $result['guess'],
            'score'         => $score,
            'completed'     => $completed,
            'completion_xp' => $completionXp,
            'new_badges'    => $newBadges,
            'rank_progress' => [
                'xp_gained' => $xpAfter - $xpBefore,
                'rank'      => $this->rankService->forXp($xpAfter),
            ],
            'rank_up'       => $this->rankService->rankUp($xpBefore, $xpAfter),
        ];
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
