<?php

namespace App\Http\Requests\Clubs\Portal;

/** Lift a ban (no input). */
class PortalUnbanRequest extends PortalRequest
{
    protected string $ability = 'manageMembers';

    public function rules(): array
    {
        return [];
    }
}
