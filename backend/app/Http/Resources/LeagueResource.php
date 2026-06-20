<?php
namespace App\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;

class LeagueResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'join_code' => $this->join_code,
            'duration_days' => $this->duration_days,
            'rounds_per_day' => $this->rounds_per_day,
            'status' => $this->status,
            'total_rounds' => $this->rounds()->count(),
            'members_count' => $this->members()->count(),
            'members' => UserResource::collection($this->whenLoaded('members')),
        ];
    }
}
