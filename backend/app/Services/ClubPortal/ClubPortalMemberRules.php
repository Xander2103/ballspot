<?php

namespace App\Services\ClubPortal;

use App\Enums\ClubRole;
use App\Models\ClubInviteCode;
use App\Models\ClubMember;

/**
 * Who may do what to whom on the portal Members page (on top of the
 * ClubPolicy ability and the service invariants such as club_last_owner):
 *
 *  - nobody acts on their own membership here (leaving is an app action);
 *  - owners/admins never touch an owner unless they are an owner;
 *  - remove / ban: an active target; unban: a banned target;
 *  - role change admin ↔ member: owners only, never to or from owner
 *    (ownership moves only by transfer);
 *  - transfer ownership: owners only, to another active member;
 *  - invite codes: admin-granting codes are owner-only (create, regenerate,
 *    reveal, print); revoking any code is allowed to every club admin.
 *
 * Shared by the club portal and the club-admin API (Wave F): the single
 * implementation of the club role rules — never duplicate them.
 */
final class ClubPortalMemberRules
{
    public const REMOVE   = 'remove';
    public const BAN      = 'ban';
    public const UNBAN    = 'unban';
    public const ROLE     = 'role';
    public const TRANSFER = 'transfer';

    public static function allows(ClubMember $actor, ClubMember $target, string $action): bool
    {
        if (!$actor->canManage() || (int) $actor->club_id !== (int) $target->club_id) {
            return false;
        }
        if ((int) $actor->user_id === (int) $target->user_id) {
            return false;
        }

        $actorIsOwner = $actor->role === ClubRole::Owner;
        if ($target->role === ClubRole::Owner && !$actorIsOwner) {
            return false;
        }

        return match ($action) {
            self::REMOVE, self::BAN => $target->isActive(),
            self::UNBAN             => $target->isBanned(),
            self::ROLE              => $actorIsOwner && $target->isActive() && $target->role !== ClubRole::Owner,
            self::TRANSFER          => $actorIsOwner && $target->isActive(),
            default                 => false,
        };
    }

    /** @return list<string> */
    public static function allowedActions(ClubMember $actor, ClubMember $target): array
    {
        return array_values(array_filter(
            [self::REMOVE, self::BAN, self::UNBAN, self::ROLE, self::TRANSFER],
            fn (string $a) => self::allows($actor, $target, $a),
        ));
    }

    /** Admin-granting codes are owner-only; member codes any owner/admin. */
    public static function canHandleCode(ClubMember $actor, ClubInviteCode $code): bool
    {
        return $actor->canManage()
            && (int) $actor->club_id === (int) $code->club_id
            && ($code->grants_role !== ClubRole::Admin || $actor->role === ClubRole::Owner);
    }

    /**
     * Roles the actor may hand out with a new invite code.
     *
     * @return list<string>
     */
    public static function grantableCodeRoles(?ClubMember $actor): array
    {
        if ($actor === null || !$actor->canManage()) {
            return [];
        }

        return $actor->role === ClubRole::Owner
            ? [ClubRole::Member->value, ClubRole::Admin->value]
            : [ClubRole::Member->value];
    }
}
