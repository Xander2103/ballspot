<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Derived (rebuildable) standing of one player in a competition (§5.6).
 * Ranking: total_score desc → score_reached_at asc → attempts_used asc,
 * equal on all three = shared rank (D17).
 */
class ClubCompetitionEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'club_competition_id', 'user_id', 'total_score', 'packs_counted',
        'packs_completed', 'attempts_used', 'score_reached_at',
    ];

    protected function casts(): array
    {
        return [
            'total_score'      => 'integer',
            'packs_counted'    => 'integer',
            'packs_completed'  => 'integer',
            'attempts_used'    => 'integer',
            'score_reached_at' => 'datetime',
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

    /** Deterministic leaderboard order (rank factors first, then id). */
    public function scopeRanked(Builder $query): Builder
    {
        return $query->orderByDesc('total_score')
            ->orderBy('score_reached_at')
            ->orderBy('attempts_used')
            ->orderBy('id');
    }
}
