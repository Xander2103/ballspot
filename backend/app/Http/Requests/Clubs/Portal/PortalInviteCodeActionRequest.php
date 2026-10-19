<?php

namespace App\Http\Requests\Clubs\Portal;

/** Revoke / regenerate an invite code (no input; owner-only rules for admin codes in the controller). */
class PortalInviteCodeActionRequest extends PortalRequest
{
    protected string $ability = 'manageCodes';

    public function rules(): array
    {
        return [];
    }
}
