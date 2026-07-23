<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyChallengeGuess extends Model
{
    protected $fillable = [
        'daily_challenge_id',
        'user_id',
        'guess_x_ratio',
        'guess_y_ratio',
        'distance',
        'score',
        'submitted_at',
    ];

    // Cast the coordinate/distance decimals to float so the API serializes them
    // as numbers, not strings — otherwise the app's Number.isFinite() guards fail
    // and the daily result hides the distance, feedback and "your guess" marker.
    // Mirrors the tournament Guess model.
    protected $casts = [
        'submitted_at'  => 'datetime',
        'guess_x_ratio' => 'float',
        'guess_y_ratio' => 'float',
        'distance'      => 'float',
    ];

    public function dailyChallenge(): BelongsTo
    {
        return $this->belongsTo(DailyChallenge::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
