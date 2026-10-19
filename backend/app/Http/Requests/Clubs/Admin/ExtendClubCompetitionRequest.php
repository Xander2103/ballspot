<?php

namespace App\Http\Requests\Clubs\Admin;

use Carbon\Carbon;

/** Extend ends_at (D20: later only, audited). `ends_at` in the club timezone. */
class ExtendClubCompetitionRequest extends AdminClubRequest
{
    public function rules(): array
    {
        return [
            'ends_at' => ['required', 'date_format:' . ClubCompetitionRequest::DATETIME],
            'version' => ['required', 'integer'],
        ];
    }

    public function endsAt(string $timezone): Carbon
    {
        return Carbon::createFromFormat(ClubCompetitionRequest::DATETIME, (string) $this->validated('ends_at'), $timezone)->utc()->startOfMinute();
    }
}
