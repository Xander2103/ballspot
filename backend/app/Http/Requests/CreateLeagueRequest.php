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
            'duration_days' => ['required', 'integer', 'in:1,3,7'],
            'rounds_per_day' => ['required', 'integer', 'in:1,3'],
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
        ];
    }
}
