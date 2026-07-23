<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's final placement in a CLOSED competition period. Written once,
 * idempotently, by CompetitionCloseService. Virtual recognition only — no
 * prizes/money. Survives account deletion (user_id nulls) as a historical record.
 */
class CompetitionFinish extends Model
{
    protected $fillable = [
        'user_id', 'period_type', 'period_label', 'period_start', 'period_end',
        'placement', 'total_score', 'total_players', 'xp_awarded', 'metadata', 'awarded_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start'  => 'date',
            'period_end'    => 'date',
            'placement'     => 'integer',
            'total_score'   => 'integer',
            'total_players' => 'integer',
            'xp_awarded'    => 'integer',
            'metadata'      => 'array',
            'awarded_at'    => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
