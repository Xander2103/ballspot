<?php

/*
|--------------------------------------------------------------------------
| BallPicker Clubs — tekst per foutcode + labels voor de audit-acties
|--------------------------------------------------------------------------
| De CODE is het API-contract en verandert nooit per taal; alleen de zin wel.
*/

return [
    'errors' => [
        'unknown'                         => 'Er ging iets mis. Probeer het opnieuw.',
        'feature_disabled'                => 'Clubs zijn nog niet beschikbaar.',
        'club_not_found'                  => 'Deze club is niet gevonden.',
        'club_forbidden'                  => 'Je hebt geen toegang tot dit deel van de club.',
        'club_code_invalid'               => 'Deze uitnodigingscode is niet geldig. Controleer de code en probeer het opnieuw.',
        'club_join_locked'                => 'Te veel foute codes. Wacht even en probeer het later opnieuw.',
        'club_full'                       => 'Deze club heeft het maximumaantal leden bereikt.',
        'club_membership_limit'           => 'Je bent al lid van het maximumaantal clubs.',
        'club_unavailable'                => 'Deze club is tijdelijk niet beschikbaar.',
        'club_owner_must_transfer'        => 'Je bent de enige eigenaar van deze club. Draag het eigenaarschap over voordat je vertrekt.',
        'club_last_owner'                 => 'Een club heeft minstens één eigenaar nodig. Duid eerst een andere eigenaar aan.',
        'club_has_history'                => 'Deze club heeft activiteit en kan niet verwijderd worden. Archiveer de club in plaats daarvan.',
        'club_code_limit'                 => 'Deze club heeft al het maximumaantal actieve uitnodigingscodes.',
        'club_status_invalid'             => 'Deze statuswijziging is niet mogelijk voor deze club.',
        'club_member_not_found'           => 'Dit lid is niet gevonden in de club.',
        'club_member_banned'              => 'Deze gebruiker is verbannen uit de club. Hef de ban eerst op.',
        'stale_version'                   => 'Iemand anders heeft dit intussen gewijzigd. Herlaad de pagina en probeer het opnieuw.',
        'pack_reserved_for_competition'   => 'Deze pack is nu voorbehouden voor een competitie. Speel hem in de competitie.',
        'pack_in_competition'             => 'Deze pack hoort bij een competitie die nog niet is afgerond. Hij moet actief blijven tot de competitie is afgesloten of geannuleerd.',
        'competition_not_started'         => 'Deze competitie is nog niet begonnen.',
        'competition_ended'               => 'Deze competitie is afgelopen.',
        'competition_attempt_limit'       => 'Je hebt al je pogingen voor deze pack gebruikt.',
        'competition_pack_already_played' => 'Je hebt deze pack al gespeeld, dus hij kan niet meetellen in deze competitie.',
        'competition_attempt_in_progress' => 'Werk eerst je huidige poging af.',
        'competition_rules_locked'        => 'De competitie is begonnen, dus de regels kunnen niet meer gewijzigd worden.',
        'competition_invalid_pack'        => 'Deze pack kan niet gebruikt worden in deze competitie.',
        'competition_not_editable'        => 'Deze competitie kan niet meer gewijzigd worden.',
        'unexpected_challenge'            => 'Dat is niet de huidige uitdaging van deze poging.',
        'attempt_not_active'              => 'Deze poging is niet meer actief.',
        'photo_invalid'                   => 'Deze foto kon niet verwerkt worden. Gebruik een JPG-, PNG- of WebP-afbeelding.',
        'photo_duplicate'                 => 'Deze foto is al ingediend voor deze club.',
        'photo_status_invalid'            => 'Deze foto kan vanuit de huidige status niet naar die stap.',
        'photo_rights_required'           => 'Bevestig dat de club de rechten op deze foto heeft en dat de mensen op de foto akkoord gingen.',
        'photo_pending_limit'             => 'Er wachten al te veel foto\'s van deze club op beoordeling. Wacht tot er enkele verwerkt zijn.',
        'photo_pack_invalid'              => 'Deze uitdaging kan niet aan die pack worden toegevoegd.',
        'portal_two_factor_required'      => 'Vul de verificatiecode in die we je gemaild hebben om verder te gaan.',
    ],

    'audit' => [
        'club' => [
            'created'          => 'Club aangemaakt',
            'updated'          => 'Clubgegevens bijgewerkt',
            'suspended'        => 'Club geschorst',
            'archived'         => 'Club gearchiveerd',
            'reactivated'      => 'Club opnieuw geactiveerd',
            'deleted'          => 'Club verwijderd',
            'settings_changed' => 'Clubinstellingen gewijzigd',
        ],
        'member' => [
            'added'                 => 'Lid toegevoegd',
            'joined'                => 'Lid aangesloten',
            'left'                  => 'Lid vertrokken',
            'removed'               => 'Lid verwijderd',
            'banned'                => 'Lid verbannen',
            'unbanned'              => 'Ban opgeheven',
            'role_changed'          => 'Rol van lid gewijzigd',
            'ownership_transferred' => 'Eigenaarschap overgedragen',
        ],
        'code' => [
            'created'     => 'Uitnodigingscode aangemaakt',
            'revoked'     => 'Uitnodigingscode ingetrokken',
            'regenerated' => 'Uitnodigingscode vernieuwd',
        ],
        'pack' => [
            'created'  => 'Pack aangemaakt',
            'updated'  => 'Pack bijgewerkt',
            'archived' => 'Pack gearchiveerd',
            'linked'   => 'Pack gekoppeld aan competitie',
            'unlinked' => 'Pack losgekoppeld van competitie',
        ],
        'competition' => [
            'created'          => 'Competitie aangemaakt',
            'updated'          => 'Competitie bijgewerkt',
            'published'        => 'Competitie gepubliceerd',
            'cancelled'        => 'Competitie geannuleerd',
            'rules_locked'     => 'Competitieregels vergrendeld',
            'ends_at_extended' => 'Einde van competitie verlengd',
            'voided'           => 'Competitie ongeldig verklaard',
            'finalized'        => 'Competitie afgesloten',
            'rebuilt'          => 'Klassement herberekend',
        ],
        'attempt' => [
            'voided' => 'Poging ongeldig verklaard',
        ],
        'photo' => [
            'uploaded'            => 'Foto geüpload',
            'assigned'            => 'Foto toegewezen',
            'rejected'            => 'Foto afgewezen',
            'converted'           => 'Foto omgezet in een uitdaging',
            'in_review'           => 'Beoordeling van foto gestart',
            'preparation_started' => 'Voorbereiding van foto gestart',
            'withdrawn'           => 'Foto ingetrokken',
        ],
        'challenge' => [
            'created' => 'Club-uitdaging aangemaakt',
            'updated' => 'Club-uitdaging bijgewerkt',
        ],
    ],

    // Specific sentences for ClubDomainException messages and admin helper
    // texts (not error codes — see `errors` for those).
    'competition_messages' => [
        'pack_in_overlapping_competition' => 'Het pakket ":pack" zit al in de gepubliceerde competitie ":competition", waarvan de data overlappen. Kies andere data of een ander pakket.',
        'normal_play_allowed_helper'      => 'Met "Normaal spelen toegestaan" kunnen leden het pakket niet normaal spelen zolang er een competitiepoging op loopt, en geen competitiepoging starten zolang er een normale poging loopt. Het pakket eerst normaal uitspelen en daarna meedoen blijft mogelijk bij Eén poging en Meerdere pogingen; kies Geen herhaling of "Normaal spelen geblokkeerd" om dat te voorkomen.',
    ],
];
