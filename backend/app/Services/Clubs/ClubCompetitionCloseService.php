<?php

namespace App\Services\Clubs;

use App\Enums\CompetitionAttemptStatus;
use App\Enums\CompetitionPhase;
use App\Enums\CompetitionState;
use App\Exceptions\ClubDomainException;
use App\Models\ClubAuditEvent;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionAttempt;
use App\Models\ClubCompetitionFinish;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ends a competition (plan §5.11 step 2–3): expires attempts still active
 * after ends_at, then FINALIZES it — writes club_competition_finishes with the
 * live ranking's shared placements (one row per ranked, visible player),
 * sets state finalized (normal play of the linked packs is possible again).
 *
 * Idempotent: a finalized competition is left as it is (same finishes),
 * unless $refinalize, which recomputes the standings first and rewrites the
 * finishes (after an attempt void or a rebuild) with an audit event.
 * Finishes are club-only trophies: NO global XP and no badges (D3).
 */
class ClubCompetitionCloseService
{
    public function __construct(
        private ClubStandingsService $standings,
        private ClubAuditLogger $audit,
        private ClubMembershipService $memberships,
        private ClubCompetitionNotifier $notifier,
    ) {}

    /**
     * Expire the active attempts of a competition whose window is over (or
     * that is no longer published). Returns the number of expired attempts.
     */
    public function expireAttempts(ClubCompetition $competition): int
    {
        if ($competition->phase() === CompetitionPhase::Active || $competition->phase() === CompetitionPhase::Upcoming) {
            return 0;
        }

        $expired = 0;
        ClubCompetitionAttempt::where('club_competition_id', $competition->id)
            ->where('status', CompetitionAttemptStatus::Active->value)
            ->orderBy('id')
            ->get()
            ->each(function (ClubCompetitionAttempt $attempt) use ($competition, &$expired) {
                DB::transaction(function () use ($attempt, $competition, &$expired) {
                    DB::table('users')->where('id', $attempt->user_id)->lockForUpdate()->first();
                    $row = ClubCompetitionAttempt::whereKey($attempt->id)->lockForUpdate()->first();
                    if ($row === null || !$row->isActive()) {
                        return;
                    }
                    $row->markInactive(CompetitionAttemptStatus::Expired)->save();
                    $this->standings->recomputePlayer($competition, (int) $row->user_id);
                    $expired++;
                });
            });

        return $expired;
    }

    /**
     * @return array{finalized: bool, placements: int, total_players: int}
     */
    public function finalize(ClubCompetition $competition, ?User $actor = null, bool $refinalize = false, string $actorKind = ClubAuditEvent::ACTOR_SYSTEM): array
    {
        $competition->refresh();
        $phase = $competition->phase();

        if ($phase === CompetitionPhase::Finalized && !$refinalize) {
            return ['finalized' => false, 'placements' => $competition->finishes()->count(), 'total_players' => (int) $competition->finishes()->max('total_players')];
        }
        if ($phase !== CompetitionPhase::Ended && $phase !== CompetitionPhase::Finalized) {
            throw new ClubDomainException(ClubDomainException::COMPETITION_NOT_EDITABLE, 422);
        }

        $this->expireAttempts($competition);
        if ($refinalize) {
            $this->standings->rebuild($competition);
        }

        $result = DB::transaction(function () use ($competition, $actor, $refinalize, $actorKind) {
            /** @var ClubCompetition $row */
            $row = ClubCompetition::whereKey($competition->id)->lockForUpdate()->firstOrFail();
            if ($row->state === CompetitionState::Finalized && !$refinalize) {
                return ['finalized' => false, 'placements' => $row->finishes()->count(), 'total_players' => (int) $row->finishes()->max('total_players')];
            }
            if (!in_array($row->state, [CompetitionState::Published, CompetitionState::Finalized], true)) {
                throw new ClubDomainException(ClubDomainException::COMPETITION_NOT_EDITABLE, 422);
            }

            $ranked = $this->standings->ranked($row);
            $total  = $ranked->count();
            $now    = now();

            ClubCompetitionFinish::where('club_competition_id', $row->id)->delete();
            foreach ($ranked->chunk(200) as $chunk) {
                ClubCompetitionFinish::insert($chunk->map(fn (array $e) => [
                    'club_competition_id' => $row->id,
                    'user_id'             => $e['user_id'],
                    'placement'           => $e['rank'],
                    'total_score'         => $e['total_score'],
                    'total_players'       => $total,
                    'metadata'            => json_encode([
                        'packs_completed'  => $e['packs_completed'],
                        'attempts_used'    => $e['attempts_used'],
                        'score_reached_at' => $e['score_reached_at'],
                    ]),
                    'awarded_at'          => $now,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ])->values()->all());
            }

            $wasFinalized = $row->state === CompetitionState::Finalized;
            $row->forceFill([
                'state'        => CompetitionState::Finalized,
                'finalized_at' => $wasFinalized ? $row->finalized_at : $now,
            ])->save();

            $this->audit->log($row->club, $wasFinalized ? 'competition.rebuilt' : 'competition.finalized', $actor, $row, [
                'competition_id' => $row->id,
                'placements'     => $total,
                'participants'   => (int) $row->participants_count,
                'source'         => $wasFinalized ? 'refinalize' : ($actor ? 'admin' : 'scheduler'),
            ], $actor ? $actorKind : ClubAuditEvent::ACTOR_SYSTEM);

            return ['finalized' => true, 'placements' => $total, 'total_players' => $total];
        });

        $this->memberships->refreshCounters($competition->club);
        $competition->refresh();

        // Member push with the placement (Wave F), after the commit. At most
        // once: a re-finalize after a void finds the event already claimed.
        if ($result['finalized']) {
            $this->notifier->finalized($competition);
        }

        return $result;
    }
}
