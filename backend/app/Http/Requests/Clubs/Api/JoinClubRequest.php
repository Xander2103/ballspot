<?php

namespace App\Http\Requests\Clubs\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/clubs/join {code}. Any case, with or without the dash/spaces
 * (ClubInviteCodeService::normalise). Format problems are NOT field errors:
 * every unusable code answers the same 422 club_code_invalid (D23) and
 * counts as a failed attempt.
 */
class JoinClubRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
        ];
    }
}
