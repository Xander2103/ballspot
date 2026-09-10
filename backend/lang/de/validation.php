<?php

/*
|--------------------------------------------------------------------------
| Validation lines (German)
|--------------------------------------------------------------------------
| The common rules the API actually uses plus BallPicker's attribute names.
| Every other rule falls back to the framework's built-in English messages.
*/

return [
    'accepted'  => ':attribute muss akzeptiert werden.',
    'boolean'   => ':attribute muss wahr oder falsch sein.',
    'confirmed' => 'Die Bestätigung für :attribute stimmt nicht überein.',
    'digits'    => ':attribute muss :digits Ziffern haben.',
    'email'     => ':attribute muss eine gültige E-Mail-Adresse sein.',
    'exists'    => 'Der gewählte Wert für :attribute ist ungültig.',
    'image'     => ':attribute muss ein Bild sein.',
    'in'        => 'Der gewählte Wert für :attribute ist ungültig.',
    'integer'   => ':attribute muss eine ganze Zahl sein.',
    'max'       => [
        'array'   => ':attribute darf nicht mehr als :max Einträge haben.',
        'file'    => ':attribute darf nicht größer als :max Kilobyte sein.',
        'numeric' => ':attribute darf nicht größer als :max sein.',
        'string'  => ':attribute darf nicht länger als :max Zeichen sein.',
    ],
    'mimes'     => ':attribute muss eine Datei vom Typ :values sein.',
    'min'       => [
        'array'   => ':attribute muss mindestens :min Einträge haben.',
        'file'    => ':attribute muss mindestens :min Kilobyte groß sein.',
        'numeric' => ':attribute muss mindestens :min sein.',
        'string'  => ':attribute muss mindestens :min Zeichen lang sein.',
    ],
    'required'  => ':attribute ist erforderlich.',
    'string'    => ':attribute muss eine Zeichenkette sein.',
    'unique'    => ':attribute ist bereits vergeben.',
    'uuid'      => ':attribute muss eine gültige UUID sein.',

    'attributes' => [
        'email'                 => 'E-Mail',
        'password'              => 'Passwort',
        'password_confirmation' => 'Passwortbestätigung',
        'username'              => 'Benutzername',
        'name'                  => 'Name',
        'code'                  => 'Code',
        'token'                 => 'Token',
        'beta_code'             => 'Beta-Code',
        'preferred_language'    => 'Sprache',
        'preferred_sport_id'    => 'Sportart',
        'selected_theme'        => 'Design',
        'two_factor_enabled'    => 'Zwei-Faktor-Login',
        'terms_accepted'        => 'Nutzungsbedingungen',
        'age_confirmed'         => 'Altersbestätigung',
        'verification_id'       => 'Bestätigungssitzung',
        'friend_code'           => 'Freundescode',
        'avatar'                => 'Foto',
    ],
];
