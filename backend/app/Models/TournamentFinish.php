<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's final placement in a completed tournament. Written once, idempotently,
 * by TournamentCompletionService. Virtual recognition only.
 */
class TournamentFinish extends Model
{
    protected $fillable = [
        'league_id', 'user_id', 'placement', 'total_score', 'rounds_played', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'placement'     => 'integer',
            'total_score'   => 'integer',
            'rounds_played' => 'integer',
            'metadata'      => 'array',
        ];
    }

    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
