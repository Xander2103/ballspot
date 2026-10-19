<?php

namespace App\Http\Requests\Clubs\Portal;

use App\Enums\ClubRole;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * New invite code from the portal: label, expiry, max uses, granted role.
 * Admin-granting codes are owner-only (an admin may only create member codes).
 */
class PortalStoreInviteCodeRequest extends PortalRequest
{
    protected string $ability = 'manageCodes';

    protected $errorBag = 'newCode';

    protected function prepareForValidation(): void
    {
        $this->merge(['label' => $this->clean('label'), 'grants_role' => $this->input('grants_role', ClubRole::Member->value)]);
    }

    public function rules(): array
    {
        return [
            'label'       => ['nullable', 'string', 'max:60'],
            'expires_at'  => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'max_uses'    => ['nullable', 'integer', 'min:1', 'max:100000'],
            'grants_role' => ['required', Rule::in($this->grantableRoles())],
        ];
    }

    public function messages(): array
    {
        return ['grants_role.in' => __('club_portal.codes.admin_codes_owner_only')];
    }

    /** @return list<string> */
    public function grantableRoles(): array
    {
        $member = app(\App\Services\Clubs\ClubMembershipService::class)->membership($this->club(), $this->user());

        return \App\Services\ClubPortal\ClubPortalMemberRules::grantableCodeRoles($member) ?: [ClubRole::Member->value];
    }

    /** Options for ClubInviteCodeService::create(); a date expiry means the end of that day. */
    public function options(): array
    {
        $expires = $this->input('expires_at');

        return [
            'label'       => $this->input('label'),
            'expires_at'  => is_string($expires) && $expires !== '' ? Carbon::createFromFormat('Y-m-d', $expires)->endOfDay() : null,
            'max_uses'    => $this->filled('max_uses') ? (int) $this->input('max_uses') : null,
            'grants_role' => (string) $this->input('grants_role'),
        ];
    }
}
