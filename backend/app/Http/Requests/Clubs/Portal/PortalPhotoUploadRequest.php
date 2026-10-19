<?php

namespace App\Http\Requests\Clubs\Portal;

/**
 * Club photo upload from the portal: up to MAX_FILES images, an optional
 * note and the mandatory rights/consent confirmation (plan §11.5). The image
 * checks themselves (type, size, edge, duplicates) are
 * ClubPhotoReviewService::submit().
 */
class PortalPhotoUploadRequest extends PortalRequest
{
    public const MAX_FILES = 10;

    protected string $ability = 'uploadPhotos';

    protected function prepareForValidation(): void
    {
        $this->merge(['club_note' => $this->clean('club_note')]);
    }

    public function rules(): array
    {
        return [
            'photos'           => ['required', 'array', 'min:1', 'max:' . self::MAX_FILES],
            'photos.*'         => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:' . (int) config('ballspot.clubs.media.max_kb', 15360)],
            'club_note'        => ['nullable', 'string', 'max:500'],
            'rights_confirmed' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'rights_confirmed.accepted' => __('clubs.errors.photo_rights_required'),
            'photos.max'                => __('club_portal.photos.too_many', ['max' => self::MAX_FILES]),
        ];
    }
}
