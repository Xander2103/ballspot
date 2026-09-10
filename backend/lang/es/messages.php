<?php

/*
|--------------------------------------------------------------------------
| API messages returned directly to the app (+ server push copy) — Spanish
|--------------------------------------------------------------------------
| Machine-readable `code`/`reason` fields stay English and stable; only the
| human `message` is translated. Rendered under the requesting user's
| preferred_language (App\Http\Middleware\SetLocale).
*/

return [
    'rate_limited' => 'Demasiadas solicitudes. Inténtalo de nuevo en :seconds segundos.',

    'auth' => [
        'logged_out'                   => 'Sesión cerrada',
        'verify_email_new_code'        => 'Verifica tu correo electrónico para continuar. Te hemos enviado un código nuevo.',
        'verify_email_existing_code'   => 'Verifica tu correo electrónico para continuar. Introduce el código que te enviamos o solicita uno nuevo.',
        'email_verified'               => 'Tu correo electrónico ha sido verificado.',
        'email_already_verified'       => 'Tu correo electrónico ya está verificado.',
        'verification_code_resent'     => 'Hemos enviado un nuevo código de verificación a tu correo.',
        'verification_code_required'   => 'Introduce el código de 6 dígitos de tu correo.',
        'verification_session_mismatch' => 'Este código pertenece a una cuenta distinta a la que tiene la sesión iniciada en este dispositivo. Inicia sesión de nuevo con la cuenta que acabas de crear.',
        'resend_cooldown'              => 'Espera un momento antes de solicitar otro código.',
        'resend_failed'                => 'No pudimos enviar el correo ahora mismo. Inténtalo de nuevo en un momento.',
        'login_code_resent'            => 'Si tu inicio de sesión sigue pendiente, hemos enviado un nuevo código a tu correo.',
        'login_again'                  => 'Inicia sesión de nuevo.',
        'reset_link_sent'              => 'Si existe una cuenta con ese correo, hemos enviado un enlace para restablecer la contraseña.',
        'password_reset_done'          => 'Tu contraseña se ha restablecido. Inicia sesión.',
        'reset_link_invalid'           => 'Este enlace para restablecer la contraseña no es válido o ha caducado.',
        'beta_code_required'           => 'Durante la beta cerrada se necesita un código beta.',
        'beta_code_invalid'            => 'Código beta no válido.',
        'password_min'                 => 'La contraseña debe tener al menos 8 caracteres.',
        'language_unsupported'         => 'Elige un idioma compatible.',
        'terms_required'               => 'Debes aceptar los Términos del servicio y la Política de privacidad.',
        'age_required'                 => 'Debes confirmar que cumples la edad mínima.',
    ],

    'account' => [
        'deleted'        => 'Tu cuenta ha sido eliminada.',
        'delete_failed'  => 'No pudimos eliminar tu cuenta ahora mismo. Inténtalo de nuevo en un momento o contacta con soporte.',
    ],

    'preferences' => [
        'sport_unavailable'  => 'Este deporte aún no está disponible.',
        'theme_unavailable'  => 'Ese tema no está disponible.',
        'two_factor_boolean' => 'El inicio de sesión en dos pasos debe estar activado o desactivado.',
    ],

    'daily' => [
        'not_active'      => 'Este reto diario no está activo.',
        'not_today'       => 'Este reto diario no está disponible hoy.',
        'already_played'  => 'Ya has jugado el reto de hoy.',
        'no_guess'        => 'No se encontró ningún intento para este reto.',
    ],

    'friends' => [
        'code_not_found'     => 'No se encontró ningún jugador con ese código de amigo.',
        'self'               => 'No puedes añadirte a ti mismo como amigo.',
        'already_friends'    => 'Ya eres amigo de este jugador.',
        'pending_exists'     => 'Ya hay una solicitud pendiente con este jugador.',
        'declined'           => 'Este jugador rechazó tu solicitud. Puedes intentarlo de nuevo más adelante.',
        'not_addressed'      => 'Esta solicitud no está dirigida a ti.',
        'not_pending'        => 'Esta solicitud ya no está pendiente.',
        'rejected'           => 'Solicitud rechazada.',
        'not_friends'        => 'No eres amigo de este jugador.',
    ],

    'tournaments' => [
        'not_member'         => 'No eres miembro de esta liga',
        'hide_only_finished' => 'Solo se pueden quitar de tu lista los torneos terminados.',
        'owner_only_start'   => 'Solo el organizador puede iniciar este torneo.',
        'start_from_lobby'   => 'El torneo solo se puede iniciar desde la sala.',
        'no_challenges'      => 'No hay retos de :sport activos disponibles. Añade retos en el panel de administración.',
        'owner_only_cancel'  => 'Solo el organizador puede cancelar este torneo.',
        'owner_only_remove'  => 'Solo el organizador puede expulsar jugadores.',
        'remove_in_lobby'    => 'Solo se pueden expulsar jugadores mientras el torneo está en la sala.',
        'owner_not_removable' => 'El organizador no puede ser expulsado.',
        'daily_limit'        => 'Ya has jugado todas las rondas disponibles hoy.',
        'not_enough_challenges' => 'No hay suficientes retos de torneo sin usar. Añade más fotos de torneo primero.',
        'temporarily_unavailable' => 'Los torneos no están disponibles temporalmente mientras preparamos nuevos retos. Inténtalo de nuevo pronto.',
        'temporarily_unavailable_next_month' => 'Los torneos no están disponibles temporalmente mientras preparamos nuevos retos. Inténtalo de nuevo a principios del próximo mes.',
    ],

    'packs' => [
        'already_completed' => 'Ya has completado este pack.',
    ],

    'push' => [
        'daily_reminder_title' => 'Reto diario',
        'daily_reminder_body'  => 'El reto diario de :app de hoy aún te espera ⚽',
    ],
];
