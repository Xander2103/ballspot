<?php

namespace App\Enums;

/**
 * Per-competition replay rule (D2). single_attempt is the default.
 */
enum ReplayMode: string
{
    case NoReplay = 'no_replay';
    case SingleAttempt = 'single_attempt';
    case MultipleAttempts = 'multiple_attempts';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
