<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:50', 'unique:users,username'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            // Consent must be provable server-side (GDPR Art. 7(1)); a
            // client-side checkbox demonstrates nothing.
            'terms_accepted' => ['required', 'accepted'],
            'age_confirmed'  => ['required', 'accepted'],
        ];

        // Closed-beta gate: only enforced while a code is configured.
        if ($beta = config('ballspot.beta_code')) {
            $rules['beta_code'] = ['required', 'string', function ($attribute, $value, $fail) use ($beta) {
                if (!hash_equals(strtolower($beta), strtolower((string) $value))) {
                    $fail('Invalid beta code.');
                }
            }];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'beta_code.required'      => 'A beta code is required during closed testing.',
            'terms_accepted.accepted' => 'You must accept the Terms of Service and Privacy Policy.',
            'age_confirmed.accepted'  => 'You must confirm you meet the minimum age requirement.',
        ];
    }
}
