<?php

/*
|--------------------------------------------------------------------------
| Publieke webpagina's: wachtwoordreset-fallback (forgot / reset / result)
|--------------------------------------------------------------------------
| Gerenderd in de locale die App\Http\Middleware\SetLocale bepaalt
| (?lang= uit de e-maillink, dan Accept-Language, dan de standaard).
*/

return [
    'nav' => [
        'privacy' => 'Privacy',
        'terms'   => 'Voorwaarden',
        'support' => 'Support',
    ],
    'footer' => [
        'made_by' => 'BallPicker is gemaakt door Van Malder Studio.',
    ],
    'forgot' => [
        'title'          => 'Wachtwoord vergeten',
        'heading'        => 'Wachtwoord vergeten?',
        'intro'          => 'Vul het e-mailadres van je BallPicker-account in en we sturen je een resetlink.',
        'email'          => 'E-mail',
        'submit'         => 'Resetlink sturen',
        'sent_title'     => 'Bekijk je e-mail',
        'sent_heading'   => 'Bekijk je e-mail',
        'sent_intro'     => 'Als er een account bestaat voor dat adres, hebben we een link gestuurd om je wachtwoord te resetten.',
        'sent_callout'   => 'De link is maar beperkt geldig. Zie je de e-mail niet binnen een paar minuten, kijk dan in je spamfolder of vraag een nieuwe link aan.',
        'send_another'   => 'Nog een link sturen',
    ],
    'reset' => [
        'title'              => 'Wachtwoord resetten',
        'heading'            => 'Reset je wachtwoord',
        'intro'              => 'Kies een nieuw wachtwoord voor je BallPicker-account.',
        'needs_link'         => 'Deze pagina heeft de link uit je wachtwoordreset-e-mail nodig. Werkt de link niet meer, vraag dan hieronder een nieuwe aan.',
        'request_new'        => 'Nieuwe link aanvragen',
        'email'              => 'E-mail',
        'password'           => 'Nieuw wachtwoord (minstens 8 tekens)',
        'password_confirm'   => 'Bevestig nieuw wachtwoord',
        'submit'             => 'Nieuw wachtwoord instellen',
        'open_in_app'        => 'Liever openen in de BallPicker-app',
        'open_in_app_hint'   => 'Werkt alleen op een telefoon met BallPicker geïnstalleerd. Gebruik anders gewoon het formulier hierboven — open na het opslaan de app en log in met je nieuwe wachtwoord.',
        'link_not_working'   => 'Werkt de link niet?',
    ],
    'result' => [
        'ok_title'        => 'Wachtwoord bijgewerkt',
        'ok_heading'      => 'Wachtwoord bijgewerkt',
        'ok_intro'        => 'Je BallPicker-wachtwoord is gewijzigd en alle andere sessies zijn uitgelogd.',
        'ok_callout'      => 'Open de BallPicker-app en log in met je nieuwe wachtwoord.',
        'failed_title'    => 'Probeer het opnieuw',
        'failed_heading'  => 'Probeer het opnieuw',
        'failed_callout'  => 'Er is niets gewijzigd: je huidige wachtwoord werkt nog en deze resetlink is nog geldig.',
        'try_again'       => 'Probeer opnieuw',
        'expired_title'   => 'Resetlink verlopen',
        'expired_heading' => 'Deze link werkt niet meer',
        'expired_callout' => 'Resetlinks zijn maar beperkt geldig en kunnen maar één keer worden gebruikt. Vraag een nieuwe aan en gebruik de nieuwste e-mail.',
        'request_new'     => 'Nieuwe link aanvragen',
    ],
];
