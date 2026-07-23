<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public pack summary for discovery. Only non-sensitive fields — no admin-only
 * columns (status/visibility/sort_order), no prices (packs are content-only).
 */
class ChallengePackResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'slug'            => $this->slug,
            'description'     => $this->description,
            'sport'           => $this->sport ? [
                'slug'          => $this->sport->slug,
                'name'          => $this->sport->name,
                'emoji'         => $this->sport->emoji,
                'primary_color' => $this->sport->primary_color,
            ] : null,
            'cover_image_url' => $this->coverImageUrl(),
            'difficulty'      => $this->difficulty,
            'challenge_count' => $this->challenges_count ?? $this->challenges->count(),
            'is_featured'     => (bool) $this->is_featured,
        ];
    }
}
