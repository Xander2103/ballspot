<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'token'    => ['required', 'string'],
            'email'    => ['required', 'email'],
            // Kept consistent with registration (min:8) plus confirmation.
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
