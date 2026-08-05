<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterPushTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Expo push tokens look like ExponentPushToken[xxxx] (the SDK also emits
     * the ExpoPushToken[...] spelling). Anchoring the format stops arbitrary
     * 255-char junk from being stored and later fanned out to Expo forever.
     */
    public const TOKEN_PATTERN = '/^Expo(nent)?PushToken\[[A-Za-z0-9._-]{1,128}\]$/';

    public function rules(): array
    {
        return [
            'token'       => ['required', 'string', 'max:255', 'regex:' . self::TOKEN_PATTERN],
            'platform'    => ['sometimes', 'nullable', 'string', 'in:ios,android,web'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'token.regex' => 'That is not a valid Expo push token.',
        ];
    }
}
