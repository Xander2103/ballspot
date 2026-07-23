<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterPushTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'token'       => ['required', 'string', 'max:255'],
            'platform'    => ['sometimes', 'nullable', 'string', 'in:ios,android,web'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
