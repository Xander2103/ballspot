<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackAttemptGuess extends Model
{
    protected $fillable = [
        'pack_attempt_id', 'challenge_id', 'score',
        'guessed_x', 'guessed_y', 'distance', 'result',
    ];

    protected function casts(): array
    {
        return [
            'score'     => 'integer',
            'guessed_x' => 'float',
            'guessed_y' => 'float',
            'distance'  => 'float',
            'result'    => 'array',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PackAttempt::class, 'pack_attempt_id');
    }

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }
}
