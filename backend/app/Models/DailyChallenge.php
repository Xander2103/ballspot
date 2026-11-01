<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class DailyChallenge extends Model
{
    protected $fillable = ['challenge_id', 'challenge_date', 'status'];

    protected $casts = ['challenge_date' => 'date:Y-m-d'];

    protected static function booted(): void
    {
        // Guesses point at this row: re-pointing or re-dating it would move them
        // to another photo or another competition period.
        static::updating(function (DailyChallenge $daily) {
            if ($daily->isDirty(['challenge_id', 'challenge_date']) && $daily->guesses()->exists()) {
                throw new \LogicException("Daily challenge {$daily->id} has guesses; its challenge and date are locked.");
            }
        });
    }

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }

    public function guesses(): HasMany
    {
        return $this->hasMany(DailyChallengeGuess::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('challenge_date', $date);
    }
}
