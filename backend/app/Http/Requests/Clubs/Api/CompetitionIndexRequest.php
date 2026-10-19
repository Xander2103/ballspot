<?php

namespace App\Http\Requests\Clubs\Api;

use App\Services\Clubs\ClubCompetitionQuery;
use Illuminate\Validation\Rule;

/** GET /api/clubs/{club}/competitions?phase=active|upcoming|completed&cursor=&limit= */
class CompetitionIndexRequest extends CursorPageRequest
{
    public function rules(): array
    {
        return parent::rules() + ['phase' => ['nullable', 'string', Rule::in(ClubCompetitionQuery::PHASES)]];
    }

    public function phase(): string
    {
        return (string) ($this->validated('phase') ?? 'active');
    }
}
