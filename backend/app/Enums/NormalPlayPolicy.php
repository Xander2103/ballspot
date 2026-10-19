<?php

namespace App\Enums;

/**
 * Whether linked packs can be played on the normal track between publish and finalize (D15). blocked is the default.
 */
enum NormalPlayPolicy: string
{
    case Blocked = 'blocked';
    case Allowed = 'allowed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
