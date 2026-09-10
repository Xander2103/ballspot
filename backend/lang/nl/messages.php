<?php

/*
|--------------------------------------------------------------------------
| API-berichten die rechtstreeks naar de app gaan (+ server-pushteksten)
|--------------------------------------------------------------------------
| Machine-leesbare `code`/`reason`-velden blijven Engels en stabiel; alleen
| het menselijke `message` wordt vertaald. Gerenderd in de preferred_language
| van de aanvragende gebruiker (App\Http\Middleware\SetLocale).
*/

return [
    'rate_limited' => 'Te veel verzoeken. Probeer het over :seconds seconden opnieuw.',

    'auth' => [
        'logged_out'                   => 'Uitgelogd',
        'verify_email_new_code'        => 'Bevestig je e-mailadres om verder te gaan. We hebben je een nieuwe code gestuurd.',
        'verify_email_existing_code'   => 'Bevestig je e-mailadres om verder te gaan. Vul de code in die we je hebben gemaild, of vraag een nieuwe aan.',
        'email_verified'               => 'Je e-mailadres is bevestigd.',
        'email_already_verified'       => 'Je e-mailadres is al bevestigd.',
        'verification_code_resent'     => 'Er is een nieuwe verificatiecode naar je e-mail verstuurd.',
        'verification_code_required'   => 'Vul de 6-cijferige code uit je e-mail in.',
        'verification_session_mismatch' => 'Deze code hoort bij een ander account dan het account dat op dit toestel is ingelogd. Log opnieuw in met het account dat je net hebt aangemaakt.',
        'resend_cooldown'              => 'Wacht even voor je een nieuwe code aanvraagt.',
        'resend_failed'                => 'We konden de e-mail nu niet versturen. Probeer het zo meteen opnieuw.',
        'login_code_resent'            => 'Als je login nog openstaat, is er een nieuwe code naar je e-mail verstuurd.',
        'login_again'                  => 'Log opnieuw in.',
        'reset_link_sent'              => 'Als er een account bestaat voor dat e-mailadres, is er een link verstuurd om je wachtwoord te resetten.',
        'password_reset_done'          => 'Je wachtwoord is gereset. Log in.',
        'reset_link_invalid'           => 'Deze link om je wachtwoord te resetten is ongeldig of verlopen.',
        'beta_code_required'           => 'Tijdens de gesloten test is een betacode verplicht.',
        'beta_code_invalid'            => 'Ongeldige betacode.',
        'password_min'                 => 'Het wachtwoord moet minstens 8 tekens bevatten.',
        'language_unsupported'         => 'Kies een ondersteunde taal.',
        'terms_required'               => 'Je moet de Gebruiksvoorwaarden en het Privacybeleid accepteren.',
        'age_required'                 => 'Je moet bevestigen dat je aan de minimumleeftijd voldoet.',
    ],

    'account' => [
        'deleted'        => 'Je account is verwijderd.',
        'delete_failed'  => 'We konden je account nu niet verwijderen. Probeer het zo meteen opnieuw of neem contact op met support.',
    ],

    'preferences' => [
        'sport_unavailable'  => 'Deze sport is nog niet beschikbaar.',
        'theme_unavailable'  => 'Dat thema is niet beschikbaar.',
        'two_factor_boolean' => 'Tweestapsverificatie moet aan of uit staan.',
    ],

    'daily' => [
        'not_active'      => 'Deze dagelijkse uitdaging is niet actief.',
        'not_today'       => 'Deze dagelijkse uitdaging is vandaag niet beschikbaar.',
        'already_played'  => 'Je hebt de uitdaging van vandaag al gespeeld.',
        'no_guess'        => 'Geen gok gevonden voor deze uitdaging.',
    ],

    'friends' => [
        'code_not_found'     => 'Geen speler gevonden met die vriendencode.',
        'self'               => 'Je kunt jezelf niet als vriend toevoegen.',
        'already_friends'    => 'Je bent al bevriend met deze speler.',
        'pending_exists'     => 'Er staat al een verzoek open met deze speler.',
        'declined'           => 'Deze speler heeft je verzoek geweigerd. Je kunt het later opnieuw proberen.',
        'not_addressed'      => 'Dit verzoek is niet aan jou gericht.',
        'not_pending'        => 'Dit verzoek staat niet meer open.',
        'rejected'           => 'Verzoek geweigerd.',
        'not_friends'        => 'Je bent niet bevriend met deze speler.',
    ],

    'tournaments' => [
        'not_member'         => 'Geen lid van deze league',
        'hide_only_finished' => 'Alleen afgelopen toernooien kunnen uit je lijst worden verwijderd.',
        'owner_only_start'   => 'Alleen de host kan dit toernooi starten.',
        'start_from_lobby'   => 'Een toernooi kan alleen vanuit de lobbystatus worden gestart.',
        'no_challenges'      => 'Geen actieve :sport-uitdagingen beschikbaar. Voeg uitdagingen toe in de admin.',
        'owner_only_cancel'  => 'Alleen de host kan dit toernooi annuleren.',
        'owner_only_remove'  => 'Alleen de host kan spelers verwijderen.',
        'remove_in_lobby'    => 'Spelers kunnen alleen worden verwijderd zolang het toernooi in de lobby staat.',
        'owner_not_removable' => 'De host kan niet worden verwijderd.',
        'daily_limit'        => 'Je hebt alle rondes gespeeld die vandaag beschikbaar zijn.',
        'not_enough_challenges' => 'Niet genoeg ongebruikte toernooi-uitdagingen beschikbaar. Voeg eerst meer toernooifoto\'s toe.',
        'temporarily_unavailable' => 'Toernooien zijn tijdelijk niet beschikbaar. We bereiden nieuwe challenges voor. Probeer binnenkort opnieuw.',
        'temporarily_unavailable_next_month' => 'Toernooien zijn tijdelijk niet beschikbaar. We bereiden nieuwe challenges voor. Probeer het begin volgende maand opnieuw.',
    ],

    'packs' => [
        'already_completed' => 'Je hebt dit pakket al voltooid.',
    ],

    'push' => [
        'daily_reminder_title' => 'Dagelijkse uitdaging',
        'daily_reminder_body'  => 'De dagelijkse :app-uitdaging van vandaag wacht nog op je ⚽',
    ],
];
