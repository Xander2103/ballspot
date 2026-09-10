<?php

/*
|--------------------------------------------------------------------------
| Validation — français
|--------------------------------------------------------------------------
| The rules the BallPicker API actually uses, plus the attribute names.
| Every other rule falls back to the framework's built-in English messages.
*/

return [
    'accepted'  => 'Le champ :attribute doit être accepté.',
    'boolean'   => 'Le champ :attribute doit être vrai ou faux.',
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',
    'digits'    => 'Le champ :attribute doit contenir :digits chiffres.',
    'email'     => 'Le champ :attribute doit être une adresse e-mail valide.',
    'exists'    => 'La valeur sélectionnée pour :attribute est invalide.',
    'image'     => 'Le champ :attribute doit être une image.',
    'in'        => 'La valeur sélectionnée pour :attribute est invalide.',
    'integer'   => 'Le champ :attribute doit être un nombre entier.',
    'max' => [
        'array'   => 'Le champ :attribute ne doit pas contenir plus de :max éléments.',
        'file'    => 'Le champ :attribute ne doit pas dépasser :max kilo-octets.',
        'numeric' => 'Le champ :attribute ne doit pas être supérieur à :max.',
        'string'  => 'Le champ :attribute ne doit pas dépasser :max caractères.',
    ],
    'mimes'     => 'Le champ :attribute doit être un fichier de type : :values.',
    'min' => [
        'array'   => 'Le champ :attribute doit contenir au moins :min éléments.',
        'file'    => 'Le champ :attribute doit faire au moins :min kilo-octets.',
        'numeric' => 'Le champ :attribute doit être au moins égal à :min.',
        'string'  => 'Le champ :attribute doit contenir au moins :min caractères.',
    ],
    'required'  => 'Le champ :attribute est obligatoire.',
    'string'    => 'Le champ :attribute doit être une chaîne de caractères.',
    'unique'    => 'La valeur du champ :attribute est déjà utilisée.',
    'uuid'      => 'Le champ :attribute doit être un UUID valide.',

    'attributes' => [
        'email'                 => 'e-mail',
        'password'              => 'mot de passe',
        'password_confirmation' => 'confirmation du mot de passe',
        'username'              => 'nom d\'utilisateur',
        'name'                  => 'nom',
        'code'                  => 'code',
        'token'                 => 'jeton',
        'beta_code'             => 'code bêta',
        'preferred_language'    => 'langue',
        'preferred_sport_id'    => 'sport',
        'selected_theme'        => 'thème',
        'two_factor_enabled'    => 'connexion à deux facteurs',
        'terms_accepted'        => 'conditions',
        'age_confirmed'         => 'confirmation de l\'âge',
        'verification_id'       => 'session de vérification',
        'friend_code'           => 'code ami',
        'avatar'                => 'photo',
    ],
];
