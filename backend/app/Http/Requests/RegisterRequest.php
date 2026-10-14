<?php
namespace App\Http\Requests;

use App\Services\CaptchaVerifier;
use App\Support\AppLog;
use App\Support\AuthAbuseGuard;
use App\Support\AuthError;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    /**
     * Honeypot: a hidden field real clients always leave empty. The app sends
     * it as "" (never shown to users); a form-filling bot fills it. Named
     * like a harmless profile field on purpose.
     */
    public const HONEYPOT_FIELD = 'website';

    /** Outcome of the CAPTCHA check, when the feature flag is on (see codeFor). */
    private ?string $captchaOutcome = null;

    public function authorize(): bool { return true; }

    /**
     * Addresses are stored lowercase so "Foo@x.com" and "foo@x.com" can never
     * become two accounts on one mailbox (SQLite's UNIQUE index is
     * case-sensitive) and so login/reset lookups are stable.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => \App\Models\User::normalizeEmail($this->input('email'))]);
        }
    }

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
            // max:255 = the column width; a longer valid-RFC address would pass
            // validation and then fail the INSERT as a 500.
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            // `confirmed` = password_confirmation must be present and equal.
            // Until the store build that sends it is the minimum, the field is
            // only checked when present (config require_password_confirmation).
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            // Optional: defaults to config default_language in the controller.
            'preferred_language' => ['sometimes', 'nullable', 'string', Rule::in((array) config('ballspot.languages'))],
            // Consent must be provable server-side (GDPR Art. 7(1)); a
            // client-side checkbox demonstrates nothing.
            'terms_accepted' => ['required', 'accepted'],
            'age_confirmed'  => ['required', 'accepted'],
            // Anti-bot fields (v1.9.13). Both optional at the rule level: the
            // honeypot must stay empty, the CAPTCHA token is only required
            // when the feature flag is on — both checked in withValidator().
            self::HONEYPOT_FIELD => ['sometimes', 'nullable', 'string', 'max:255'],
            'captcha_token'      => ['sometimes', 'nullable', 'string', 'max:4096'],
        ];

        if (!config('ballspot.auth.require_password_confirmation', false) && !$this->has('password_confirmation')) {
            $rules['password'] = ['required', 'string', 'min:8', 'max:255'];
        }

        // Closed-beta gate: only enforced while a code is configured.
        if ($beta = config('ballspot.beta_code')) {
            $rules['beta_code'] = ['required', 'string', function ($attribute, $value, $fail) use ($beta) {
                if (!hash_equals(strtolower($beta), strtolower((string) $value))) {
                    $fail(__('messages.auth.beta_code_invalid'));
                }
            }];
        }

        return $rules;
    }

    /**
     * Post-rule checks that must never leak detail to a bot:
     *  - honeypot filled → generic "check the highlighted fields" + the IP is
     *    blocked from creating accounts for an hour (AuthAbuseGuard);
     *  - CAPTCHA (only when enabled) → `registration_captcha_required` when the
     *    token is missing, `registration_captcha_failed` when it does not verify.
     * The CAPTCHA round-trip is skipped while other fields are still invalid
     * (no point spending a verify call on a payload that fails anyway).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->filled(self::HONEYPOT_FIELD)) {
                AuthAbuseGuard::recordHoneypot($this->ip());
                $v->errors()->add(self::HONEYPOT_FIELD, AuthError::message(AuthError::VALIDATION_FAILED));

                return;
            }

            $captcha = app(CaptchaVerifier::class);
            if (!$captcha->enabled()) {
                return;
            }

            $token = $this->input('captcha_token');
            if (!is_string($token) || trim($token) === '') {
                $this->captchaOutcome = CaptchaVerifier::MISSING;
                $v->errors()->add('captcha_token', AuthError::message(AuthError::REGISTRATION_CAPTCHA_REQUIRED));

                return;
            }
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $outcome = $captcha->verify($token, $this->ip());
            if ($outcome !== CaptchaVerifier::OK) {
                $this->captchaOutcome = $outcome;
                $v->errors()->add('captcha_token', AuthError::message(AuthError::REGISTRATION_CAPTCHA_FAILED));
            }
        });
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
            $codes[$field] = $this->codeFor($field, array_keys($failed[$field] ?? []));
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
    private function codeFor(string $field, array $rules): string
    {
        return match (true) {
            $field === 'email'    && in_array('Unique', $rules, true)    => AuthError::EMAIL_TAKEN,
            $field === 'username' && in_array('Unique', $rules, true)    => AuthError::USERNAME_TAKEN,
            $field === 'password' && in_array('Confirmed', $rules, true) => AuthError::PASSWORD_MISMATCH,
            // Never a hint that a honeypot exists.
            $field === self::HONEYPOT_FIELD => AuthError::VALIDATION_FAILED,
            $field === 'captcha_token' => $this->captchaOutcome === CaptchaVerifier::MISSING
                ? AuthError::REGISTRATION_CAPTCHA_REQUIRED
                : AuthError::REGISTRATION_CAPTCHA_FAILED,
            default => $field . '_invalid',
        };
    }

    public function messages(): array
    {
        return [
            'beta_code.required'      => __('messages.auth.beta_code_required'),
            'email.unique'            => AuthError::message(AuthError::EMAIL_TAKEN),
            'username.unique'         => AuthError::message(AuthError::USERNAME_TAKEN),
            'password.confirmed'      => AuthError::message(AuthError::PASSWORD_MISMATCH),
            'password.min'            => __('messages.auth.password_min'),
            'preferred_language.in'   => __('messages.auth.language_unsupported'),
            'terms_accepted.accepted' => __('messages.auth.terms_required'),
            'age_confirmed.accepted'  => __('messages.auth.age_required'),
        ];
    }
}
