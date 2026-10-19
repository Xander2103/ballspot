<?php

/*
|--------------------------------------------------------------------------
| BallPicker Clubs — texto por código de error + etiquetas de auditoría
|--------------------------------------------------------------------------
| El CÓDIGO es el contrato de la API y nunca cambia según el idioma.
*/

return [
    'errors' => [
        'unknown'                         => 'Algo salió mal. Inténtalo de nuevo.',
        'feature_disabled'                => 'Los clubes aún no están disponibles.',
        'club_not_found'                  => 'No se ha encontrado este club.',
        'club_forbidden'                  => 'No tienes acceso a esta parte del club.',
        'club_code_invalid'               => 'Este código de invitación no es válido. Revisa el código e inténtalo de nuevo.',
        'club_join_locked'                => 'Demasiados códigos incorrectos. Espera un rato e inténtalo de nuevo.',
        'club_full'                       => 'Este club ha alcanzado su límite de miembros.',
        'club_membership_limit'           => 'Has alcanzado el número máximo de clubes a los que puedes unirte.',
        'club_unavailable'                => 'Este club no está disponible temporalmente.',
        'club_owner_must_transfer'        => 'Eres el único propietario de este club. Transfiere la propiedad antes de salir.',
        'club_last_owner'                 => 'Un club necesita al menos un propietario. Nombra primero a otro propietario.',
        'club_has_history'                => 'Este club tiene actividad y no se puede eliminar. Archívalo en su lugar.',
        'club_code_limit'                 => 'Este club ya tiene el número máximo de códigos de invitación activos.',
        'club_status_invalid'             => 'Este cambio de estado no es posible para este club.',
        'club_member_not_found'           => 'No se ha encontrado a este miembro en el club.',
        'club_member_banned'              => 'Este usuario está expulsado del club. Levanta primero la expulsión.',
        'stale_version'                   => 'Otra persona lo ha cambiado mientras tanto. Recarga la página e inténtalo de nuevo.',
        'pack_reserved_for_competition'   => 'Este pack está reservado ahora para una competición. Juégalo en la competición.',
        'competition_not_started'         => 'Esta competición aún no ha empezado.',
        'competition_ended'               => 'Esta competición ha terminado.',
        'competition_attempt_limit'       => 'Has usado todos tus intentos para este pack.',
        'competition_pack_already_played' => 'Ya has jugado este pack, así que no puede contar en esta competición.',
        'competition_attempt_in_progress' => 'Termina primero tu intento actual.',
        'competition_rules_locked'        => 'La competición ha empezado, así que sus reglas ya no se pueden cambiar.',
        'competition_invalid_pack'        => 'Este pack no se puede usar en esta competición.',
        'competition_not_editable'        => 'Esta competición ya no se puede modificar.',
        'unexpected_challenge'            => 'Ese no es el desafío actual de este intento.',
        'attempt_not_active'              => 'Este intento ya no está activo.',
        'photo_invalid'                   => 'No se ha podido procesar esta foto. Usa una imagen JPG, PNG o WebP.',
        'photo_duplicate'                 => 'Esta foto ya se ha enviado para este club.',
        'portal_two_factor_required'      => 'Introduce el código de verificación que te enviamos por correo para continuar.',
    ],

    'audit' => [
        'club' => [
            'created'          => 'Club creado',
            'updated'          => 'Datos del club actualizados',
            'suspended'        => 'Club suspendido',
            'archived'         => 'Club archivado',
            'reactivated'      => 'Club reactivado',
            'deleted'          => 'Club eliminado',
            'settings_changed' => 'Ajustes del club cambiados',
        ],
        'member' => [
            'added'                 => 'Miembro añadido',
            'joined'                => 'Miembro unido',
            'left'                  => 'Miembro salió',
            'removed'               => 'Miembro retirado',
            'banned'                => 'Miembro expulsado',
            'unbanned'              => 'Expulsión levantada',
            'role_changed'          => 'Rol del miembro cambiado',
            'ownership_transferred' => 'Propiedad transferida',
        ],
        'code' => [
            'created'     => 'Código de invitación creado',
            'revoked'     => 'Código de invitación revocado',
            'regenerated' => 'Código de invitación regenerado',
        ],
        'pack' => [
            'created'  => 'Pack creado',
            'updated'  => 'Pack actualizado',
            'archived' => 'Pack archivado',
            'linked'   => 'Pack vinculado a la competición',
            'unlinked' => 'Pack desvinculado de la competición',
        ],
        'competition' => [
            'created'          => 'Competición creada',
            'updated'          => 'Competición actualizada',
            'published'        => 'Competición publicada',
            'cancelled'        => 'Competición cancelada',
            'rules_locked'     => 'Reglas de la competición bloqueadas',
            'ends_at_extended' => 'Final de la competición ampliado',
            'voided'           => 'Competición anulada',
            'finalized'        => 'Competición cerrada',
            'rebuilt'          => 'Clasificación recalculada',
        ],
        'attempt' => [
            'voided' => 'Intento anulado',
        ],
        'photo' => [
            'uploaded'  => 'Foto subida',
            'assigned'  => 'Foto asignada',
            'rejected'  => 'Foto rechazada',
            'converted' => 'Foto convertida en desafío',
        ],
    ],
];
