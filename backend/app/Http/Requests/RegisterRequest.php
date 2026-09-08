<?php
namespace App\Http\Requests;

use App\Support\AppLog;
use App\Support\AuthError;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

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
            // `confirmed` = password_confirmation must be present and equal.
            // Until the store build that sends it is the minimum, the field is
            // only checked when present (config require_password_confirmation).
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            // Optional: defaults to config default_language in the controller.
            'preferred_language' => ['sometimes', 'nullable', 'string', Rule::in((array) config('ballspot.languages'))],
            // Consent must be provable server-side (GDPR Art. 7(1)); a
            // client-side checkbox demonstrates nothing.
            'terms_accepted' => ['required', 'accepted'],
            'age_confirmed'  => ['required', 'accepted'],
        ];

        if (!config('ballspot.auth.require_password_confirmation', false) && !$this->has('password_confirmation')) {
            $rules['password'] = ['required', 'string', 'min:8'];
        }

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
     * Every registration failure answers in the shared AuthError shape:
     * `{ message, code, errors: {field: [msg]}, codes: {field: code} }`.
     * The log carries FIELD NAMES only (`register.validation_failed`) — never
     * the submitted values, the email, the password or a beta code.
     */
    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors();
        $failed = $validator->failed();

        $codes = [];
        foreach (array_keys($errors->toArray()) as $field) {
            $codes[$field] = self::codeFor($field, array_keys($failed[$field] ?? []));
        }

        AppLog::warn('register.validation_failed', [
            'fields' => array_keys($errors->toArray()),
            'codes'  => array_values($codes),
        ]);

        if ($errors->has('beta_code')) {
            // Kept: the #1 "I can't sign up" support question during a closed beta.
            AppLog::warn('auth.beta_code_rejected', [
                'reason' => $this->filled('beta_code') ? 'invalid_code' : 'missing_code',
            ]);
        }

        // The primary code is the first field's — the app highlights that field.
        $first = array_key_first($codes);

        throw new HttpResponseException(response()->json([
            'message' => $first ? $errors->first($first) : AuthError::message(AuthError::VALIDATION_FAILED),
            'code'    => $first ? $codes[$first] : AuthError::VALIDATION_FAILED,
            'errors'  => $errors->toArray(),
            'codes'   => $codes,
        ], 422));
    }

    /** Stable machine-readable code per (field, failed rules). */
    private static function codeFor(string $field, array $rules): string
    {
        return match (true) {
            $field === 'email'    && in_array('Unique', $rules, true)    => AuthError::EMAIL_TAKEN,
            $field === 'username' && in_array('Unique', $rules, true)    => AuthError::USERNAME_TAKEN,
            $field === 'password' && in_array('Confirmed', $rules, true) => AuthError::PASSWORD_MISMATCH,
            default => $field . '_invalid',
        };
    }

    public function messages(): array
    {
        return [
            'beta_code.required'      => 'A beta code is required during closed testing.',
            'email.unique'            => AuthError::message(AuthError::EMAIL_TAKEN),
            'username.unique'         => AuthError::message(AuthError::USERNAME_TAKEN),
            'password.confirmed'      => AuthError::message(AuthError::PASSWORD_MISMATCH),
            'password.min'            => 'Password must be at least 8 characters.',
            'preferred_language.in'   => 'Please choose a supported language.',
            'terms_accepted.accepted' => 'You must accept the Terms of Service and Privacy Policy.',
            'age_confirmed.accepted'  => 'You must confirm you meet the minimum age requirement.',
        ];
    }
}
