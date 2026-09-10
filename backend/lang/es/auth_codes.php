<?php

/*
|--------------------------------------------------------------------------
| Friendly copy per AuthError code (App\Support\AuthError) — Spanish
|--------------------------------------------------------------------------
| The CODE is the API contract and never changes per language; only this
| sentence does. The mobile app translates the code itself (client-side), so
| these strings are what older clients and the web pages show.
*/

return [
    'email_taken'        => 'Ya existe una cuenta con este correo electrónico. Inicia sesión o restablece tu contraseña.',
    'username_taken'     => 'Este nombre de usuario ya está en uso.',
    'password_mismatch'  => 'Las contraseñas no coinciden.',
    'validation_failed'  => 'Revisa los campos marcados.',
    'invalid_credentials' => 'Correo electrónico o contraseña incorrectos.',
    'account_deleted'     => 'Esta cuenta ha sido eliminada. Puedes crear una cuenta nueva con el mismo correo.',
    'two_factor_required'     => 'Te hemos enviado un código de verificación a tu correo.',
    'two_factor_code_invalid' => 'Ese código no es correcto. Revisa el correo más reciente e inténtalo de nuevo.',
    'two_factor_code_expired' => 'Este código ha caducado. Inicia sesión de nuevo para recibir uno nuevo.',
    'two_factor_locked'       => 'Demasiados intentos incorrectos. Pulsa «Reenviar código» para recibir uno nuevo.',
    'two_factor_session_invalid' => 'Esta sesión de inicio ha caducado. Inicia sesión de nuevo.',
    'verification_code_invalid' => 'Ese código no es correcto. Revisa el correo más reciente e inténtalo de nuevo.',
    'verification_code_expired' => 'Este código ha caducado. Solicita uno nuevo.',
    'verification_locked'       => 'Demasiados intentos incorrectos. Solicita un código nuevo.',
    'verification_no_code'      => 'No hay ningún código de verificación activo para esta cuenta. Pulsa «Reenviar código» para recibir uno nuevo.',
    'reset_token_invalid' => 'Este enlace para restablecer la contraseña no es válido. Solicita uno nuevo.',
    'reset_token_expired' => 'Este enlace para restablecer la contraseña ha caducado. Solicita uno nuevo.',
    'reset_failed'        => 'No pudimos restablecer tu contraseña ahora mismo. Inténtalo de nuevo en un momento.',
    'admin_account_protected' => 'Las cuentas de administrador no se pueden eliminar desde la app.',
    'unknown'             => 'Algo salió mal. Inténtalo de nuevo.',
];
