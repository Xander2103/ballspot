<?php
namespace App\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;

class LeagueRoundResource extends JsonResource
{
    public function toArray($request): array
    {
        $challenge = $this->challenge;
        $challenge->loadMissing('sport');
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
                // The challenge's own sport drives the guess marker in the app
                // (a tournament can only ever be one sport, but the payload is
                // per challenge for consistency with daily/pack).
                'sport'            => $challenge->sport ? [
                    'slug'          => $challenge->sport->slug,
                    'name'          => $challenge->sport->name,
                    'emoji'         => $challenge->sport->emoji,
                    'object_name'   => $challenge->sport->object_name,
                    'primary_color' => $challenge->sport->primary_color,
                ] : null,
            ],
        ];
    }
}
