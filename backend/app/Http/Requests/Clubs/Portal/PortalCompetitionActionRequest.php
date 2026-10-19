<?php

namespace App\Http\Requests\Clubs\Portal;

use App\Http\Requests\Clubs\Admin\ClubCompetitionActionRequest;
use App\Models\Club;

/** Publish / cancel from the portal (optional reason, `version` against stale pages). */
class PortalCompetitionActionRequest extends ClubCompetitionActionRequest
{
    public function authorize(): bool
    {
        $club = $this->route('club');

        return $club instanceof Club && (bool) $this->user()?->can('manageCompetitions', $club);
    }
}
