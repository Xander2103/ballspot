<?php

namespace App\Http\Requests\Clubs\Api;

use App\Services\Clubs\MyClubsQuery;
use Illuminate\Validation\Rule;

/** GET /api/clubs?q=&sort=recent|name&cursor=&limit= */
class MyClubsIndexRequest extends CursorPageRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            // Trimmed and cut to 60 characters by MyClubsQuery (lenient on purpose).
            'q'    => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', 'string', Rule::in(MyClubsQuery::SORTS)],
        ];
    }
}
