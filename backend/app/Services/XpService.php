<?php

namespace App\Services;

use App\Models\User;
use App\Models\XpEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * The XP ledger is the source of truth for player XP / rank.
 *
 * Every award is an append-only row in xp_events. Awards tied to a concrete
 * source (a guess, a badge, a streak milestone) are de-duplicated on
 * (user, source_type, source_id) so replays never double-count.
 */
class XpService
{
    /**
     * Award XP to a user. Returns the created XpEvent, or null if the amount is
     * zero or this exact source was already awarded.
     */
    public function awardXp(
        User $user,
        string $sourceType,
        ?int $sourceId,
        int $amount,
        string $reason,
        array $metadata = [],
    ): ?XpEvent {
        if ($amount === 0) {
            return null;
        }

        // De-dupe sourced awards (NULL source_id awards are always allowed).
        if ($sourceId !== null && $this->alreadyAwarded($user, $sourceType, $sourceId)) {
            return null;
        }

        try {
            return XpEvent::create([
                'user_id'     => $user->id,
                'source_type' => $sourceType,
                'source_id'   => $sourceId,
                'amount'      => $amount,
                'reason'      => $reason,
                'metadata'    => empty($metadata) ? null : $metadata,
            ]);
        } catch (QueryException $e) {
            // Lost a race on the unique index — treat as already awarded.
            return null;
        }
    }

    public function alreadyAwarded(User $user, string $sourceType, int $sourceId): bool
    {
        return XpEvent::where('user_id', $user->id)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->exists();
    }

    /** Total ledger XP for a user (pure ledger — no score fallback). */
    public function getTotalXp(User $user): int
    {
        return (int) XpEvent::where('user_id', $user->id)->sum('amount');
    }

    public function hasAnyEvents(User $user): bool
    {
        return XpEvent::where('user_id', $user->id)->exists();
    }

    /** @return Collection<int,XpEvent> most-recent first */
    public function getRecentXpEvents(User $user, int $limit = 20): Collection
    {
        return XpEvent::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
