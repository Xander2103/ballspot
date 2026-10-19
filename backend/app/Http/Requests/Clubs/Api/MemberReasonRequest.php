<?php

namespace App\Http\Requests\Clubs\Api;


/** POST /api/clubs/{club}/members/{userId}/remove|ban — optional `{ reason }` (≤ 200, stored + audited). */
class MemberReasonRequest extends ClubAdminRequest
{
    protected string $ability = 'manageMembers';

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => $this->cleanString('reason')]);
    }

    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:200']];
    }

    public function reason(): ?string
    {
        return $this->validated('reason');
    }
}
