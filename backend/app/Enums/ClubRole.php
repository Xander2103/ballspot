<?php

namespace App\Enums;

/**
 * Role of an ACTIVE club membership (D5). Platform admins are not a club role:
 * they act through the admin area (Gate::before, see AppServiceProvider).
 */
enum ClubRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** Roles an invite code may grant. Ownership is never handed out by a code. */
    public static function grantable(): array
    {
        return [self::Member, self::Admin];
    }

    /** Owner or admin: may manage codes, members, photos and competitions. */
    public function canManage(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }
}
