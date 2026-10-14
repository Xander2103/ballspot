<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One growth event (share tap / review attempt) for the admin Growth page.
 *
 * Append-only; `created_at` only. The event-name allow-lists below are the
 * single source of truth: the API refuses anything else, and the share /
 * reward events can only be written server-side by ShareRewardService (a
 * client cannot inflate share stats or claim a reward through POST /events).
 */
class AppEvent extends Model
{
    public const UPDATED_AT = null;

    // --- Share (written by ShareRewardService only) ----------------------
    public const SHARE_APP_TAPPED                    = 'share_app_tapped';
    public const DAILY_RESULT_SHARE_TAPPED           = 'daily_result_share_tapped';
    public const DAILY_RESULT_HIGH_SCORE_SHARE_TAPPED = 'daily_result_high_score_share_tapped';
    public const SHARE_REWARD_CLAIMED                = 'share_reward_claimed';

    // --- Review / rate (reported by the app via POST /events) ------------
    // None of these ever grants XP, a badge or any other benefit.
    public const RATE_APP_TAPPED                        = 'rate_app_tapped';
    public const REVIEW_PROMPT_ATTEMPTED                = 'review_prompt_attempted';
    public const NATIVE_REVIEW_REQUESTED                = 'native_review_requested';
    public const REVIEW_PROMPT_SKIPPED_DUE_TO_COMPLETED = 'review_prompt_skipped_due_to_completed';
    public const REVIEW_PROMPT_SKIPPED_DUE_TO_COOLDOWN  = 'review_prompt_skipped_due_to_cooldown';

    /** Events that count as "a share" (total shares, Social Striker). */
    public const SHARE_EVENTS = [
        self::SHARE_APP_TAPPED,
        self::DAILY_RESULT_SHARE_TAPPED,
    ];

    /** Events the app may report directly. Share events are NOT in here. */
    public const CLIENT_EVENTS = [
        self::RATE_APP_TAPPED,
        self::REVIEW_PROMPT_ATTEMPTED,
        self::NATIVE_REVIEW_REQUESTED,
        self::REVIEW_PROMPT_SKIPPED_DUE_TO_COMPLETED,
        self::REVIEW_PROMPT_SKIPPED_DUE_TO_COOLDOWN,
    ];

    /** Every known event, in admin display order. */
    public const ALL = [
        self::SHARE_APP_TAPPED,
        self::DAILY_RESULT_SHARE_TAPPED,
        self::DAILY_RESULT_HIGH_SCORE_SHARE_TAPPED,
        self::SHARE_REWARD_CLAIMED,
        self::RATE_APP_TAPPED,
        self::REVIEW_PROMPT_ATTEMPTED,
        self::NATIVE_REVIEW_REQUESTED,
        self::REVIEW_PROMPT_SKIPPED_DUE_TO_COMPLETED,
        self::REVIEW_PROMPT_SKIPPED_DUE_TO_COOLDOWN,
    ];

    protected $fillable = ['user_id', 'event_name', 'metadata', 'created_at'];

    protected function casts(): array
    {
        return [
            'metadata'   => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
