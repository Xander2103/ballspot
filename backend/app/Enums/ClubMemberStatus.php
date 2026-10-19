<?php

namespace App\Enums;

/**
 * Lifecycle of a club_members row. Rows are kept for history and re-join rules: left/removed may re-join with a valid code, banned may not (D23).
 */
enum ClubMemberStatus: string
{
    case Active = 'active';
    case Left = 'left';
    case Removed = 'removed';
    case Banned = 'banned';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
