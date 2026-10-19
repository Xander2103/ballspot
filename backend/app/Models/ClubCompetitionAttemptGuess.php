<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A scored guess of a competition attempt. `submitted_at` is the server
 * receive time — standings count it only inside the competition window.
 */
class ClubCompetitionAttemptGuess extends Model
{
    use HasFactory;

    protected $fillable = [
        'attempt_id', 'challenge_id', 'position', 'guess_x', 'guess_y',
        'distance', 'score', 'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'position'     => 'integer',
            'guess_x'      => 'float',
            'guess_y'      => 'float',
            'distance'     => 'float',
            'score'        => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ClubCompetitionAttempt::class, 'attempt_id');
    }

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }
}
