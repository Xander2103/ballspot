<?php

namespace App\Rules;

use App\Models\User;
use App\Support\AuthError;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A username counts as taken in any letter case (see RegisterRequest::codeFor for the error code). */
class UsernameAvailable implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && User::usernameIsTaken($value)) {
            $fail(AuthError::message(AuthError::USERNAME_TAKEN));
        }
    }
}
