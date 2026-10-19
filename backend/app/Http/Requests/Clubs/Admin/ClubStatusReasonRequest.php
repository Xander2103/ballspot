<?php

namespace App\Http\Requests\Clubs\Admin;

/** Suspend / archive: a reason is required (stored and audited). */
class ClubStatusReasonRequest extends AdminClubRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => $this->clean('reason')]);
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:200']];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Give a reason — it is stored with the status change and in the audit log.'];
    }
}
