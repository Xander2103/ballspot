<?php

/*
|--------------------------------------------------------------------------
| Public web pages: password reset fallback (forgot / reset / result) — Spanish
|--------------------------------------------------------------------------
| Rendered under the locale resolved by App\Http\Middleware\SetLocale
| (?lang= from the email link, then Accept-Language, then the default).
*/

return [
    'nav' => [
        'privacy' => 'Privacidad',
        'terms'   => 'Términos',
        'support' => 'Soporte',
    ],
    'footer' => [
        'made_by' => 'BallPicker es una creación de Van Malder Studio.',
    ],
    'forgot' => [
        'title'          => 'Contraseña olvidada',
        'heading'        => '¿Olvidaste tu contraseña?',
        'intro'          => 'Introduce el correo de tu cuenta de BallPicker y te enviaremos un enlace para restablecerla.',
        'email'          => 'Correo electrónico',
        'submit'         => 'Enviar enlace',
        'sent_title'     => 'Revisa tu correo',
        'sent_heading'   => 'Revisa tu correo',
        'sent_intro'     => 'Si existe una cuenta con esa dirección, te hemos enviado un enlace para restablecer tu contraseña.',
        'sent_callout'   => 'El enlace funciona durante un tiempo limitado. Si no ves el correo en unos minutos, revisa tu carpeta de spam o solicita otro enlace.',
        'send_another'   => 'Enviar otro enlace',
    ],
    'reset' => [
        'title'              => 'Restablecer contraseña',
        'heading'            => 'Restablece tu contraseña',
        'intro'              => 'Elige una contraseña nueva para tu cuenta de BallPicker.',
        'needs_link'         => 'Esta página necesita el enlace del correo para restablecer tu contraseña. Si el enlace ya no funciona, solicita uno nuevo abajo.',
        'request_new'        => 'Solicitar un enlace nuevo',
        'email'              => 'Correo electrónico',
        'password'           => 'Nueva contraseña (mínimo 8 caracteres)',
        'password_confirm'   => 'Confirmar nueva contraseña',
        'submit'             => 'Guardar contraseña',
        'open_in_app'        => 'Abrir en la app de BallPicker',
        'open_in_app_hint'   => 'Solo funciona en un teléfono con BallPicker instalado. Si no, usa el formulario de arriba: después de guardar, abre la app e inicia sesión con tu nueva contraseña.',
        'link_not_working'   => '¿El enlace no funciona?',
    ],
    'result' => [
        'ok_title'        => 'Contraseña actualizada',
        'ok_heading'      => 'Contraseña actualizada',
        'ok_intro'        => 'Tu contraseña de BallPicker se ha cambiado y se ha cerrado la sesión en todos los demás dispositivos.',
        'ok_callout'      => 'Abre la app de BallPicker e inicia sesión con tu nueva contraseña.',
        'failed_title'    => 'Inténtalo de nuevo',
        'failed_heading'  => 'Inténtalo de nuevo',
        'failed_callout'  => 'No se ha cambiado nada: tu contraseña actual sigue funcionando y este enlace sigue siendo válido.',
        'try_again'       => 'Reintentar',
        'expired_title'   => 'Enlace caducado',
        'expired_heading' => 'Este enlace ya no funciona',
        'expired_callout' => 'Los enlaces de restablecimiento valen durante un tiempo limitado y solo se pueden usar una vez. Solicita uno nuevo y usa el correo más reciente.',
        'request_new'     => 'Solicitar un enlace nuevo',
    ],
];
