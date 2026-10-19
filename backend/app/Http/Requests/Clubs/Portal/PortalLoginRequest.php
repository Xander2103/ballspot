<?php

namespace App\Http\Requests\Clubs\Portal;

use Illuminate\Foundation\Http\FormRequest;

/** POST /club/login — email + password (the generic refusal lives in the controller). */
class PortalLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'    => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }
}
