<?php

/*
|--------------------------------------------------------------------------
| BallPicker Clubs — texte par code d'erreur + libellés des actions d'audit
|--------------------------------------------------------------------------
| Le CODE est le contrat de l'API et ne change jamais selon la langue.
*/

return [
    'errors' => [
        'unknown'                         => 'Une erreur s\'est produite. Réessaie.',
        'feature_disabled'                => 'Les clubs ne sont pas encore disponibles.',
        'club_not_found'                  => 'Ce club est introuvable.',
        'club_forbidden'                  => 'Tu n\'as pas accès à cette partie du club.',
        'club_code_invalid'               => 'Ce code d\'invitation n\'est pas valide. Vérifie le code et réessaie.',
        'club_join_locked'                => 'Trop de codes incorrects. Patiente un moment puis réessaie.',
        'club_full'                       => 'Ce club a atteint son nombre maximal de membres.',
        'club_membership_limit'           => 'Tu as atteint le nombre maximal de clubs que tu peux rejoindre.',
        'club_unavailable'                => 'Ce club est temporairement indisponible.',
        'club_owner_must_transfer'        => 'Tu es le seul propriétaire de ce club. Transfère la propriété avant de partir.',
        'club_last_owner'                 => 'Un club doit avoir au moins un propriétaire. Désigne d\'abord un autre propriétaire.',
        'club_has_history'                => 'Ce club a de l\'activité et ne peut pas être supprimé. Archive-le plutôt.',
        'club_code_limit'                 => 'Ce club a déjà le nombre maximal de codes d\'invitation actifs.',
        'club_status_invalid'             => 'Ce changement de statut n\'est pas possible pour ce club.',
        'club_member_not_found'           => 'Ce membre est introuvable dans le club.',
        'club_member_banned'              => 'Cet utilisateur est banni du club. Lève d\'abord le bannissement.',
        'stale_version'                   => 'Quelqu\'un d\'autre a modifié ceci entre-temps. Recharge la page et réessaie.',
        'pack_reserved_for_competition'   => 'Ce pack est réservé à une compétition pour le moment. Joue-le dans la compétition.',
        'competition_not_started'         => 'Cette compétition n\'a pas encore commencé.',
        'competition_ended'               => 'Cette compétition est terminée.',
        'competition_attempt_limit'       => 'Tu as utilisé toutes tes tentatives pour ce pack.',
        'competition_pack_already_played' => 'Tu as déjà joué ce pack, il ne peut donc pas compter dans cette compétition.',
        'competition_attempt_in_progress' => 'Termine d\'abord ta tentative en cours.',
        'competition_rules_locked'        => 'La compétition a commencé, ses règles ne peuvent plus être modifiées.',
        'competition_invalid_pack'        => 'Ce pack ne peut pas être utilisé dans cette compétition.',
        'competition_not_editable'        => 'Cette compétition ne peut plus être modifiée.',
        'unexpected_challenge'            => 'Ce n\'est pas le défi en cours de cette tentative.',
        'attempt_not_active'              => 'Cette tentative n\'est plus active.',
        'photo_invalid'                   => 'Cette photo n\'a pas pu être traitée. Utilise une image JPG, PNG ou WebP.',
        'photo_duplicate'                 => 'Cette photo a déjà été envoyée pour ce club.',
        'photo_status_invalid'            => 'Cette photo ne peut pas passer à cette étape depuis son statut actuel.',
        'photo_rights_required'           => 'Confirme que le club détient les droits sur cette photo et que les personnes visibles ont donné leur accord.',
        'photo_pending_limit'             => 'Ce club a déjà trop de photos en attente de vérification. Attends que certaines soient traitées.',
        'photo_pack_invalid'              => 'Ce défi ne peut pas être ajouté à ce pack.',
        'portal_two_factor_required'      => 'Saisis le code de vérification que nous t\'avons envoyé par e-mail pour continuer.',
    ],

    'audit' => [
        'club' => [
            'created'          => 'Club créé',
            'updated'          => 'Informations du club mises à jour',
            'suspended'        => 'Club suspendu',
            'archived'         => 'Club archivé',
            'reactivated'      => 'Club réactivé',
            'deleted'          => 'Club supprimé',
            'settings_changed' => 'Paramètres du club modifiés',
        ],
        'member' => [
            'added'                 => 'Membre ajouté',
            'joined'                => 'Membre inscrit',
            'left'                  => 'Membre parti',
            'removed'               => 'Membre retiré',
            'banned'                => 'Membre banni',
            'unbanned'              => 'Bannissement levé',
            'role_changed'          => 'Rôle du membre modifié',
            'ownership_transferred' => 'Propriété transférée',
        ],
        'code' => [
            'created'     => 'Code d\'invitation créé',
            'revoked'     => 'Code d\'invitation révoqué',
            'regenerated' => 'Code d\'invitation régénéré',
        ],
        'pack' => [
            'created'  => 'Pack créé',
            'updated'  => 'Pack mis à jour',
            'archived' => 'Pack archivé',
            'linked'   => 'Pack lié à la compétition',
            'unlinked' => 'Pack retiré de la compétition',
        ],
        'competition' => [
            'created'          => 'Compétition créée',
            'updated'          => 'Compétition mise à jour',
            'published'        => 'Compétition publiée',
            'cancelled'        => 'Compétition annulée',
            'rules_locked'     => 'Règles de la compétition verrouillées',
            'ends_at_extended' => 'Fin de la compétition prolongée',
            'voided'           => 'Compétition invalidée',
            'finalized'        => 'Compétition clôturée',
            'rebuilt'          => 'Classement recalculé',
        ],
        'attempt' => [
            'voided' => 'Tentative invalidée',
        ],
        'photo' => [
            'uploaded'            => 'Photo envoyée',
            'assigned'            => 'Photo attribuée',
            'rejected'            => 'Photo refusée',
            'converted'           => 'Photo convertie en défi',
            'in_review'           => 'Vérification de la photo commencée',
            'preparation_started' => 'Préparation de la photo commencée',
            'withdrawn'           => 'Photo retirée',
        ],
        'challenge' => [
            'created' => 'Défi du club créé',
            'updated' => 'Défi du club modifié',
        ],
    ],
];
