<?php

/*
|--------------------------------------------------------------------------
| Vriendelijke tekst per AuthError-code (App\Support\AuthError) — Nederlands
|--------------------------------------------------------------------------
| De CODE is het API-contract en verandert nooit per taal; alleen deze zin
| wel. De mobiele app vertaalt de code zelf (client-side), dus deze strings
| zijn wat oudere clients en de webpagina's tonen.
*/

return [
    'email_taken'        => 'Er bestaat al een account met dit e-mailadres. Log in of reset je wachtwoord.',
    'username_taken'     => 'Deze gebruikersnaam is al in gebruik.',
    'password_mismatch'  => 'De wachtwoorden komen niet overeen.',
    'validation_failed'  => 'Controleer de gemarkeerde velden.',
    'invalid_credentials' => 'Ongeldig e-mailadres of wachtwoord.',
    'account_deleted'     => 'Dit account is verwijderd. Je kunt een nieuw account aanmaken met hetzelfde e-mailadres.',
    'two_factor_required'     => 'We hebben een verificatiecode naar je e-mail gestuurd.',
    'two_factor_code_invalid' => 'Die code klopt niet. Bekijk de nieuwste e-mail en probeer opnieuw.',
    'two_factor_code_expired' => 'Deze code is verlopen. Log opnieuw in om een nieuwe te krijgen.',
    'two_factor_locked'       => 'Te veel foute pogingen. Tik op "Code opnieuw sturen" voor een nieuwe.',
    'two_factor_session_invalid' => 'Deze loginsessie is verlopen. Log opnieuw in.',
    'verification_code_invalid' => 'Die code klopt niet. Bekijk de nieuwste e-mail en probeer opnieuw.',
    'verification_code_expired' => 'Deze code is verlopen. Vraag een nieuwe aan.',
    'verification_locked'       => 'Te veel foute pogingen. Vraag een nieuwe code aan.',
    'verification_no_code'      => 'Er is geen actieve verificatiecode voor dit account. Tik op "Code opnieuw sturen" voor een nieuwe.',
    'reset_token_invalid' => 'Deze link om je wachtwoord te resetten is ongeldig. Vraag een nieuwe aan.',
    'reset_token_expired' => 'Deze link om je wachtwoord te resetten is verlopen. Vraag een nieuwe aan.',
    'reset_failed'        => 'We konden je wachtwoord nu niet resetten. Probeer het zo meteen opnieuw.',
    'admin_account_protected' => 'Beheerdersaccounts kunnen niet via de app worden verwijderd.',
    'unknown'             => 'Er ging iets mis. Probeer het opnieuw.',
];
