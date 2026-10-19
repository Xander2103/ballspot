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

    /**
     * The GET /api/packs/{slug} `data` payload: the summary above plus safe
     * summaries of the READY challenges. Shared with the club pack detail
     * (GET /api/clubs/{club}/packs/{slug}) so both stay identical. Expects
     * `challenges` (with sport + category) and `sport` loaded.
     */
    public static function detail(\App\Models\ChallengePack $pack): array
    {
        $challenges = $pack->challenges
            ->filter(fn (\App\Models\Challenge $c) => $c->isReadyForDaily())
            ->values();

        $payload = (new self($pack))->resolve();
        // challenge_count should reflect what a user can actually play here.
        $payload['challenge_count'] = $challenges->count();
        $payload['challenges'] = $challenges->map(fn (\App\Models\Challenge $c) => [
            'id'               => $c->id,
            'title'            => $c->title,
            'difficulty'       => $c->difficulty,
            // SECURITY: never expose ball_x_ratio / ball_y_ratio / reveal image here.
            'hidden_image_url' => \App\Support\ChallengeImageUrl::hidden($c),
            'sport'            => $c->sport ? [
                'slug'  => $c->sport->slug,
                'name'  => $c->sport->name,
                'emoji' => $c->sport->emoji,
            ] : null,
            'category'         => $c->category ? ['name' => $c->category->name, 'slug' => $c->category->slug] : null,
        ])->values();

        return $payload;
    }
}
