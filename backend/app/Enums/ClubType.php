<?php

namespace App\Enums;

/**
 * Kind of organisation behind a club (admin index filter).
 */
enum ClubType: string
{
    case SportsClub = 'sports_club';
    case School = 'school';
    case Company = 'company';
    case Community = 'community';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
