<?php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public sport/category summary used for onboarding selection, sport badges,
 * and preference echoes. Safe to expose to any authenticated user.
 */
class SportResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'slug'           => $this->slug,
            'emoji'          => $this->emoji,
            'object_name'    => $this->object_name,
            'primary_color'  => $this->primary_color,
            'status'         => $this->status,
            'is_playable'    => $this->isPlayable(),
            'is_coming_soon' => $this->isComingSoon(),
            // Retained for backward compatibility (mirrors status === active).
            'is_active'      => (bool) $this->is_active,
        ];
    }
}
