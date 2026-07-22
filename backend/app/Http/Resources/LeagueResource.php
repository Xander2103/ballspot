<?php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LeagueResource extends JsonResource
{
    public function toArray($request): array
    {
        $userId = $request?->user()?->id;

        $roundsCount = $this->rounds()->where('status', 'open')->count();

        $completedRounds = 0;
        if ($userId && $roundsCount > 0) {
            $completedRounds = $this->rounds()
                ->where('status', 'open')
                ->whereHas('guesses', fn($q) => $q->where('user_id', $userId))
                ->count();
        }

        $remaining   = max(0, $roundsCount - $completedRounds);
        $progressPct = $roundsCount > 0
            ? (int) round($completedRounds / $roundsCount * 100)
            : 0;

        return [
            'id'                     => $this->id,
            'name'                   => $this->name,
            'join_code'              => $this->join_code,
            'duration_days'          => $this->duration_days,
            'rounds_per_day'         => $this->rounds_per_day,
            'status'                 => $this->status,
            'sport'                  => $this->sport ? [
                'id'            => $this->sport->id,
                'slug'          => $this->sport->slug,
                'name'          => $this->sport->name,
                'emoji'         => $this->sport->emoji,
                'primary_color' => $this->sport->primary_color,
            ] : null,
            'owner_user_id'          => $this->owner_user_id,
            'is_owner'               => $userId === $this->owner_user_id,
            'members_count'          => $this->members()->count(),
            'rounds_count'           => $roundsCount,
            'completed_rounds_count' => $completedRounds,
            'remaining_rounds_count' => $remaining,
            'progress_pct'           => $progressPct,
            'starts_at'              => $this->starts_at?->toISOString(),
            'ends_at'                => $this->ends_at?->toISOString(),
            'members'                => $this->whenLoaded('members', function () {
                return $this->members->map(fn($m) => [
                    'id'        => $m->id,
                    'name'      => $m->name,
                    'username'  => $m->username,
                    'is_owner'  => (int) $m->id === (int) $this->owner_user_id,
                    'joined_at' => $m->pivot?->joined_at
                        ? \Carbon\Carbon::parse($m->pivot->joined_at)->toISOString()
                        : null,
                ]);
            }),
        ];
    }
}
