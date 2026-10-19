<?php

/*
|--------------------------------------------------------------------------
| BallPicker Clubs — Text pro Fehlercode + Bezeichnungen der Audit-Aktionen
|--------------------------------------------------------------------------
| Der CODE ist der API-Vertrag und ändert sich nie je nach Sprache.
*/

return [
    'errors' => [
        'unknown'                         => 'Etwas ist schiefgelaufen. Bitte versuche es erneut.',
        'feature_disabled'                => 'Vereine sind noch nicht verfügbar.',
        'club_not_found'                  => 'Dieser Verein wurde nicht gefunden.',
        'club_forbidden'                  => 'Du hast keinen Zugriff auf diesen Bereich des Vereins.',
        'club_code_invalid'               => 'Dieser Einladungscode ist nicht gültig. Prüfe den Code und versuche es erneut.',
        'club_join_locked'                => 'Zu viele falsche Codes. Bitte warte eine Weile und versuche es dann erneut.',
        'club_full'                       => 'Dieser Verein hat die maximale Mitgliederzahl erreicht.',
        'club_membership_limit'           => 'Du bist bereits in der maximalen Anzahl von Vereinen.',
        'club_unavailable'                => 'Dieser Verein ist vorübergehend nicht verfügbar.',
        'club_owner_must_transfer'        => 'Du bist der einzige Eigentümer dieses Vereins. Übertrage die Eigentümerschaft, bevor du austrittst.',
        'club_last_owner'                 => 'Ein Verein braucht mindestens einen Eigentümer. Ernenne zuerst einen anderen Eigentümer.',
        'club_has_history'                => 'Dieser Verein hat Aktivität und kann nicht gelöscht werden. Archiviere ihn stattdessen.',
        'club_code_limit'                 => 'Dieser Verein hat bereits die maximale Anzahl aktiver Einladungscodes.',
        'club_status_invalid'             => 'Diese Statusänderung ist für diesen Verein nicht möglich.',
        'club_member_not_found'           => 'Dieses Mitglied wurde im Verein nicht gefunden.',
        'club_member_banned'              => 'Dieser Nutzer ist aus dem Verein verbannt. Hebe die Sperre zuerst auf.',
        'stale_version'                   => 'Jemand anderes hat dies inzwischen geändert. Lade die Seite neu und versuche es erneut.',
        'pack_reserved_for_competition'   => 'Dieses Pack ist gerade für einen Wettbewerb reserviert. Spiele es im Wettbewerb.',
        'pack_in_competition'             => 'Dieses Pack gehört zu einem Wettbewerb, der noch nicht abgeschlossen ist. Es muss aktiv bleiben, bis der Wettbewerb abgeschlossen oder abgesagt ist.',
        'competition_not_started'         => 'Dieser Wettbewerb hat noch nicht begonnen.',
        'competition_ended'               => 'Dieser Wettbewerb ist beendet.',
        'competition_attempt_limit'       => 'Du hast alle Versuche für dieses Pack verbraucht.',
        'competition_pack_already_played' => 'Du hast dieses Pack schon gespielt, daher kann es in diesem Wettbewerb nicht zählen.',
        'competition_attempt_in_progress' => 'Beende zuerst deinen aktuellen Versuch.',
        'competition_rules_locked'        => 'Der Wettbewerb hat begonnen, seine Regeln können nicht mehr geändert werden.',
        'competition_invalid_pack'        => 'Dieses Pack kann in diesem Wettbewerb nicht verwendet werden.',
        'competition_not_editable'        => 'Dieser Wettbewerb kann nicht mehr geändert werden.',
        'unexpected_challenge'            => 'Das ist nicht die aktuelle Challenge dieses Versuchs.',
        'attempt_not_active'              => 'Dieser Versuch ist nicht mehr aktiv.',
        'photo_invalid'                   => 'Dieses Foto konnte nicht verarbeitet werden. Verwende ein JPG-, PNG- oder WebP-Bild.',
        'photo_duplicate'                 => 'Dieses Foto wurde für diesen Verein bereits eingereicht.',
        'photo_status_invalid'            => 'Dieses Foto kann vom aktuellen Status aus nicht in diesen Schritt wechseln.',
        'photo_rights_required'           => 'Bestätige, dass der Verein die Rechte an diesem Foto hat und die abgebildeten Personen zugestimmt haben.',
        'photo_pending_limit'             => 'Für diesen Verein warten bereits zu viele Fotos auf die Prüfung. Bitte warte, bis einige bearbeitet sind.',
        'photo_pack_invalid'              => 'Diese Challenge kann diesem Pack nicht hinzugefügt werden.',
        'portal_two_factor_required'      => 'Gib den Bestätigungscode ein, den wir dir per E-Mail geschickt haben, um fortzufahren.',
    ],

    'audit' => [
        'club' => [
            'created'          => 'Verein erstellt',
            'updated'          => 'Vereinsdaten aktualisiert',
            'suspended'        => 'Verein gesperrt',
            'archived'         => 'Verein archiviert',
            'reactivated'      => 'Verein reaktiviert',
            'deleted'          => 'Verein gelöscht',
            'settings_changed' => 'Vereinseinstellungen geändert',
        ],
        'member' => [
            'added'                 => 'Mitglied hinzugefügt',
            'joined'                => 'Mitglied beigetreten',
            'left'                  => 'Mitglied ausgetreten',
            'removed'               => 'Mitglied entfernt',
            'banned'                => 'Mitglied verbannt',
            'unbanned'              => 'Sperre aufgehoben',
            'role_changed'          => 'Rolle des Mitglieds geändert',
            'ownership_transferred' => 'Eigentümerschaft übertragen',
        ],
        'code' => [
            'created'     => 'Einladungscode erstellt',
            'revoked'     => 'Einladungscode widerrufen',
            'regenerated' => 'Einladungscode neu erstellt',
        ],
        'pack' => [
            'created'  => 'Pack erstellt',
            'updated'  => 'Pack aktualisiert',
            'archived' => 'Pack archiviert',
            'linked'   => 'Pack mit Wettbewerb verknüpft',
            'unlinked' => 'Pack vom Wettbewerb gelöst',
        ],
        'competition' => [
            'created'          => 'Wettbewerb erstellt',
            'updated'          => 'Wettbewerb aktualisiert',
            'published'        => 'Wettbewerb veröffentlicht',
            'cancelled'        => 'Wettbewerb abgesagt',
            'rules_locked'     => 'Wettbewerbsregeln gesperrt',
            'ends_at_extended' => 'Wettbewerbsende verlängert',
            'voided'           => 'Wettbewerb annulliert',
            'finalized'        => 'Wettbewerb abgeschlossen',
            'rebuilt'          => 'Rangliste neu berechnet',
        ],
        'attempt' => [
            'voided' => 'Versuch annulliert',
        ],
        'photo' => [
            'uploaded'            => 'Foto hochgeladen',
            'assigned'            => 'Foto zugewiesen',
            'rejected'            => 'Foto abgelehnt',
            'converted'           => 'Foto in eine Challenge umgewandelt',
            'in_review'           => 'Fotoprüfung begonnen',
            'preparation_started' => 'Fotovorbereitung begonnen',
            'withdrawn'           => 'Foto zurückgezogen',
        ],
        'challenge' => [
            'created' => 'Vereins-Challenge erstellt',
            'updated' => 'Vereins-Challenge aktualisiert',
        ],
    ],

    // Specific sentences for ClubDomainException messages and admin helper
    // texts (not error codes — see `errors` for those).
    'competition_messages' => [
        'pack_in_overlapping_competition' => 'Das Paket ":pack" ist bereits im veröffentlichten Wettbewerb ":competition", dessen Zeitraum sich überschneidet. Wähle andere Daten oder ein anderes Paket.',
        'normal_play_allowed_helper'      => 'Mit "Normales Spielen erlaubt" können Mitglieder das Paket nicht normal spielen, solange ein Wettbewerbsversuch darauf läuft, und keinen Wettbewerbsversuch starten, solange ein normaler Versuch läuft. Das Paket zuerst normal fertig zu spielen und danach teilzunehmen bleibt bei Ein Versuch und Mehrere Versuche möglich; wähle Keine Wiederholung oder "Normales Spielen gesperrt", um das zu verhindern.',
    ],
];
