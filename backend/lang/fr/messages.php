<?php

/*
|--------------------------------------------------------------------------
| API messages returned directly to the app (+ server push copy) — français
|--------------------------------------------------------------------------
| Machine-readable `code`/`reason` fields stay English and stable; only the
| human `message` is translated. Rendered under the requesting user's
| preferred_language (App\Http\Middleware\SetLocale). Register: "tu".
*/

return [
    'rate_limited' => 'Trop de requêtes. Réessaie dans :seconds secondes.',

    'auth' => [
        'logged_out'                   => 'Déconnecté',
        'verify_email_new_code'        => 'Vérifie ton adresse e-mail pour continuer. Nous t\'avons envoyé un nouveau code.',
        'verify_email_existing_code'   => 'Vérifie ton adresse e-mail pour continuer. Saisis le code que nous t\'avons envoyé par e-mail, ou demandes-en un nouveau.',
        'email_verified'               => 'Ton e-mail a été vérifié.',
        'email_already_verified'       => 'Ton e-mail est déjà vérifié.',
        'verification_code_resent'     => 'Un nouveau code de vérification a été envoyé à ton adresse e-mail.',
        'verification_code_required'   => 'Saisis le code à 6 chiffres reçu par e-mail.',
        'verification_session_mismatch' => 'Ce code appartient à un autre compte que celui connecté sur cet appareil. Reconnecte-toi avec le compte que tu viens de créer.',
        'resend_cooldown'              => 'Patiente un instant avant de demander un autre code.',
        'resend_failed'                => 'Impossible d\'envoyer l\'e-mail pour le moment. Réessaie dans un instant.',
        'login_code_resent'            => 'Si ta connexion est toujours en attente, un nouveau code a été envoyé à ton adresse e-mail.',
        'login_again'                  => 'Reconnecte-toi.',
        'reset_link_sent'              => 'Si un compte existe pour cet e-mail, un lien de réinitialisation du mot de passe a été envoyé.',
        'password_reset_done'          => 'Ton mot de passe a été réinitialisé. Connecte-toi.',
        'reset_link_invalid'           => 'Ce lien de réinitialisation du mot de passe est invalide ou a expiré.',
        'beta_code_required'           => 'Un code bêta est requis pendant la phase de test fermée.',
        'beta_code_invalid'            => 'Code bêta invalide.',
        'password_min'                 => 'Le mot de passe doit contenir au moins 8 caractères.',
        'language_unsupported'         => 'Choisis une langue prise en charge.',
        'terms_required'               => 'Tu dois accepter les Conditions d\'utilisation et la Politique de confidentialité.',
        'age_required'                 => 'Tu dois confirmer que tu as l\'âge minimum requis.',
    ],

    'account' => [
        'deleted'        => 'Ton compte a été supprimé.',
        'delete_failed'  => 'Impossible de supprimer ton compte pour le moment. Réessaie dans un instant ou contacte l\'assistance.',
    ],

    'preferences' => [
        'sport_unavailable'  => 'Ce sport n\'est pas encore disponible.',
        'theme_unavailable'  => 'Ce thème n\'est pas disponible.',
        'two_factor_boolean' => 'La connexion à deux facteurs doit être activée ou désactivée.',
    ],

    'daily' => [
        'not_active'      => 'Ce défi du jour n\'est pas actif.',
        'not_today'       => 'Ce défi du jour n\'est pas disponible aujourd\'hui.',
        'already_played'  => 'Tu as déjà joué au défi d\'aujourd\'hui.',
        'no_guess'        => 'Aucun essai trouvé pour ce défi.',
    ],

    'friends' => [
        'code_not_found'     => 'Aucun joueur trouvé avec ce code ami.',
        'self'               => 'Tu ne peux pas t\'ajouter toi-même comme ami.',
        'already_friends'    => 'Tu es déjà ami avec ce joueur.',
        'pending_exists'     => 'Une demande est déjà en attente avec ce joueur.',
        'declined'           => 'Ce joueur a refusé ta demande. Tu pourras réessayer plus tard.',
        'not_addressed'      => 'Cette demande ne t\'est pas adressée.',
        'not_pending'        => 'Cette demande n\'est plus en attente.',
        'rejected'           => 'Demande refusée.',
        'not_friends'        => 'Tu n\'es pas ami avec ce joueur.',
    ],

    'tournaments' => [
        'not_member'         => 'Tu n\'es pas membre de cette ligue',
        'hide_only_finished' => 'Seuls les tournois terminés peuvent être retirés de ta liste.',
        'owner_only_start'   => 'Seul l\'organisateur peut démarrer ce tournoi.',
        'start_from_lobby'   => 'Le tournoi ne peut être démarré que depuis le salon.',
        'no_challenges'      => 'Aucun défi :sport actif disponible. Ajoute des défis dans l\'admin.',
        'owner_only_cancel'  => 'Seul l\'organisateur peut annuler ce tournoi.',
        'owner_only_remove'  => 'Seul l\'organisateur peut retirer des joueurs.',
        'remove_in_lobby'    => 'Les joueurs ne peuvent être retirés que lorsque le tournoi est dans le salon.',
        'owner_not_removable' => 'L\'organisateur ne peut pas être retiré.',
        'daily_limit'        => 'Tu as joué toutes les manches disponibles pour aujourd\'hui.',
        'not_enough_challenges' => 'Pas assez de défis de tournoi inutilisés disponibles. Ajoute d\'abord d\'autres photos de tournoi.',
        'temporarily_unavailable' => 'Les tournois sont temporairement indisponibles pendant que nous préparons de nouveaux défis. Réessaie bientôt.',
        'temporarily_unavailable_next_month' => 'Les tournois sont temporairement indisponibles pendant que nous préparons de nouveaux défis. Réessaie au début du mois prochain.',
    ],

    'packs' => [
        'already_completed' => 'Tu as déjà terminé ce pack.',
    ],

    'push' => [
        'daily_reminder_title' => 'Défi du jour',
        'daily_reminder_body'  => 'Le défi :app d\'aujourd\'hui t\'attend toujours ⚽',
    ],
];
