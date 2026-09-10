<?php

/*
|--------------------------------------------------------------------------
| Friendly copy per AuthError code (App\Support\AuthError)
|--------------------------------------------------------------------------
| The CODE is the API contract and never changes per language; only this
| sentence does. The mobile app translates the code itself (client-side), so
| these strings are what older clients and the web pages show.
*/

return [
    'email_taken'        => 'An account with this email already exists. Please log in or reset your password.',
    'username_taken'     => 'This username is already taken.',
    'password_mismatch'  => 'Passwords do not match.',
    'validation_failed'  => 'Please check the highlighted fields.',
    'invalid_credentials' => 'Invalid email or password.',
    'account_deleted'     => 'This account has been deleted. You can create a new account with the same email.',
    'two_factor_required'     => 'We sent a verification code to your email.',
    'two_factor_code_invalid' => 'That code is not correct. Check the newest email and try again.',
    'two_factor_code_expired' => 'This code has expired. Please log in again to get a new one.',
    'two_factor_locked'       => 'Too many incorrect attempts. Tap "Resend code" to get a new one.',
    'two_factor_session_invalid' => 'This login session has expired. Please log in again.',
    'verification_code_invalid' => 'That code is not correct. Check the newest email and try again.',
    'verification_code_expired' => 'This code has expired. Please request a new one.',
    'verification_locked'       => 'Too many incorrect attempts. Please request a new code.',
    'verification_no_code'      => 'No verification code is active for this account. Tap "Resend code" to get a new one.',
    'reset_token_invalid' => 'This password reset link is invalid. Please request a new one.',
    'reset_token_expired' => 'This password reset link has expired. Please request a new one.',
    'reset_failed'        => 'We could not reset your password right now. Please try again in a moment.',
    'admin_account_protected' => 'Admin accounts cannot be deleted from the app.',
    'unknown'             => 'Something went wrong. Please try again.',
];
