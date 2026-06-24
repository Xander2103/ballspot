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

    protected $casts = ['submitted_at' => 'datetime'];

    public function dailyChallenge(): BelongsTo
    {
        return $this->belongsTo(DailyChallenge::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
