<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // Must reference an existing, ACTIVE sport. Null explicitly clears
            // the preference (falls back to football-first flow).
            'preferred_sport_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('sports', 'id')->where('is_active', true),
            ],
            // Must be one of the extensible allow-listed themes.
            'selected_theme' => [
                'sometimes',
                'string',
                Rule::in(config('ballspot.themes')),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'preferred_sport_id.exists' => 'That sport is not available yet. Pick an active sport.',
            'selected_theme.in'         => 'That theme is not available.',
        ];
    }
}
