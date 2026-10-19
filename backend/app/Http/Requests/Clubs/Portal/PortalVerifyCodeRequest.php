<?php

namespace App\Http\Requests\Clubs\Portal;

use Illuminate\Foundation\Http\FormRequest;

/** POST /club/verify — the emailed 6-digit code (spaces/dashes tolerated). */
class PortalVerifyCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $code = $this->input('code');
        $this->merge(['code' => is_string($code) ? (string) preg_replace('/[\s\-]+/', '', $code) : $code]);
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'regex:/^\d{6}$/']];
    }

    public function messages(): array
    {
        return ['code.regex' => __('club_portal.verify.code_format')];
    }
}
