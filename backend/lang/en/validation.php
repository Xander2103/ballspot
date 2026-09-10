<?php

/*
|--------------------------------------------------------------------------
| Validation overrides (English)
|--------------------------------------------------------------------------
| Only what BallPicker customises. Every other rule falls back to the
| framework's built-in English messages. The other languages ship the
| common rules the API actually uses (required, email, min, max, confirmed,
| accepted, unique, in, string, boolean, digits, uuid, exists, integer,
| image, mimes) plus the same attribute names.
*/

return [
    'attributes' => [
        'email'                 => 'email',
        'password'              => 'password',
        'password_confirmation' => 'password confirmation',
        'username'              => 'username',
        'name'                  => 'name',
        'code'                  => 'code',
        'token'                 => 'token',
        'beta_code'             => 'beta code',
        'preferred_language'    => 'language',
        'preferred_sport_id'    => 'sport',
        'selected_theme'        => 'theme',
        'two_factor_enabled'    => 'two-factor login',
        'terms_accepted'        => 'terms',
        'age_confirmed'         => 'age confirmation',
        'verification_id'       => 'verification session',
        'friend_code'           => 'friend code',
        'avatar'                => 'photo',
    ],
];
