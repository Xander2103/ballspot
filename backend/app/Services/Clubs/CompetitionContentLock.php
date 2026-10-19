<?php

namespace App\Services\Clubs;

use App\Enums\CompetitionState;
use App\Models\Challenge;
use App\Models\ChallengePack;
use App\Models\ClubCompetitionPack;
use Illuminate\Support\Facades\DB;

/**
 * "Linked to a locked competition" — competition content freezes at the rule
 * lock (plan §5.7, invariant 6). Shared by the photo review (adding to a
 * pack), the admin challenge editor (ball position / images / status) and the
 * challenge delete guard.
 *
 * A competition is locked from starts_at on (ClubCompetition::isLocked) even
 * before the lazy lock action wrote the snapshot; until then the pack's
 * current challenges are what the snapshot will contain, so they count too.
 */
class CompetitionContentLock
{
    /** The pack is linked to a published competition whose rules are locked. */
    public function packIsLocked(ChallengePack $pack): bool
    {
        return $pack->competitions()
            ->where('club_competitions.state', CompetitionState::Published->value)
            ->get()
            ->contains(fn ($competition) => $competition->isLocked());
    }

    /**
     * The challenge is part of the (effective) snapshot of a PUBLISHED
     * competition with locked rules: its ball position, images and active
     * status must not change until the competition is finalized.
     */
    public function challengeIsLocked(Challenge $challenge): bool
    {
        return $this->inSnapshot($challenge, [CompetitionState::Published], lockedOnly: true);
    }

    /**
     * The challenge is in the snapshot of any competition that is not
     * finalized (published — locked or about to snapshot it — or voided):
     * deleting it would break attempts and the audit trail.
     */
    public function challengeInOpenSnapshot(Challenge $challenge): bool
    {
        return $this->inSnapshot($challenge, [CompetitionState::Published, CompetitionState::Voided], lockedOnly: false);
    }

    /**
     * @param list<CompetitionState> $states
     */
    private function inSnapshot(Challenge $challenge, array $states, bool $lockedOnly): bool
    {
        $id     = (int) $challenge->id;
        $values = array_map(fn (CompetitionState $s) => $s->value, $states);

        // 1. Written snapshots (few rows: open competitions only). Matched in
        //    PHP — JSON contains is not portable to SQLite.
        $snapshotted = ClubCompetitionPack::query()
            ->with('competition')
            ->whereNotNull('challenge_ids')
            ->whereHas('competition', fn ($q) => $q->whereIn('state', $values))
            ->get()
            ->contains(fn (ClubCompetitionPack $link) => in_array($id, $link->snapshotIds(), true)
                && (!$lockedOnly || $link->competition->isLocked()));
        if ($snapshotted) {
            return true;
        }

        // 2. Locked (starts_at passed) but the lock action has not run yet:
        //    the pack's current challenges become the snapshot.
        $packIds = DB::table('challenge_pack_challenge')->where('challenge_id', $id)->pluck('challenge_pack_id');
        if ($packIds->isEmpty()) {
            return false;
        }

        return ClubCompetitionPack::query()
            ->with('competition')
            ->whereNull('challenge_ids')
            ->whereIn('challenge_pack_id', $packIds)
            ->whereHas('competition', fn ($q) => $q->where('state', CompetitionState::Published->value))
            ->get()
            ->contains(fn (ClubCompetitionPack $link) => in_array(CompetitionState::Published, $states, true)
                && (!$lockedOnly || $link->competition->isLocked()));
    }
}
