<?php

namespace App\Http\Requests\Clubs\Portal;

use App\Enums\ClubRole;
use Illuminate\Validation\Rule;

/** Role change admin ↔ member (owner only — checked by ClubPortalMemberRules; never to owner). */
class PortalMemberRoleRequest extends PortalRequest
{
    protected string $ability = 'manageMembers';

    public function rules(): array
    {
        return ['role' => ['required', Rule::in([ClubRole::Admin->value, ClubRole::Member->value])]];
    }

    public function role(): ClubRole
    {
        return ClubRole::from((string) $this->input('role'));
    }
}
