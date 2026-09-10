<?php

/*
|--------------------------------------------------------------------------
| Public web pages: password reset fallback (forgot / reset / result) — German
|--------------------------------------------------------------------------
| Rendered under the locale resolved by App\Http\Middleware\SetLocale
| (?lang= from the email link, then Accept-Language, then the default).
*/

return [
    'nav' => [
        'privacy' => 'Datenschutz',
        'terms'   => 'Nutzungsbedingungen',
        'support' => 'Support',
    ],
    'footer' => [
        'made_by' => 'BallPicker wird von Van Malder Studio entwickelt.',
    ],
    'forgot' => [
        'title'          => 'Passwort vergessen',
        'heading'        => 'Passwort vergessen?',
        'intro'          => 'Gib die E-Mail deines BallPicker-Kontos ein und wir senden dir einen Link zum Zurücksetzen.',
        'email'          => 'E-Mail',
        'submit'         => 'Link senden',
        'sent_title'     => 'Prüfe deine E-Mails',
        'sent_heading'   => 'Prüfe deine E-Mails',
        'sent_intro'     => 'Falls für diese Adresse ein Konto existiert, haben wir einen Link zum Zurücksetzen deines Passworts gesendet.',
        'sent_callout'   => 'Der Link ist nur begrenzt gültig. Wenn du die E-Mail nicht innerhalb weniger Minuten siehst, sieh in deinem Spam-Ordner nach oder fordere einen neuen Link an.',
        'send_another'   => 'Neuen Link senden',
    ],
    'reset' => [
        'title'              => 'Passwort zurücksetzen',
        'heading'            => 'Setze dein Passwort zurück',
        'intro'              => 'Wähle ein neues Passwort für dein BallPicker-Konto.',
        'needs_link'         => 'Diese Seite braucht den Link aus deiner E-Mail zum Zurücksetzen des Passworts. Wenn der Link nicht mehr funktioniert, fordere unten einen neuen an.',
        'request_new'        => 'Neuen Link anfordern',
        'email'              => 'E-Mail',
        'password'           => 'Neues Passwort (mindestens 8 Zeichen)',
        'password_confirm'   => 'Neues Passwort bestätigen',
        'submit'             => 'Neues Passwort speichern',
        'open_in_app'        => 'Stattdessen in der BallPicker-App öffnen',
        'open_in_app_hint'   => 'Funktioniert nur auf einem Handy mit installierter BallPicker-App. Ansonsten nutze einfach das Formular oben — öffne nach dem Speichern die App und melde dich mit deinem neuen Passwort an.',
        'link_not_working'   => 'Link funktioniert nicht?',
    ],
    'result' => [
        'ok_title'        => 'Passwort aktualisiert',
        'ok_heading'      => 'Passwort aktualisiert',
        'ok_intro'        => 'Dein BallPicker-Passwort wurde geändert und alle anderen Sitzungen wurden abgemeldet.',
        'ok_callout'      => 'Öffne die BallPicker-App und melde dich mit deinem neuen Passwort an.',
        'failed_title'    => 'Bitte versuche es erneut',
        'failed_heading'  => 'Bitte versuche es erneut',
        'failed_callout'  => 'Es wurde nichts geändert: Dein aktuelles Passwort funktioniert weiterhin und dieser Link ist noch gültig.',
        'try_again'       => 'Erneut versuchen',
        'expired_title'   => 'Link abgelaufen',
        'expired_heading' => 'Dieser Link funktioniert nicht mehr',
        'expired_callout' => 'Links zum Zurücksetzen sind nur begrenzt gültig und können nur einmal verwendet werden. Fordere einen neuen an und verwende die neueste E-Mail.',
        'request_new'     => 'Neuen Link anfordern',
    ],
];
