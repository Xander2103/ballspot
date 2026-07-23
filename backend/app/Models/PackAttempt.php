<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PackAttempt extends Model
{
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ABANDONED = 'abandoned';

    protected $fillable = [
        'user_id', 'challenge_pack_id', 'status',
        'started_at', 'completed_at', 'total_score', 'current_index', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'started_at'    => 'datetime',
            'completed_at'  => 'datetime',
            'total_score'   => 'integer',
            'current_index' => 'integer',
            'metadata'      => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(ChallengePack::class, 'challenge_pack_id');
    }

    public function guesses(): HasMany
    {
        return $this->hasMany(PackAttemptGuess::class);
    }

    /** Ordered challenge ids snapshotted at start. */
    public function challengeIds(): array
    {
        return $this->metadata['challenge_ids'] ?? [];
    }

    public function totalChallenges(): int
    {
        return count($this->challengeIds());
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
