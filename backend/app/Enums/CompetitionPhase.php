<?php

namespace App\Enums;

/**
 * Derived, never stored: ClubCompetition::phase() computes it from state + starts_at/ends_at so it can never drift.
 */
enum CompetitionPhase: string
{
    case Draft = 'draft';
    case Upcoming = 'upcoming';
    case Active = 'active';
    case Ended = 'ended';
    case Finalized = 'finalized';
    case Cancelled = 'cancelled';
    case Voided = 'voided';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
