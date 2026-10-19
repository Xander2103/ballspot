<?php

namespace App\Http\Requests\Clubs\Admin;

use App\Enums\ClubRole;
use Illuminate\Validation\Rule;

/** New invite code: label, expiry, max uses, granted role (member/admin — never owner). */
class StoreClubInviteCodeRequest extends AdminClubRequest
{
    protected $errorBag = 'newCode';

    protected function prepareForValidation(): void
    {
        $this->merge(['label' => $this->clean('label')]);
    }

    public function rules(): array
    {
        return [
            'label'       => ['nullable', 'string', 'max:60'],
            'expires_at'  => ['nullable', 'date', 'after_or_equal:today'],
            'max_uses'    => ['nullable', 'integer', 'min:1', 'max:100000'],
            'grants_role' => ['required', Rule::in(array_map(fn (ClubRole $r) => $r->value, ClubRole::grantable()))],
        ];
    }

    /** Options for ClubInviteCodeService::create(). A date-only expiry means the end of that day. */
    public function options(): array
    {
        $expires = $this->input('expires_at');
        $expiresAt = null;
        if (is_string($expires) && $expires !== '') {
            $expiresAt = \Illuminate\Support\Carbon::parse($expires);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires)) {
                $expiresAt = $expiresAt->endOfDay();
            }
        }

        return [
            'label'       => $this->input('label'),
            'expires_at'  => $expiresAt,
            'max_uses'    => $this->filled('max_uses') ? (int) $this->input('max_uses') : null,
            'grants_role' => (string) $this->input('grants_role'),
        ];
    }
}
