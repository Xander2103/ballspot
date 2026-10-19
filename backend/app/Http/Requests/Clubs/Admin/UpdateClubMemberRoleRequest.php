<?php

namespace App\Http\Requests\Clubs\Admin;

use App\Enums\ClubRole;
use Illuminate\Validation\Rule;

/** Change an active member's role (last-owner protection lives in the service). */
class UpdateClubMemberRoleRequest extends AdminClubRequest
{
    public function rules(): array
    {
        return ['role' => ['required', Rule::in(ClubRole::values())]];
    }

    public function role(): ClubRole
    {
        return ClubRole::from((string) $this->input('role'));
    }
}
