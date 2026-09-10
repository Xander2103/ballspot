<?php

/*
|--------------------------------------------------------------------------
| Friendly copy per AuthError code (App\Support\AuthError) — German
|--------------------------------------------------------------------------
| The CODE is the API contract and never changes per language; only this
| sentence does. The mobile app translates the code itself (client-side), so
| these strings are what older clients and the web pages show.
*/

return [
    'email_taken'        => 'Mit dieser E-Mail existiert bereits ein Konto. Bitte melde dich an oder setze dein Passwort zurück.',
    'username_taken'     => 'Dieser Benutzername ist bereits vergeben.',
    'password_mismatch'  => 'Die Passwörter stimmen nicht überein.',
    'validation_failed'  => 'Bitte prüfe die markierten Felder.',
    'invalid_credentials' => 'Ungültige E-Mail oder ungültiges Passwort.',
    'account_deleted'     => 'Dieses Konto wurde gelöscht. Du kannst mit derselben E-Mail ein neues Konto erstellen.',
    'two_factor_required'     => 'Wir haben einen Bestätigungscode an deine E-Mail gesendet.',
    'two_factor_code_invalid' => 'Dieser Code ist nicht korrekt. Prüfe die neueste E-Mail und versuche es erneut.',
    'two_factor_code_expired' => 'Dieser Code ist abgelaufen. Bitte melde dich erneut an, um einen neuen zu erhalten.',
    'two_factor_locked'       => 'Zu viele falsche Versuche. Tippe auf „Code erneut senden“, um einen neuen zu erhalten.',
    'two_factor_session_invalid' => 'Diese Login-Sitzung ist abgelaufen. Bitte melde dich erneut an.',
    'verification_code_invalid' => 'Dieser Code ist nicht korrekt. Prüfe die neueste E-Mail und versuche es erneut.',
    'verification_code_expired' => 'Dieser Code ist abgelaufen. Bitte fordere einen neuen an.',
    'verification_locked'       => 'Zu viele falsche Versuche. Bitte fordere einen neuen Code an.',
    'verification_no_code'      => 'Für dieses Konto ist kein Bestätigungscode aktiv. Tippe auf „Code erneut senden“, um einen neuen zu erhalten.',
    'reset_token_invalid' => 'Dieser Link zum Zurücksetzen des Passworts ist ungültig. Bitte fordere einen neuen an.',
    'reset_token_expired' => 'Dieser Link zum Zurücksetzen des Passworts ist abgelaufen. Bitte fordere einen neuen an.',
    'reset_failed'        => 'Wir konnten dein Passwort gerade nicht zurücksetzen. Bitte versuche es gleich noch einmal.',
    'admin_account_protected' => 'Admin-Konten können nicht über die App gelöscht werden.',
    'unknown'             => 'Etwas ist schiefgelaufen. Bitte versuche es erneut.',
];
