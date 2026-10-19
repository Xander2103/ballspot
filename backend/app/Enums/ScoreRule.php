<?php

namespace App\Enums;

/**
 * Which non-voided attempt counts per (player, pack) in multiple_attempts mode. Forced to first when max_attempts = 1.
 */
enum ScoreRule: string
{
    case First = 'first';
    case Best = 'best';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
