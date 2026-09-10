<?php

/*
|--------------------------------------------------------------------------
| Public web pages: password reset fallback (forgot / reset / result)
|--------------------------------------------------------------------------
| Rendered under the locale resolved by App\Http\Middleware\SetLocale
| (?lang= from the email link, then Accept-Language, then the default).
*/

return [
    'nav' => [
        'privacy' => 'Privacy',
        'terms'   => 'Terms',
        'support' => 'Support',
    ],
    'footer' => [
        'made_by' => 'BallPicker is created by Van Malder Studio.',
    ],
    'forgot' => [
        'title'          => 'Forgot password',
        'heading'        => 'Forgot your password?',
        'intro'          => "Enter the email for your BallPicker account and we'll send you a reset link.",
        'email'          => 'Email',
        'submit'         => 'Send reset link',
        'sent_title'     => 'Check your email',
        'sent_heading'   => 'Check your email',
        'sent_intro'     => "If an account exists for that address, we've sent a link to reset your password.",
        'sent_callout'   => "The link works for a limited time. If you don't see the email within a few minutes, check your spam folder or request another link.",
        'send_another'   => 'Send another link',
    ],
    'reset' => [
        'title'              => 'Reset password',
        'heading'            => 'Reset your password',
        'intro'              => 'Choose a new password for your BallPicker account.',
        'needs_link'         => 'This page needs the link from your password reset email. If the link no longer works, request a new one below.',
        'request_new'        => 'Request a new link',
        'email'              => 'Email',
        'password'           => 'New password (at least 8 characters)',
        'password_confirm'   => 'Confirm new password',
        'submit'             => 'Set new password',
        'open_in_app'        => 'Open in the BallPicker app instead',
        'open_in_app_hint'   => 'Only works on a phone with BallPicker installed. Otherwise just use the form above — after saving, open the app and log in with your new password.',
        'link_not_working'   => 'Link not working?',
    ],
    'result' => [
        'ok_title'        => 'Password updated',
        'ok_heading'      => 'Password updated',
        'ok_intro'        => 'Your BallPicker password has been changed and every other session has been signed out.',
        'ok_callout'      => 'Open the BallPicker app and log in with your new password.',
        'failed_title'    => 'Please try again',
        'failed_heading'  => 'Please try again',
        'failed_callout'  => 'Nothing was changed: your current password still works and this reset link is still valid.',
        'try_again'       => 'Try again',
        'expired_title'   => 'Reset link expired',
        'expired_heading' => 'This link no longer works',
        'expired_callout' => 'Reset links are valid for a limited time and can only be used once. Request a new one and use the newest email.',
        'request_new'     => 'Request a new link',
    ],
];
