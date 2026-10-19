<?php

namespace App\Models;

use App\Enums\CompetitionAttemptStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One competition attempt of a player on a linked pack (plan §4.6, §5.3).
 * `active_slot` must be 1 while status = active and NULL otherwise — that
 * column is what the unique index uses to allow one active attempt only.
 */
class ClubCompetitionAttempt extends Model
{
    use HasFactory;

    public const ACTIVE_SLOT = 1;

    protected $fillable = [
        'club_competition_id', 'challenge_pack_id', 'user_id', 'attempt_number',
        'status', 'active_slot', 'challenge_ids', 'current_index', 'guesses_count',
        'total_score', 'is_rewarded_run', 'started_at', 'completed_at',
        'last_scored_at', 'voided_at', 'voided_by_user_id', 'void_reason',
    ];

    protected $attributes = [
        'status'          => 'active',
        'active_slot'     => self::ACTIVE_SLOT,
        'current_index'   => 0,
        'guesses_count'   => 0,
        'total_score'     => 0,
        'is_rewarded_run' => false,
    ];

    protected function casts(): array
    {
        return [
            'status'          => CompetitionAttemptStatus::class,
            'attempt_number'  => 'integer',
            'active_slot'     => 'integer',
            'challenge_ids'   => 'array',
            'current_index'   => 'integer',
            'guesses_count'   => 'integer',
            'total_score'     => 'integer',
            'is_rewarded_run' => 'boolean',
            'started_at'      => 'datetime',
            'completed_at'    => 'datetime',
            'last_scored_at'  => 'datetime',
            'voided_at'       => 'datetime',
        ];
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(ClubCompetition::class, 'club_competition_id');
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(ChallengePack::class, 'challenge_pack_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function guesses(): HasMany
    {
        return $this->hasMany(ClubCompetitionAttemptGuess::class, 'attempt_id')->orderBy('position');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    public function scopeNotVoided(Builder $query): Builder
    {
        return $query->where('status', '!=', CompetitionAttemptStatus::Voided->value);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CompetitionAttemptStatus::Active->value);
    }

    /** @return list<int> */
    public function challengeIds(): array
    {
        return array_values(array_map('intval', $this->challenge_ids ?? []));
    }

    public function totalChallenges(): int
    {
        return count($this->challengeIds());
    }

    public function isActive(): bool
    {
        return $this->status === CompetitionAttemptStatus::Active;
    }

    public function isVoided(): bool
    {
        return $this->status === CompetitionAttemptStatus::Voided;
    }

    /**
     * Leave the active state: sets the status and releases the active slot
     * (NULL), so a next attempt can start. Does not save.
     */
    public function markInactive(CompetitionAttemptStatus $status): static
    {
        if ($status === CompetitionAttemptStatus::Active) {
            throw new \InvalidArgumentException('Use a non-active status.');
        }
        $this->status      = $status;
        $this->active_slot = null;

        return $this;
    }
}
