<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plain badge definition (no per-user state). Trophy-room "earned"/"earned_at"
 * flags are merged in by BadgeController so this stays reusable for new_badges.
 */
class BadgeResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'          => $this->id,
            'code'        => $this->code,
            'name'        => $this->name,
            'description' => $this->description,
            'icon'        => $this->icon,
            'category'    => $this->category,
            'rarity'      => $this->rarity,
            'sort_order'  => $this->sort_order,
        ];
    }
}
