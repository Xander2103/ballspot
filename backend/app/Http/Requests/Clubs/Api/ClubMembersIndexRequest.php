<?php

namespace App\Http\Requests\Clubs\Api;

/** GET /api/clubs/{club}/members?q=&cursor=&limit= */
class ClubMembersIndexRequest extends CursorPageRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'q' => ['nullable', 'string', 'max:200'],
        ];
    }
}
