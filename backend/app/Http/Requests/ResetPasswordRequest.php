<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => \App\Models\User::normalizeEmail($this->input('email'))]);
        }
    }

    public function rules(): array
    {
        return [
            'token'    => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255'],
            // Kept consistent with registration (min:8) plus confirmation.
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ];
    }
}
