<?php

/*
|--------------------------------------------------------------------------
| Friendly copy per AuthError code (App\Support\AuthError) — français
|--------------------------------------------------------------------------
| The CODE is the API contract and never changes per language; only this
| sentence does. Register: "tu" (informal), same as the app.
*/

return [
    'email_taken'        => 'Un compte existe déjà avec cet e-mail. Connecte-toi ou réinitialise ton mot de passe.',
    'username_taken'     => 'Ce nom d\'utilisateur est déjà pris.',
    'password_mismatch'  => 'Les mots de passe ne correspondent pas.',
    'validation_failed'  => 'Vérifie les champs signalés.',
    'invalid_credentials' => 'E-mail ou mot de passe incorrect.',
    'account_deleted'     => 'Ce compte a été supprimé. Tu peux créer un nouveau compte avec le même e-mail.',
    'session_invalid'     => 'Ta session a expiré. Reconnecte-toi.',
    'profile_unavailable' => 'Impossible de charger ton profil pour le moment. Réessaie dans un instant.',
    'two_factor_required'     => 'Nous avons envoyé un code de vérification à ton adresse e-mail.',
    'two_factor_code_invalid' => 'Ce code est incorrect. Vérifie le dernier e-mail reçu et réessaie.',
    'two_factor_code_expired' => 'Ce code a expiré. Reconnecte-toi pour en recevoir un nouveau.',
    'two_factor_locked'       => 'Trop de tentatives incorrectes. Appuie sur « Renvoyer le code » pour en recevoir un nouveau.',
    'two_factor_session_invalid' => 'Cette session de connexion a expiré. Reconnecte-toi.',
    'two_factor_send_failed'     => 'Nous n\'avons pas pu envoyer ton code de connexion pour le moment. Réessaie dans un instant.',
    'verification_code_invalid' => 'Ce code est incorrect. Vérifie le dernier e-mail reçu et réessaie.',
    'verification_code_expired' => 'Ce code a expiré. Demandes-en un nouveau.',
    'verification_locked'       => 'Trop de tentatives incorrectes. Demande un nouveau code.',
    'verification_no_code'      => 'Aucun code de vérification n\'est actif pour ce compte. Appuie sur « Renvoyer le code » pour en recevoir un nouveau.',
    'reset_token_invalid' => 'Ce lien de réinitialisation du mot de passe est invalide. Demandes-en un nouveau.',
    'reset_token_expired' => 'Ce lien de réinitialisation du mot de passe a expiré. Demandes-en un nouveau.',
    'reset_failed'        => 'Impossible de réinitialiser ton mot de passe pour le moment. Réessaie dans un instant.',
    'rate_limited'                     => 'Trop de tentatives. Patiente un peu et réessaie.',
    'registration_rate_limited'        => 'Trop de tentatives. Patiente un peu et réessaie.',
    'verification_resend_rate_limited' => 'Trop de tentatives. Patiente un peu et réessaie.',
    'password_reset_rate_limited'      => 'Trop de tentatives. Patiente un peu et réessaie.',
    'login_2fa_rate_limited'           => 'Trop de tentatives. Patiente un peu et réessaie.',
    'registration_captcha_required'    => 'Termine la vérification de sécurité puis réessaie.',
    'registration_captcha_failed'      => 'La vérification de sécurité a échoué. Réessaie.',
    'admin_account_protected' => 'Les comptes administrateur ne peuvent pas être supprimés depuis l\'application.',
    'unknown'             => 'Un problème est survenu. Réessaie.',
];
