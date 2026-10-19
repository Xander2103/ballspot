<?php

namespace App\Http\Requests\Clubs\Api;


/**
 * POST /api/clubs/{club}/photos (multipart) — one `photo`, the mandatory
 * `rights_confirmed` (plan §11.5) and an optional `note`. The image checks
 * themselves (type, size, edge, duplicates, open limit) are
 * ClubPhotoReviewService::submit(), shared with the portal and the admin.
 */
class UploadClubPhotoRequest extends ClubAdminRequest
{
    protected string $ability = 'uploadPhotos';

    protected function prepareForValidation(): void
    {
        $this->merge(['note' => $this->cleanString('note')]);
    }

    public function rules(): array
    {
        return [
            'photo'            => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:' . (int) config('ballspot.clubs.media.max_kb', 15360)],
            'rights_confirmed' => ['accepted'],
            'note'             => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return ['rights_confirmed.accepted' => __('clubs.errors.photo_rights_required')];
    }
}
