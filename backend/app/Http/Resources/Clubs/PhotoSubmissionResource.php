<?php

namespace App\Http\Resources\Clubs;

use App\Models\ClubPhotoSubmission;
use App\Services\Clubs\ClubMediaService;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PhotoSubmission (contracts §9, club-admin API). The thumbnail is a
 * short-lived signed `clubs.media` URL of the private file; the path, disk,
 * hash, uploader and admin notes are never exposed.
 *
 * @property ClubPhotoSubmission $resource
 */
class PhotoSubmissionResource extends JsonResource
{
    public function toArray($request): array
    {
        $p = $this->resource;

        $thumbnail = null;
        if (is_string($p->path) && $p->path !== '') {
            try {
                $thumbnail = app(ClubMediaService::class)->signedUrl($p->path);
            } catch (\Throwable) {
                $thumbnail = null;
            }
        }

        return [
            'id'               => (int) $p->id,
            'status'           => $p->status->value,
            'club_note'        => $p->club_note,
            'rejection_reason' => $p->rejection_reason,
            'thumbnail_url'    => $thumbnail,
            'created_at'       => $p->created_at?->toISOString(),
        ];
    }
}
