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
