<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class XpEvent extends Model
{
    public const SOURCE_DAILY_GUESS       = 'daily_guess';
    public const SOURCE_TOURNAMENT_GUESS  = 'tournament_guess';
    public const SOURCE_BADGE_UNLOCK      = 'badge_unlock';
    public const SOURCE_STREAK_BONUS      = 'streak_bonus';
    public const SOURCE_TOURNAMENT_WIN    = 'tournament_win';
    public const SOURCE_WEEKLY_FINISH     = 'weekly_finish';
    public const SOURCE_ADMIN_ADJUSTMENT  = 'admin_adjustment';
    public const SOURCE_PACK_GUESS        = 'pack_guess';
    public const SOURCE_PACK_COMPLETION   = 'pack_completion';
    public const SOURCE_COMPETITION_FINISH = 'competition_finish';
    // v1.9.12 share rewards (ShareRewardService). source_id: 1 for the
    // once-per-account first share, YYYYMMDD for the once-per-day awards.
    public const SOURCE_SHARE_FIRST            = 'share_first';
    public const SOURCE_SHARE_DAILY            = 'share_daily';
    public const SOURCE_SHARE_DAILY_HIGH_SCORE = 'share_daily_high_score';
    // BallPicker Clubs competition track (ClubRewardService, plan §5.8). Only
    // the player's rewarded run on a club pack pays out. source_id: the
    // competition guess id (per guess) / the competition attempt id (completion).
    // Normal club pack play keeps the pack_guess / pack_completion sources.
    public const SOURCE_CLUB_COMPETITION_GUESS      = 'club_competition_guess';
    public const SOURCE_CLUB_COMPETITION_COMPLETION = 'club_competition_completion';

    protected $fillable = [
        'user_id', 'source_type', 'source_id', 'amount', 'reason', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount'   => 'integer',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
