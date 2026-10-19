<?php

namespace App\Http\Requests\Clubs\Portal;

use App\Http\Requests\Clubs\Admin\ExtendClubCompetitionRequest;
use App\Models\Club;

/** Extend ends_at (later only, D20) from the portal; club timezone input. */
class PortalExtendCompetitionRequest extends ExtendClubCompetitionRequest
{
    public function authorize(): bool
    {
        $club = $this->route('club');

        return $club instanceof Club && (bool) $this->user()?->can('manageCompetitions', $club);
    }
}
