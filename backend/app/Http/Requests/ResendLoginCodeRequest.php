<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResendLoginCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'verification_id' => ['required', 'uuid'],
        ];
    }
}
