<?php
namespace App\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;

class LeagueRoundResource extends JsonResource
{
    public function toArray($request): array
    {
        $challenge = $this->challenge;
        return [
            'id'           => $this->id,
            'round_number' => $this->round_number,
            'status'       => $this->status,
            'challenge'    => [
                'id'               => $challenge->id,
                'title'            => $challenge->title,
                'difficulty'       => $challenge->difficulty,
                'hidden_image_url' => asset('storage/' . $challenge->hidden_image_path),
                'category'         => $challenge->category ? [
                    'id'   => $challenge->category->id,
                    'name' => $challenge->category->name,
                    'slug' => $challenge->category->slug,
                ] : null,
            ],
        ];
    }
}
