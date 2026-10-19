<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The single global-reward run of a player on a club pack (D3, §5.8).
 * Insert-only; the unique (user_id, challenge_pack_id) index settles races.
 * Written by ClubRewardService (Member API agent).
 */
class ClubPackRewardClaim extends Model
{
    use HasFactory;

    public const SOURCE_PACK_ATTEMPT        = 'pack_attempt';
    public const SOURCE_COMPETITION_ATTEMPT = 'competition_attempt';
    public const SOURCES = [self::SOURCE_PACK_ATTEMPT, self::SOURCE_COMPETITION_ATTEMPT];

    protected $fillable = ['user_id', 'challenge_pack_id', 'source_type', 'source_id', 'claimed_at'];

    protected function casts(): array
    {
        return [
            'source_id'  => 'integer',
            'claimed_at' => 'datetime',
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
}
