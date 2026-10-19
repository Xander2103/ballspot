<?php

namespace App\Services\Clubs;

use App\Enums\CompetitionState;
use App\Exceptions\ClubDomainException;
use App\Models\ChallengePack;
use App\Models\ClubAuditEvent;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionPack;
use App\Models\User;
use App\Support\AppLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Rule lock + editability matrix of a competition (plan §5.7). Enforced in
 * the service layer — the admin form only mirrors it (read-only fields).
 *
 * Lock moment = starts_at: isLocked() is `rules_locked_at !== null ||
 * now ≥ starts_at`, so it never depends on the scheduler. The lock ACTION
 * (snapshot challenge_ids of every linked pack, set rules_locked_at, audit
 * `competition.rules_locked`) is idempotent and runs from the scheduler tick
 * and lazily inside the first attempt start, whichever comes first.
 *
 * | field                               | draft | upcoming | active      | ended | finalized |
 * | name, description                   | ✓     | ✓        | ✓           | ✓     | ✓         |
 * | replay_mode, max_attempts, score_rule, normal_play_policy, packs, starts_at | ✓ | ✓ | 🔒 | 🔒 | 🔒 |
 * | ends_at                             | ✓     | ✓        | extend only | 🔒    | 🔒        |
 * Cancelled / voided competitions only accept name/description.
 */
class ClubCompetitionRulesGuard
{
    /** Fields that freeze at the rule lock. */
    public const RULE_FIELDS = ['replay_mode', 'max_attempts', 'score_rule', 'normal_play_policy', 'pack_ids', 'starts_at'];

    /** Fields editable in every state. */
    public const FREE_FIELDS = ['name', 'description'];

    public function __construct(private ClubAuditLogger $audit) {}

    public function isLocked(ClubCompetition $competition, ?CarbonInterface $now = null): bool
    {
        return $competition->isLocked($now);
    }

    /** True when the field can be changed right now (for read-only rendering). */
    public function isEditable(ClubCompetition $competition, string $field, ?CarbonInterface $now = null): bool
    {
        if (in_array($field, self::FREE_FIELDS, true)) {
            return true;
        }
        if (in_array($competition->state, [CompetitionState::Cancelled, CompetitionState::Voided, CompetitionState::Finalized], true)) {
            return false;
        }
        if (!$competition->isLocked($now)) {
            return true;
        }
        // Locked: only ends_at, and only while the window is still open (extend only).
        return $field === 'ends_at' && ($now ?? now())->lt($competition->ends_at);
    }

    /**
     * Reject a set of CHANGED fields that the matrix does not allow.
     *
     * @param array<string, mixed> $changes field => new value (only fields that differ)
     */
    public function assertCanChange(ClubCompetition $competition, array $changes, ?CarbonInterface $now = null): void
    {
        $now ??= now();
        foreach ($changes as $field => $value) {
            if (in_array($field, self::FREE_FIELDS, true)) {
                continue;
            }
            if (in_array($competition->state, [CompetitionState::Cancelled, CompetitionState::Voided, CompetitionState::Finalized], true)) {
                throw new ClubDomainException(
                    $competition->state === CompetitionState::Finalized ? ClubDomainException::COMPETITION_RULES_LOCKED : ClubDomainException::COMPETITION_NOT_EDITABLE,
                    422,
                );
            }
            if (!$competition->isLocked($now)) {
                continue;
            }
            if ($field === 'ends_at') {
                $this->assertExtension($competition, $value, $now);
                continue;
            }
            throw new ClubDomainException(ClubDomainException::COMPETITION_RULES_LOCKED, 422);
        }
    }

    /** D20: after the lock ends_at may only move later, and only while the window is open. */
    public function assertExtension(ClubCompetition $competition, CarbonInterface $newEndsAt, ?CarbonInterface $now = null): void
    {
        $now ??= now();
        if ($now->gte($competition->ends_at) || $newEndsAt->lte($competition->ends_at)) {
            throw new ClubDomainException(ClubDomainException::COMPETITION_RULES_LOCKED, 422);
        }
    }

    /**
     * The lock action (idempotent). Returns true when THIS call locked the
     * competition, false when it was already locked or is not published.
     * Snapshots the ordered ready-challenge ids of every linked pack; a pack
     * that became unplayable gets an empty snapshot (it cannot be started —
     * competition_invalid_pack) and a warning, but never blocks the start.
     */
    public function lock(ClubCompetition $competition, ?User $actor = null, string $actorKind = ClubAuditEvent::ACTOR_SYSTEM): bool
    {
        [$row, $lockedNow] = DB::transaction(function () use ($competition, $actor, $actorKind) {
            /** @var ClubCompetition $row */
            $row = ClubCompetition::whereKey($competition->id)->lockForUpdate()->firstOrFail();
            if ($row->rules_locked_at !== null || $row->state !== CompetitionState::Published) {
                return [$row, false];
            }

            $empty = [];
            $links = ClubCompetitionPack::where('club_competition_id', $row->id)->orderBy('sort_order')->orderBy('id')->get();
            foreach ($links as $link) {
                $pack = ChallengePack::with('challenges')->find($link->challenge_pack_id);
                $ids  = ($pack && $pack->club_id === $row->club_id && $pack->status === ChallengePack::STATUS_ACTIVE)
                    ? $pack->readyChallenges()->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
                    : [];
                if ($ids === []) {
                    $empty[] = $link->challenge_pack_id;
                }
                $link->forceFill(['challenge_ids' => $ids, 'snapshotted_at' => now()])->save();
            }

            $row->forceFill(['rules_locked_at' => now()])->save();

            $this->audit->log($row->club, 'competition.rules_locked', $actor, $row, [
                'competition_id' => $row->id,
                'count'          => $links->count(),
                'replay_mode'    => $row->replay_mode,
                'max_attempts'   => $row->max_attempts,
                'score_rule'     => $row->score_rule,
                'normal_play_policy' => $row->normal_play_policy,
            ], $actorKind);

            if ($empty !== []) {
                AppLog::warn('club.competition_lock_empty_pack', ['competition_id' => $row->id, 'pack_ids' => $empty]);
            }

            return [$row, true];
        });

        $competition->setRawAttributes($row->getAttributes(), true);

        return $lockedNow;
    }
}
