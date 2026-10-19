<?php

namespace App\Http\Requests\Clubs\Portal;

/** Remove / ban a member: optional reason (stored on the membership + audited). */
class PortalMemberReasonRequest extends PortalRequest
{
    protected string $ability = 'manageMembers';

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => $this->clean('reason')]);
    }

    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:200']];
    }
}
