<?php

namespace App\Http\Resources\Clubs;

use App\Models\ClubCompetitionAttempt;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * CompetitionAttempt (contracts §5). `is_rewarded_run` = false for replays /
 * extra attempts: they score for the competition but earn no global XP (D3).
 * Expects `competition` and `pack` loaded (loaded on demand otherwise).
 *
 * @property ClubCompetitionAttempt $resource
 */
class CompetitionAttemptResource extends JsonResource
{
    public function toArray($request): array
    {
        $a = $this->resource;
        $a->loadMissing(['competition:id,public_id', 'pack:id,slug']);
        $total = $a->totalChallenges();

        return [
            'id'               => (int) $a->id,
            'competition_id'   => $a->competition?->public_id,
            'pack_slug'        => $a->pack?->slug,
            'attempt_number'   => (int) $a->attempt_number,
            'status'           => $a->status->value,
            'current_index'    => (int) $a->current_index,
            'completed_count'  => min((int) $a->current_index, $total),
            'total_challenges' => $total,
            'total_score'      => (int) $a->total_score,
            'is_rewarded_run'  => (bool) $a->is_rewarded_run,
            'started_at'       => $a->started_at?->toISOString(),
            'completed_at'     => $a->completed_at?->toISOString(),
        ];
    }
}
