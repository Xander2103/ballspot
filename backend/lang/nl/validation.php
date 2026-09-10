<?php

/*
|--------------------------------------------------------------------------
| Validatieberichten (Nederlands)
|--------------------------------------------------------------------------
| De regels die de BallPicker-API echt gebruikt, plus de attribuutnamen.
| Alle andere regels vallen terug op de Engelse teksten van het framework.
*/

return [
    'accepted'  => 'Je moet :attribute accepteren.',
    'boolean'   => 'Het veld :attribute moet waar of onwaar zijn.',
    'confirmed' => 'De bevestiging van :attribute komt niet overeen.',
    'digits'    => 'Het veld :attribute moet uit :digits cijfers bestaan.',
    'email'     => 'Het veld :attribute moet een geldig e-mailadres zijn.',
    'exists'    => 'De gekozen :attribute is ongeldig.',
    'image'     => 'Het veld :attribute moet een afbeelding zijn.',
    'in'        => 'De gekozen :attribute is ongeldig.',
    'integer'   => 'Het veld :attribute moet een geheel getal zijn.',
    'max' => [
        'array'   => 'Het veld :attribute mag niet meer dan :max items bevatten.',
        'file'    => 'Het veld :attribute mag niet groter zijn dan :max kilobytes.',
        'numeric' => 'Het veld :attribute mag niet groter zijn dan :max.',
        'string'  => 'Het veld :attribute mag niet langer zijn dan :max tekens.',
    ],
    'mimes'     => 'Het veld :attribute moet een bestand zijn van het type: :values.',
    'min' => [
        'array'   => 'Het veld :attribute moet minstens :min items bevatten.',
        'file'    => 'Het veld :attribute moet minstens :min kilobytes groot zijn.',
        'numeric' => 'Het veld :attribute moet minstens :min zijn.',
        'string'  => 'Het veld :attribute moet minstens :min tekens bevatten.',
    ],
    'required'  => 'Het veld :attribute is verplicht.',
    'string'    => 'Het veld :attribute moet tekst zijn.',
    'unique'    => ':attribute is al in gebruik.',
    'uuid'      => 'Het veld :attribute moet een geldige UUID zijn.',

    'attributes' => [
        'email'                 => 'e-mailadres',
        'password'              => 'wachtwoord',
        'password_confirmation' => 'wachtwoordbevestiging',
        'username'              => 'gebruikersnaam',
        'name'                  => 'naam',
        'code'                  => 'code',
        'token'                 => 'token',
        'beta_code'             => 'betacode',
        'preferred_language'    => 'taal',
        'preferred_sport_id'    => 'sport',
        'selected_theme'        => 'thema',
        'two_factor_enabled'    => 'tweestapsverificatie',
        'terms_accepted'        => 'voorwaarden',
        'age_confirmed'         => 'leeftijdsbevestiging',
        'verification_id'       => 'verificatiesessie',
        'friend_code'           => 'vriendencode',
        'avatar'                => 'foto',
    ],
];
