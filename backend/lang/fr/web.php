<?php

/*
|--------------------------------------------------------------------------
| Public web pages: password reset fallback (forgot / reset / result) — fr
|--------------------------------------------------------------------------
| Rendered under the locale resolved by App\Http\Middleware\SetLocale
| (?lang= from the email link, then Accept-Language, then the default).
| Register: "tu" (informal), same as the app and the emails.
*/

return [
    'nav' => [
        'privacy' => 'Confidentialité',
        'terms'   => 'Conditions',
        'support' => 'Assistance',
    ],
    'footer' => [
        'made_by' => 'BallPicker est créé par Van Malder Studio.',
    ],
    'forgot' => [
        'title'          => 'Mot de passe oublié',
        'heading'        => 'Mot de passe oublié ?',
        'intro'          => 'Saisis l\'e-mail de ton compte BallPicker et nous t\'enverrons un lien de réinitialisation.',
        'email'          => 'E-mail',
        'submit'         => 'Envoyer le lien',
        'sent_title'     => 'Vérifie tes e-mails',
        'sent_heading'   => 'Vérifie tes e-mails',
        'sent_intro'     => 'Si un compte existe pour cette adresse, nous avons envoyé un lien pour réinitialiser ton mot de passe.',
        'sent_callout'   => 'Le lien fonctionne pendant une durée limitée. Si tu ne vois pas l\'e-mail d\'ici quelques minutes, vérifie ton dossier spam ou demande un nouveau lien.',
        'send_another'   => 'Envoyer un autre lien',
    ],
    'reset' => [
        'title'              => 'Réinitialiser le mot de passe',
        'heading'            => 'Réinitialise ton mot de passe',
        'intro'              => 'Choisis un nouveau mot de passe pour ton compte BallPicker.',
        'needs_link'         => 'Cette page a besoin du lien de ton e-mail de réinitialisation de mot de passe. Si le lien ne fonctionne plus, demandes-en un nouveau ci-dessous.',
        'request_new'        => 'Demander un nouveau lien',
        'email'              => 'E-mail',
        'password'           => 'Nouveau mot de passe (au moins 8 caractères)',
        'password_confirm'   => 'Confirmer le nouveau mot de passe',
        'submit'             => 'Définir le nouveau mot de passe',
        'open_in_app'        => 'Ouvrir dans l\'app BallPicker à la place',
        'open_in_app_hint'   => 'Fonctionne uniquement sur un téléphone où BallPicker est installé. Sinon, utilise simplement le formulaire ci-dessus — après l\'enregistrement, ouvre l\'app et connecte-toi avec ton nouveau mot de passe.',
        'link_not_working'   => 'Le lien ne fonctionne pas ?',
    ],
    'result' => [
        'ok_title'        => 'Mot de passe mis à jour',
        'ok_heading'      => 'Mot de passe mis à jour',
        'ok_intro'        => 'Ton mot de passe BallPicker a été modifié et toutes les autres sessions ont été déconnectées.',
        'ok_callout'      => 'Ouvre l\'app BallPicker et connecte-toi avec ton nouveau mot de passe.',
        'failed_title'    => 'Réessaie',
        'failed_heading'  => 'Réessaie',
        'failed_callout'  => 'Rien n\'a été modifié : ton mot de passe actuel fonctionne toujours et ce lien de réinitialisation est encore valable.',
        'try_again'       => 'Réessayer',
        'expired_title'   => 'Lien de réinitialisation expiré',
        'expired_heading' => 'Ce lien ne fonctionne plus',
        'expired_callout' => 'Les liens de réinitialisation sont valables pendant une durée limitée et ne peuvent être utilisés qu\'une seule fois. Demandes-en un nouveau et utilise le dernier e-mail reçu.',
        'request_new'     => 'Demander un nouveau lien',
    ],
];
