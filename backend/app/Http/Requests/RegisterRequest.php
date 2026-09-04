<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        // Deleted accounts are anonymized in place: email/username are rewritten
        // to deleted-{id} values inside the deletion transaction, so they never
        // collide with a fresh registration and the same identifiers can be
        // reused (AccountReRegistrationTest). The plain unique rule is kept on
        // purpose — the DB unique index would reject a lingering identifier
        // anyway, and a 422 here beats a 500 there.
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

    /**
     * Beta-gate failures are the #1 "I can't sign up" support question, so
     * log the category (missing vs wrong) — never the submitted or expected
     * code, never the email.
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $errors = $validator->errors();

        if ($errors->has('beta_code')) {
            \App\Support\AppLog::warn('auth.beta_code_rejected', [
                'reason' => $this->filled('beta_code') ? 'invalid_code' : 'missing_code',
            ]);
        }

        parent::failedValidation($validator);
    }

    public function messages(): array
    {
        return [
            'beta_code.required'      => 'A beta code is required during closed testing.',
            'email.unique'            => 'An account with this email already exists. Try logging in or resetting your password.',
            'username.unique'         => 'This username is already taken. Please choose another one.',
            'terms_accepted.accepted' => 'You must accept the Terms of Service and Privacy Policy.',
            'age_confirmed.accepted'  => 'You must confirm you meet the minimum age requirement.',
        ];
    }
}
