<?php

/*
|--------------------------------------------------------------------------
| BallPicker Clubs — messages per stable error code + audit action labels
|--------------------------------------------------------------------------
| The CODE (App\Exceptions\ClubDomainException) is the API contract and never
| changes per language; only the sentence does. `audit` labels the allow-
| listed actions of App\Models\ClubAuditEvent::ACTIONS.
*/

return [
    'errors' => [
        'unknown'                         => 'Something went wrong. Please try again.',
        'feature_disabled'                => 'Clubs are not available yet.',
        'club_not_found'                  => 'This club could not be found.',
        'club_forbidden'                  => 'You do not have access to this part of the club.',
        'club_code_invalid'               => 'This invite code is not valid. Check the code and try again.',
        'club_join_locked'                => 'Too many incorrect codes. Please wait a while and try again.',
        'club_full'                       => 'This club has reached its member limit.',
        'club_membership_limit'           => 'You have reached the maximum number of clubs you can join.',
        'club_unavailable'                => 'This club is temporarily unavailable.',
        'club_owner_must_transfer'        => 'You are the only owner of this club. Transfer ownership before you leave.',
        'club_last_owner'                 => 'A club needs at least one owner. Appoint another owner first.',
        'club_has_history'                => 'This club has activity and cannot be deleted. Archive it instead.',
        'club_code_limit'                 => 'This club already has the maximum number of active invite codes.',
        'club_status_invalid'             => 'This status change is not possible for this club.',
        'club_member_not_found'           => 'This member could not be found in the club.',
        'club_member_banned'              => 'This user is banned from the club. Lift the ban first.',
        'stale_version'                   => 'Someone else changed this in the meantime. Reload the page and try again.',
        'pack_reserved_for_competition'   => 'This pack is reserved for a competition right now. Play it in the competition.',
        'competition_not_started'         => 'This competition has not started yet.',
        'competition_ended'               => 'This competition has ended.',
        'competition_attempt_limit'       => 'You have used all your attempts for this pack.',
        'competition_pack_already_played' => 'You have already played this pack, so it cannot count in this competition.',
        'competition_attempt_in_progress' => 'Finish your current attempt first.',
        'competition_rules_locked'        => 'The competition has started, so its rules can no longer be changed.',
        'competition_invalid_pack'        => 'This pack cannot be used in this competition.',
        'competition_not_editable'        => 'This competition can no longer be changed.',
        'unexpected_challenge'            => 'That is not the current challenge of this attempt.',
        'attempt_not_active'              => 'This attempt is no longer active.',
        'photo_invalid'                   => 'This photo could not be processed. Use a JPG, PNG or WebP image.',
        'photo_duplicate'                 => 'This photo has already been submitted for this club.',
        'portal_two_factor_required'      => 'Enter the verification code we emailed you to continue.',
    ],

    'audit' => [
        'club' => [
            'created'          => 'Club created',
            'updated'          => 'Club details updated',
            'suspended'        => 'Club suspended',
            'archived'         => 'Club archived',
            'reactivated'      => 'Club reactivated',
            'deleted'          => 'Club deleted',
            'settings_changed' => 'Club settings changed',
        ],
        'member' => [
            'added'                 => 'Member added',
            'joined'                => 'Member joined',
            'left'                  => 'Member left',
            'removed'               => 'Member removed',
            'banned'                => 'Member banned',
            'unbanned'              => 'Ban lifted',
            'role_changed'          => 'Member role changed',
            'ownership_transferred' => 'Ownership transferred',
        ],
        'code' => [
            'created'     => 'Invite code created',
            'revoked'     => 'Invite code revoked',
            'regenerated' => 'Invite code regenerated',
        ],
        'pack' => [
            'created'  => 'Pack created',
            'updated'  => 'Pack updated',
            'archived' => 'Pack archived',
            'linked'   => 'Pack linked to competition',
            'unlinked' => 'Pack unlinked from competition',
        ],
        'competition' => [
            'created'          => 'Competition created',
            'updated'          => 'Competition updated',
            'published'        => 'Competition published',
            'cancelled'        => 'Competition cancelled',
            'rules_locked'     => 'Competition rules locked',
            'ends_at_extended' => 'Competition end extended',
            'voided'           => 'Competition voided',
            'finalized'        => 'Competition finalized',
            'rebuilt'          => 'Standings rebuilt',
        ],
        'attempt' => [
            'voided' => 'Attempt voided',
        ],
        'photo' => [
            'uploaded'  => 'Photo uploaded',
            'assigned'  => 'Photo assigned',
            'rejected'  => 'Photo rejected',
            'converted' => 'Photo converted to a challenge',
        ],
    ],
];
