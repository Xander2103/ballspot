<?php

namespace App\Support;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

/**
 * One response shape for every known account/auth failure the app must
 * explain to the user:
 *
 *   { "message": "<friendly sentence>", "code": "<stable snake_case code>",
 *     "errors": { "<field>": ["<friendly sentence>"] }   // when field-bound
 *     ...extra }
 *
 * `message` is safe to show verbatim. `code` is what the mobile app maps to
 * its own copy (src/utils/authErrors.ts) — add a code here, add it there.
 * `errors` keeps the Laravel validation shape so older clients that only read
 * `errors.<field>[0]` still get the friendly text. Never put raw exception
 * text, tokens, codes or emails in here.
 */
final class AuthError
{
    // Registration
    public const EMAIL_TAKEN        = 'email_taken';
    public const USERNAME_TAKEN     = 'username_taken';
    public const PASSWORD_MISMATCH  = 'password_mismatch';
    public const VALIDATION_FAILED  = 'validation_failed';
    // Login
    public const INVALID_CREDENTIALS = 'invalid_credentials';
    public const ACCOUNT_DELETED     = 'account_deleted';
    // Login 2FA (email code)
    public const TWO_FACTOR_REQUIRED     = 'two_factor_required';
    public const TWO_FACTOR_CODE_INVALID = 'two_factor_code_invalid';
    public const TWO_FACTOR_CODE_EXPIRED = 'two_factor_code_expired';
    public const TWO_FACTOR_LOCKED       = 'two_factor_locked';
    public const TWO_FACTOR_SESSION_INVALID = 'two_factor_session_invalid';
    // Email verification (registration)
    public const VERIFICATION_CODE_INVALID = 'verification_code_invalid';
    public const VERIFICATION_CODE_EXPIRED = 'verification_code_expired';
    public const VERIFICATION_LOCKED       = 'verification_locked';
    public const VERIFICATION_NO_CODE      = 'verification_no_code';
    // Password reset
    public const RESET_TOKEN_INVALID = 'reset_token_invalid';
    public const RESET_TOKEN_EXPIRED = 'reset_token_expired';
    public const RESET_FAILED        = 'reset_failed';

    /** Friendly copy per code — the single source of truth for API messages. */
    public const MESSAGES = [
        self::EMAIL_TAKEN        => 'An account with this email already exists. Please log in or reset your password.',
        self::USERNAME_TAKEN     => 'This username is already taken.',
        self::PASSWORD_MISMATCH  => 'Passwords do not match.',
        self::VALIDATION_FAILED  => 'Please check the highlighted fields.',
        self::INVALID_CREDENTIALS => 'Invalid email or password.',
        self::ACCOUNT_DELETED     => 'This account has been deleted. You can create a new account with the same email.',
        self::TWO_FACTOR_REQUIRED     => 'We sent a verification code to your email.',
        self::TWO_FACTOR_CODE_INVALID => 'That code is not correct. Check the newest email and try again.',
        self::TWO_FACTOR_CODE_EXPIRED => 'This code has expired. Please log in again to get a new one.',
        self::TWO_FACTOR_LOCKED       => 'Too many incorrect attempts. Tap "Resend code" to get a new one.',
        self::TWO_FACTOR_SESSION_INVALID => 'This login session has expired. Please log in again.',
        self::VERIFICATION_CODE_INVALID => 'That code is not correct. Check the newest email and try again.',
        self::VERIFICATION_CODE_EXPIRED => 'This code has expired. Please request a new one.',
        self::VERIFICATION_LOCKED       => 'Too many incorrect attempts. Please request a new code.',
        self::VERIFICATION_NO_CODE      => 'No verification code is active for this account. Tap "Resend code" to get a new one.',
        self::RESET_TOKEN_INVALID => 'This password reset link is invalid. Please request a new one.',
        self::RESET_TOKEN_EXPIRED => 'This password reset link has expired. Please request a new one.',
        self::RESET_FAILED        => 'We could not reset your password right now. Please try again in a moment.',
    ];

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? 'Something went wrong. Please try again.';
    }

    /**
     * Build the response. `$field` binds the message to a form field (the
     * Laravel `errors` shape); `$extra` adds machine-readable context such as
     * a `reason`.
     */
    public static function response(string $code, int $status = 422, ?string $field = null, array $extra = [], ?string $message = null): JsonResponse
    {
        $message ??= self::message($code);

        $body = ['message' => $message, 'code' => $code];
        if ($field !== null) {
            $body['errors'] = [$field => [$message]];
            $body['codes']  = [$field => $code];
        }

        return response()->json($body + $extra, $status);
    }

    /** Abort the request with the error response. */
    public static function throw(string $code, int $status = 422, ?string $field = null, array $extra = [], ?string $message = null): never
    {
        throw new HttpResponseException(self::response($code, $status, $field, $extra, $message));
    }
}
