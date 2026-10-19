<?php

namespace App\Http\Requests\Clubs\Api;

use Illuminate\Foundation\Http\FormRequest;

/** POST …/competitions/{competition}/packs/{slug}/attempts {attempt_number} */
class StartCompetitionAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Membership is checked in the controller (404 club_not_found for non-members).
        return true;
    }

    public function rules(): array
    {
        $cap = max(2, (int) config('ballspot.clubs.competition.max_attempts_cap', 10));

        return ['attempt_number' => ['required', 'integer', 'min:1', 'max:' . $cap]];
    }
}
