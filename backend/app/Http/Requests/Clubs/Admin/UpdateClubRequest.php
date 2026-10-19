<?php

namespace App\Http\Requests\Clubs\Admin;

/** Edit identity / location / contact with the optimistic `version` (stale_version on mismatch). */
class UpdateClubRequest extends ClubDetailsRequest
{
    public function rules(): array
    {
        return $this->detailRules() + [
            'version'     => ['required', 'integer', 'min:1'],
            'remove_logo' => ['nullable', 'boolean'],
        ];
    }
}
