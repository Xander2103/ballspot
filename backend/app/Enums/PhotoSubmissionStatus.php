<?php

namespace App\Enums;

/**
 * Club photo submission workflow: pending -> in_review -> in_preparation -> converted, or rejected.
 */
enum PhotoSubmissionStatus: string
{
    case Pending = 'pending';
    case InReview = 'in_review';
    case InPreparation = 'in_preparation';
    case Converted = 'converted';
    case Rejected = 'rejected';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
