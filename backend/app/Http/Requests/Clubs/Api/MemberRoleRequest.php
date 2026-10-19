<?php

namespace App\Http\Requests\Clubs\Api;

use App\Enums\ClubRole;
use Illuminate\Validation\Rule;

/** PATCH /api/clubs/{club}/members/{userId} — `{ role: "admin" | "member" }` (owner only; never to/from owner). */
class MemberRoleRequest extends ClubAdminRequest
{
    protected string $ability = 'transferOwnership';

    public function rules(): array
    {
        return ['role' => ['required', 'string', Rule::in([ClubRole::Admin->value, ClubRole::Member->value])]];
    }

    public function role(): ClubRole
    {
        return ClubRole::from((string) $this->validated('role'));
    }
}
