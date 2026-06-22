<?php
namespace App\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaderboardEntryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'rank' => $this->resource['rank'],
            'user_id' => $this->resource['user_id'],
            'username' => $this->resource['username'],
            'name' => $this->resource['name'],
            'total_score' => $this->resource['total_score'],
            'guesses_count' => $this->resource['guesses_count'],
            'avg_score' => $this->resource['avg_score'],
            'is_current_user' => $this->resource['is_current_user'],
        ];
    }
}
