<?php

/*
|--------------------------------------------------------------------------
| Transactional emails (rendered under the recipient's preferred_language)
|--------------------------------------------------------------------------
| German. :app = BallPicker (config ballspot.app_name), :code = the one-time
| code, :minutes = expiry in minutes. Never put the code or a token in a log.
*/

return [
    'verify' => [
        'subject'  => 'Bestätige deine E-Mail für :app',
        'greeting' => 'Willkommen bei :app!',
        'code'     => 'Dein Bestätigungscode für deine E-Mail lautet: :code',
        'expires'  => 'Dieser Code läuft in :minutes Minuten ab.',
        'ignore'   => 'Wenn du kein Konto erstellt hast, kannst du diese E-Mail ignorieren.',
    ],
    'login_code' => [
        'subject'  => 'Dein :app Login-Code',
        'greeting' => 'Bestätige deinen Login',
        'code'     => 'Dein Login-Code lautet: :code',
        'expires'  => 'Dieser Code läuft in :minutes Minuten ab.',
        'ignore'   => 'Wenn das nicht du warst, kannst du diese E-Mail ignorieren.',
    ],
    'reset' => [
        'subject'  => 'Setze dein :app Passwort zurück',
        'greeting' => 'Setze dein Passwort zurück',
        'intro'    => 'Du erhältst diese E-Mail, weil wir eine Anfrage zum Zurücksetzen des Passworts für dein :app Konto erhalten haben.',
        'action'   => 'Passwort zurücksetzen',
        'ignore'   => 'Wenn du kein Zurücksetzen des Passworts angefordert hast, musst du nichts weiter tun. Du kannst diese E-Mail einfach ignorieren.',
        'expires'  => 'Dieser Link zum Zurücksetzen des Passworts läuft bald ab.',
        'regards'  => 'Viele Grüße',
    ],
];
