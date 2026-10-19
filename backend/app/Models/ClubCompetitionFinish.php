<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Final placement of a finalized club competition — club-only trophy, no global XP (D3). */
class ClubCompetitionFinish extends Model
{
    use HasFactory;

    protected $fillable = [
        'club_competition_id', 'user_id', 'placement', 'total_score',
        'total_players', 'metadata', 'awarded_at',
    ];

    protected function casts(): array
    {
        return [
            'placement'     => 'integer',
            'total_score'   => 'integer',
            'total_players' => 'integer',
            'metadata'      => 'array',
            'awarded_at'    => 'datetime',
        ];
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(ClubCompetition::class, 'club_competition_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
