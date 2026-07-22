<?php

namespace App\Services;

use App\Models\Challenge;
use App\Models\DailyChallenge;
use App\Models\Sport;

/**
 * Answers "is this sport ready to graduate from coming_soon to active?".
 * Advisory only — activation is never hard-blocked (admin decides).
 */
class SportReadinessService
{
    /**
     * @return array{ready_challenges:int,scheduled_dailies:int,min_ready_challenges:int,min_scheduled_dailies:int,is_ready:bool}
     */
    public function for(Sport $sport): array
    {
        $ready = Challenge::where('sport_id', $sport->id)
            ->where('status', 'active')
            ->whereNotNull('hidden_image_path')
            ->where('hidden_image_path', '!=', '')
            ->whereNotNull('ball_x_ratio')
            ->whereNotNull('ball_y_ratio')
            ->count();

        $scheduled = DailyChallenge::whereHas('challenge', fn ($q) => $q->where('sport_id', $sport->id))->count();

        $minReady     = (int) config('ballspot.sport_readiness.min_ready_challenges');
        $minScheduled = (int) config('ballspot.sport_readiness.min_scheduled_dailies');

        return [
            'ready_challenges'      => $ready,
            'scheduled_dailies'     => $scheduled,
            'min_ready_challenges'  => $minReady,
            'min_scheduled_dailies' => $minScheduled,
            'is_ready'              => $ready >= $minReady && $scheduled >= $minScheduled,
        ];
    }
}
