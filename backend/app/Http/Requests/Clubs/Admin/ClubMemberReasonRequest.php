<?php

namespace App\Http\Requests\Clubs\Admin;

/** Remove / ban: an optional admin-only reason (stored on the membership + audited). */
class ClubMemberReasonRequest extends AdminClubRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => $this->clean('reason')]);
    }

    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:200']];
    }
}
