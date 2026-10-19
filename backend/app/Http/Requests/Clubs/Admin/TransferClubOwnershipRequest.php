<?php

namespace App\Http\Requests\Clubs\Admin;

/** Make an active member the (single) owner; previous owners become admins. */
class TransferClubOwnershipRequest extends AdminClubRequest
{
    public function rules(): array
    {
        return ['member_id' => ['required', 'integer']];
    }
}
