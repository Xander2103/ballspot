<?php

namespace App\Http\Requests\Clubs\Api;

use App\Enums\ClubRole;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * POST /api/clubs/{club}/invite-codes — `{ label?, max_uses?, expires_at?, grants_role? = member }`.
 * Membership / role / ability (and owner-only admin-granting codes) are
 * checked by the controller with the shared rules, so a non-member still
 * gets 404 before any validation detail.
 */
class StoreInviteCodeRequest extends ClubAdminRequest
{
    protected string $ability = 'manageCodes';

    protected function prepareForValidation(): void
    {
        $this->merge([
            'label'       => $this->cleanString('label'),
            'grants_role' => $this->input('grants_role') ?? ClubRole::Member->value,
        ]);
    }

    public function rules(): array
    {
        return [
            'label'       => ['nullable', 'string', 'max:60'],
            'max_uses'    => ['nullable', 'integer', 'min:1', 'max:100000'],
            'expires_at'  => ['nullable', 'date', 'after:now'],
            'grants_role' => ['required', 'string', Rule::in([ClubRole::Member->value, ClubRole::Admin->value])],
        ];
    }

    public function grantsRole(): ClubRole
    {
        return ClubRole::from((string) $this->validated('grants_role'));
    }

    /** Options for ClubInviteCodeService::create(). */
    public function options(): array
    {
        $expires = $this->validated('expires_at');

        return [
            'label'       => $this->validated('label'),
            'expires_at'  => $expires !== null ? Carbon::parse($expires)->utc() : null,
            'max_uses'    => $this->validated('max_uses') !== null ? (int) $this->validated('max_uses') : null,
            'grants_role' => $this->grantsRole(),
        ];
    }
}
