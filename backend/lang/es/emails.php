<?php

/*
|--------------------------------------------------------------------------
| Transactional emails — Spanish
|--------------------------------------------------------------------------
| :app = BallPicker (config ballspot.app_name), :code = the one-time code,
| :minutes = expiry in minutes. Never put the code or a token in a log.
*/

return [
    'verify' => [
        'subject'  => 'Verifica tu correo de :app',
        'greeting' => '¡Bienvenido a :app!',
        'code'     => 'Tu código de verificación de correo es: :code',
        'expires'  => 'Este código caduca en :minutes minutos.',
        'ignore'   => 'Si no has creado ninguna cuenta, puedes ignorar este correo.',
    ],
    'login_code' => [
        'subject'  => 'Tu código de inicio de sesión de :app',
        'greeting' => 'Verifica tu inicio de sesión',
        'code'     => 'Tu código de inicio de sesión es: :code',
        'expires'  => 'Este código caduca en :minutes minutos.',
        'ignore'   => 'Si no has sido tú, puedes ignorar este correo.',
    ],
    'reset' => [
        'subject'  => 'Restablece tu contraseña de :app',
        'greeting' => 'Restablece tu contraseña',
        'intro'    => 'Recibes este correo porque hemos recibido una solicitud para restablecer la contraseña de tu cuenta de :app.',
        'action'   => 'Restablecer contraseña',
        'ignore'   => 'Si no has solicitado restablecer tu contraseña, no tienes que hacer nada más. Puedes ignorar este correo sin problema.',
        'expires'  => 'Este enlace para restablecer la contraseña caducará pronto.',
        'regards'  => 'Un saludo',
    ],
];
