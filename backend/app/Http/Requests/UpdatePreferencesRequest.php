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
            // Must reference an existing, playable (status = active) sport. Null
            // explicitly clears the preference (falls back to football-first).
            'preferred_sport_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('sports', 'id')->where('status', \App\Models\Sport::STATUS_ACTIVE),
            ],
            // Must be one of the extensible allow-listed themes.
            'selected_theme' => [
                'sometimes',
                'string',
                Rule::in(config('ballspot.themes')),
            ],
            // nl | en | fr | de | es — see config ballspot.languages.
            'preferred_language' => [
                'sometimes',
                'string',
                Rule::in((array) config('ballspot.languages')),
            ],
            // Optional email login code (2FA). Default false for every account.
            'two_factor_enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'preferred_sport_id.exists' => 'This sport is not available yet.',
            'selected_theme.in'         => 'That theme is not available.',
            'preferred_language.in'     => 'Please choose a supported language.',
            'two_factor_enabled.boolean' => 'Two-factor login must be on or off.',
        ];
    }
}
