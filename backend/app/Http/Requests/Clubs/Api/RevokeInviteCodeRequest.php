<?php

namespace App\Http\Requests\Clubs\Api;

/** POST /api/clubs/{club}/invite-codes/{id}/revoke — no body; any owner/admin may revoke any code of the club. */
class RevokeInviteCodeRequest extends ClubAdminRequest
{
    protected string $ability = 'manageCodes';

    public function rules(): array
    {
        return [];
    }
}
