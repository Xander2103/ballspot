<?php

namespace App\Http\Requests\Clubs\Portal;

/** Owner hands ownership to another active member (they become admin). Typed confirmation of the username. */
class PortalTransferOwnershipRequest extends PortalRequest
{
    protected string $ability = 'transferOwnership';

    public function rules(): array
    {
        return [
            'member_id' => ['required', 'integer'],
            'confirm'   => ['required', 'string', 'max:100'],
        ];
    }
}
