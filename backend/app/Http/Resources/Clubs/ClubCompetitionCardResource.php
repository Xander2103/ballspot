<?php

namespace App\Http\Resources\Clubs;

use App\Models\ClubCompetition;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * CompetitionCard (contracts §5) as used by the club detail's
 * competitions_preview. Expects `competition_packs_count` (withCount) and,
 * optionally, the viewer's standing passed as `me`
 * ({rank, total_score, packs_completed} or null).
 *
 * The Competitions track (Wave C) owns the competition endpoints; it may
 * reuse or extend this resource so both surfaces keep one card shape.
 *
 * @property ClubCompetition $resource
 */
class ClubCompetitionCardResource extends JsonResource
{
    public function __construct(ClubCompetition $competition, private ?array $me = null)
    {
        parent::__construct($competition);
    }

    public function toArray($request): array
    {
        $c = $this->resource;

        return [
            'id'                 => $c->public_id,
            'name'               => $c->name,
            'description'        => $c->description,
            'phase'              => $c->phase()->value,
            'starts_at'          => $c->starts_at?->toISOString(),
            'ends_at'            => $c->ends_at?->toISOString(),
            'timezone'           => $c->timezone,
            'rules'              => [
                'replay_mode'        => $c->replay_mode->value,
                'max_attempts'       => (int) $c->max_attempts,
                'score_rule'         => $c->score_rule->value,
                'normal_play_policy' => $c->normal_play_policy->value,
            ],
            'packs_count'        => (int) ($c->competition_packs_count ?? $c->competitionPacks()->count()),
            'participants_count' => (int) $c->participants_count,
            'me'                 => $this->me,
        ];
    }
}
