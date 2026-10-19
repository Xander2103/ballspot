<?php

namespace App\Enums;

/**
 * Stored club_competitions.state. The user-facing phase is derived from it plus the window (CompetitionPhase).
 */
enum CompetitionState: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Cancelled = 'cancelled';
    case Finalized = 'finalized';
    case Voided = 'voided';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
