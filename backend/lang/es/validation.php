<?php

/*
|--------------------------------------------------------------------------
| Validation (Spanish)
|--------------------------------------------------------------------------
| The common rules the API actually uses plus BallPicker's attribute names.
| Every other rule falls back to the framework's built-in English messages.
*/

return [
    'accepted'  => 'Debes aceptar el campo :attribute.',
    'boolean'   => 'El campo :attribute debe ser verdadero o falso.',
    'confirmed' => 'La confirmación del campo :attribute no coincide.',
    'digits'    => 'El campo :attribute debe tener :digits dígitos.',
    'email'     => 'El campo :attribute debe ser un correo electrónico válido.',
    'exists'    => 'El valor seleccionado de :attribute no es válido.',
    'image'     => 'El campo :attribute debe ser una imagen.',
    'in'        => 'El valor seleccionado de :attribute no es válido.',
    'integer'   => 'El campo :attribute debe ser un número entero.',
    'max' => [
        'array'   => 'El campo :attribute no debe tener más de :max elementos.',
        'file'    => 'El campo :attribute no debe superar los :max kilobytes.',
        'numeric' => 'El campo :attribute no debe ser mayor que :max.',
        'string'  => 'El campo :attribute no debe tener más de :max caracteres.',
    ],
    'mimes'     => 'El campo :attribute debe ser un archivo de tipo: :values.',
    'min' => [
        'array'   => 'El campo :attribute debe tener al menos :min elementos.',
        'file'    => 'El campo :attribute debe tener al menos :min kilobytes.',
        'numeric' => 'El campo :attribute debe ser al menos :min.',
        'string'  => 'El campo :attribute debe tener al menos :min caracteres.',
    ],
    'required'  => 'El campo :attribute es obligatorio.',
    'string'    => 'El campo :attribute debe ser una cadena de texto.',
    'unique'    => 'El valor de :attribute ya está en uso.',
    'uuid'      => 'El campo :attribute debe ser un UUID válido.',

    'attributes' => [
        'email'                 => 'correo electrónico',
        'password'              => 'contraseña',
        'password_confirmation' => 'confirmación de contraseña',
        'username'              => 'nombre de usuario',
        'name'                  => 'nombre',
        'code'                  => 'código',
        'token'                 => 'token',
        'beta_code'             => 'código beta',
        'preferred_language'    => 'idioma',
        'preferred_sport_id'    => 'deporte',
        'selected_theme'        => 'tema',
        'two_factor_enabled'    => 'inicio de sesión en dos pasos',
        'terms_accepted'        => 'términos',
        'age_confirmed'         => 'confirmación de edad',
        'verification_id'       => 'sesión de verificación',
        'friend_code'           => 'código de amigo',
        'avatar'                => 'foto',
    ],
];
