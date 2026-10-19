<?php

namespace App\Enums;

/**
 * Club status machine: active <-> suspended, active|suspended -> archived, archived -> active (plan §4.2). Suspended = read-only (D18); archived = hidden from My Clubs.
 */
enum ClubStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
