<?php

/*
|--------------------------------------------------------------------------
| API messages returned directly to the app (+ server push copy) — German
|--------------------------------------------------------------------------
| Machine-readable `code`/`reason` fields stay English and stable; only the
| human `message` is translated. Rendered under the requesting user's
| preferred_language (App\Http\Middleware\SetLocale).
*/

return [
    'rate_limited' => 'Zu viele Anfragen. Bitte versuche es in :seconds Sekunden erneut.',

    'auth' => [
        'logged_out'                   => 'Abgemeldet',
        'verify_email_new_code'        => 'Bitte bestätige deine E-Mail-Adresse, um fortzufahren. Wir haben dir einen neuen Code gesendet.',
        'verify_email_existing_code'   => 'Bitte bestätige deine E-Mail-Adresse, um fortzufahren. Gib den Code ein, den wir dir per E-Mail gesendet haben, oder fordere einen neuen an.',
        'email_verified'               => 'Deine E-Mail wurde bestätigt.',
        'email_already_verified'       => 'Deine E-Mail ist bereits bestätigt.',
        'verification_code_resent'     => 'Ein neuer Bestätigungscode wurde an deine E-Mail gesendet.',
        'verification_code_required'   => 'Gib den 6-stelligen Code aus deiner E-Mail ein.',
        'verification_session_mismatch' => 'Dieser Code gehört zu einem anderen Konto als dem, das auf diesem Gerät angemeldet ist. Bitte melde dich erneut mit dem Konto an, das du gerade erstellt hast.',
        'resend_cooldown'              => 'Bitte warte einen Moment, bevor du einen neuen Code anforderst.',
        'resend_failed'                => 'Wir konnten die E-Mail gerade nicht senden. Bitte versuche es gleich noch einmal.',
        'login_code_resent'            => 'Falls dein Login noch aussteht, wurde ein neuer Code an deine E-Mail gesendet.',
        'login_again'                  => 'Bitte melde dich erneut an.',
        'reset_link_sent'              => 'Falls für diese E-Mail ein Konto existiert, wurde ein Link zum Zurücksetzen des Passworts gesendet.',
        'password_reset_done'          => 'Dein Passwort wurde zurückgesetzt. Bitte melde dich an.',
        'reset_link_invalid'           => 'Dieser Link zum Zurücksetzen des Passworts ist ungültig oder abgelaufen.',
        'beta_code_required'           => 'Während der geschlossenen Testphase ist ein Beta-Code erforderlich.',
        'beta_code_invalid'            => 'Ungültiger Beta-Code.',
        'password_min'                 => 'Das Passwort muss mindestens 8 Zeichen lang sein.',
        'language_unsupported'         => 'Bitte wähle eine unterstützte Sprache.',
        'terms_required'               => 'Du musst die Nutzungsbedingungen und die Datenschutzerklärung akzeptieren.',
        'age_required'                 => 'Du musst bestätigen, dass du das Mindestalter erreicht hast.',
    ],

    'account' => [
        'deleted'        => 'Dein Konto wurde gelöscht.',
        'delete_failed'  => 'Wir konnten dein Konto gerade nicht löschen. Bitte versuche es gleich noch einmal oder kontaktiere den Support.',
    ],

    'preferences' => [
        'sport_unavailable'  => 'Diese Sportart ist noch nicht verfügbar.',
        'theme_unavailable'  => 'Dieses Design ist nicht verfügbar.',
        'two_factor_boolean' => 'Der Zwei-Faktor-Login muss an oder aus sein.',
    ],

    'daily' => [
        'not_active'      => 'Diese Tages-Challenge ist nicht aktiv.',
        'not_today'       => 'Diese Tages-Challenge ist heute nicht verfügbar.',
        'already_played'  => 'Du hast die heutige Challenge bereits gespielt.',
        'no_guess'        => 'Kein Tipp für diese Challenge gefunden.',
    ],

    'friends' => [
        'code_not_found'     => 'Kein Spieler mit diesem Freundescode gefunden.',
        'self'               => 'Du kannst dich nicht selbst als Freund hinzufügen.',
        'already_friends'    => 'Du bist bereits mit diesem Spieler befreundet.',
        'pending_exists'     => 'Es gibt bereits eine ausstehende Anfrage mit diesem Spieler.',
        'declined'           => 'Dieser Spieler hat deine Anfrage abgelehnt. Du kannst es später erneut versuchen.',
        'not_addressed'      => 'Diese Anfrage ist nicht an dich gerichtet.',
        'not_pending'        => 'Diese Anfrage ist nicht mehr ausstehend.',
        'rejected'           => 'Anfrage abgelehnt.',
        'not_friends'        => 'Du bist mit diesem Spieler nicht befreundet.',
    ],

    'tournaments' => [
        'not_member'         => 'Kein Mitglied dieser Liga',
        'hide_only_finished' => 'Nur abgeschlossene Turniere können aus deiner Liste entfernt werden.',
        'owner_only_start'   => 'Nur der Ersteller kann dieses Turnier starten.',
        'start_from_lobby'   => 'Ein Turnier kann nur aus dem Lobby-Status gestartet werden.',
        'no_challenges'      => 'Keine aktiven :sport-Challenges verfügbar. Füge im Admin-Bereich Challenges hinzu.',
        'owner_only_cancel'  => 'Nur der Ersteller kann dieses Turnier abbrechen.',
        'owner_only_remove'  => 'Nur der Ersteller kann Spieler entfernen.',
        'remove_in_lobby'    => 'Spieler können nur entfernt werden, solange das Turnier in der Lobby ist.',
        'owner_not_removable' => 'Der Ersteller kann nicht entfernt werden.',
        'daily_limit'        => 'Du hast alle für heute verfügbaren Runden gespielt.',
        'not_enough_challenges' => 'Nicht genügend ungenutzte Turnier-Challenges verfügbar. Füge zuerst weitere Turnierfotos hinzu.',
        'temporarily_unavailable' => 'Turniere sind vorübergehend nicht verfügbar, während wir neue Challenges vorbereiten. Bitte versuche es bald noch einmal.',
        'temporarily_unavailable_next_month' => 'Turniere sind vorübergehend nicht verfügbar, während wir neue Challenges vorbereiten. Bitte versuche es Anfang nächsten Monats noch einmal.',
    ],

    'packs' => [
        'already_completed' => 'Du hast dieses Paket bereits abgeschlossen.',
    ],

    'push' => [
        'daily_reminder_title' => 'Tages-Challenge',
        'daily_reminder_body'  => 'Deine heutige :app Tages-Challenge wartet noch auf dich ⚽',
    ],
];
