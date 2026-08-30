<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class CreateLeagueRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // v1.9.0: fixed lengths only (7 / 14 / 1 month = 30). Anything else
            // (0, 1, 3, 365, negatives) is rejected. Old tournaments with other
            // durations are untouched — this only guards creation.
            'duration_days' => [
                'required',
                'integer',
                \Illuminate\Validation\Rule::in(config('ballspot.tournaments.allowed_duration_days', [7, 14, 30])),
            ],
            // v1.8.8: players get exactly one photo per day. The field is
            // accepted for old app builds but ignored server-side
            // (see LeagueService::create).
            'rounds_per_day' => ['sometimes', 'integer'],
            // Optional: which sport this tournament is for. Must be playable
            // (status = active). Omitted -> user's preferred sport, then football.
            'sport_id' => [
                'sometimes',
                'nullable',
                'integer',
                \Illuminate\Validation\Rule::exists('sports', 'id')->where('status', \App\Models\Sport::STATUS_ACTIVE),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'sport_id.exists' => 'This sport is not available yet.',
            'duration_days.in' => 'Tournament length must be 7 days, 14 days or 1 month.',
        ];
    }
}
