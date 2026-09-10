<?php

/*
|--------------------------------------------------------------------------
| Transactionele e-mails (gerenderd in de preferred_language van de ontvanger)
|--------------------------------------------------------------------------
| :app = BallPicker (config ballspot.app_name), :code = de eenmalige code,
| :minutes = geldigheid in minuten. Zet nooit de code of een token in een log.
*/

return [
    'verify' => [
        'subject'  => 'Bevestig je e-mailadres voor :app',
        'greeting' => 'Welkom bij :app!',
        'code'     => 'Je verificatiecode is: :code',
        'expires'  => 'Deze code verloopt over :minutes minuten.',
        'ignore'   => 'Als je geen account hebt aangemaakt, kun je deze e-mail negeren.',
    ],
    'login_code' => [
        'subject'  => 'Je :app-logincode',
        'greeting' => 'Bevestig je login',
        'code'     => 'Je logincode is: :code',
        'expires'  => 'Deze code verloopt over :minutes minuten.',
        'ignore'   => 'Als jij dit niet was, kun je deze e-mail negeren.',
    ],
    'reset' => [
        'subject'  => 'Reset je :app-wachtwoord',
        'greeting' => 'Reset je wachtwoord',
        'intro'    => 'Je ontvangt deze e-mail omdat we een verzoek hebben gekregen om het wachtwoord van je :app-account te resetten.',
        'action'   => 'Wachtwoord resetten',
        'ignore'   => 'Als je geen wachtwoordreset hebt aangevraagd, hoef je niets te doen. Je kunt deze e-mail gerust negeren.',
        'expires'  => 'Deze link om je wachtwoord te resetten verloopt binnenkort.',
        'regards'  => 'Groeten',
    ],
];
