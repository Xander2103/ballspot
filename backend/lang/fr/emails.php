<?php

/*
|--------------------------------------------------------------------------
| Transactional emails — français (informal "tu")
|--------------------------------------------------------------------------
| :app = BallPicker (config ballspot.app_name), :code = the one-time code,
| :minutes = expiry in minutes. Never put the code or a token in a log.
*/

return [
    'verify' => [
        'subject'  => 'Vérifie ton e-mail :app',
        'greeting' => 'Bienvenue sur :app !',
        'code'     => 'Ton code de vérification d\'e-mail est : :code',
        'expires'  => 'Ce code expire dans :minutes minutes.',
        'ignore'   => 'Si tu n\'as pas créé de compte, tu peux ignorer cet e-mail.',
    ],
    'login_code' => [
        'subject'  => 'Ton code de connexion :app',
        'greeting' => 'Vérifie ta connexion',
        'code'     => 'Ton code de connexion est : :code',
        'expires'  => 'Ce code expire dans :minutes minutes.',
        'ignore'   => 'Si ce n\'était pas toi, tu peux ignorer cet e-mail.',
    ],
    'reset' => [
        'subject'  => 'Réinitialise ton mot de passe :app',
        'greeting' => 'Réinitialise ton mot de passe',
        'intro'    => 'Tu reçois cet e-mail parce que nous avons reçu une demande de réinitialisation du mot de passe de ton compte :app.',
        'action'   => 'Réinitialiser le mot de passe',
        'ignore'   => 'Si tu n\'as pas demandé de réinitialisation de mot de passe, aucune action n\'est nécessaire. Tu peux ignorer cet e-mail en toute sécurité.',
        'expires'  => 'Ce lien de réinitialisation du mot de passe expirera bientôt.',
        'regards'  => 'À bientôt',
    ],
];
