<?php

namespace App\Http\Requests\Clubs\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `cursor` + `limit` of every paginated member-API list. `limit` is clamped
 * to ballspot.clubs.pagination.max by the query (not rejected), the cursor
 * is opaque (an invalid one answers 422 on `cursor`).
 */
class CursorPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Membership is checked in the controller (404 club_not_found for non-members).
        return true;
    }

    public function rules(): array
    {
        return [
            'cursor' => ['nullable', 'string', 'max:512'],
            'limit'  => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function pageCursor(): ?string
    {
        $cursor = $this->validated('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    public function pageLimit(): ?int
    {
        $limit = $this->validated('limit');

        return $limit === null ? null : (int) $limit;
    }
}
