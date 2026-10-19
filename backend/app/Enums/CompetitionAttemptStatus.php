<?php

namespace App\Enums;

/**
 * club_competition_attempts.status. expired = the window ended mid-attempt (its partial score counts, D16); voided = admin integrity action.
 */
enum CompetitionAttemptStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Expired = 'expired';
    case Voided = 'voided';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
