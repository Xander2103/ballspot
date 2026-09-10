<?php

/*
|--------------------------------------------------------------------------
| Transactional emails (rendered under the recipient's preferred_language)
|--------------------------------------------------------------------------
| :app = BallPicker (config ballspot.app_name), :code = the one-time code,
| :minutes = expiry in minutes. Never put the code or a token in a log.
*/

return [
    'verify' => [
        'subject'  => 'Verify your :app email',
        'greeting' => 'Welcome to :app!',
        'code'     => 'Your email verification code is: :code',
        'expires'  => 'This code expires in :minutes minutes.',
        'ignore'   => 'If you did not create an account, you can ignore this email.',
    ],
    'login_code' => [
        'subject'  => 'Your :app login code',
        'greeting' => 'Verify your login',
        'code'     => 'Your login code is: :code',
        'expires'  => 'This code expires in :minutes minutes.',
        'ignore'   => 'If this was not you, you can ignore this email.',
    ],
    'reset' => [
        'subject'  => 'Reset your :app password',
        'greeting' => 'Reset your password',
        'intro'    => 'You are receiving this email because we received a password reset request for your :app account.',
        'action'   => 'Reset Password',
        'ignore'   => 'If you did not request a password reset, no further action is required. You can safely ignore this email.',
        'expires'  => 'This password reset link will expire soon.',
        'regards'  => 'Regards',
    ],
];
