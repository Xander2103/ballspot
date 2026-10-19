<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Derived (rebuildable) result of one player on one competition pack (§5.5). */
class ClubCompetitionPackResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'club_competition_id', 'user_id', 'challenge_pack_id', 'attempts_used',
        'counted_attempt_id', 'counted_score', 'best_score', 'first_score',
        'completed', 'score_reached_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts_used'    => 'integer',
            'counted_score'    => 'integer',
            'best_score'       => 'integer',
            'first_score'      => 'integer',
            'completed'        => 'boolean',
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

    public function pack(): BelongsTo
    {
        return $this->belongsTo(ChallengePack::class, 'challenge_pack_id');
    }

    public function countedAttempt(): BelongsTo
    {
        return $this->belongsTo(ClubCompetitionAttempt::class, 'counted_attempt_id');
    }
}
