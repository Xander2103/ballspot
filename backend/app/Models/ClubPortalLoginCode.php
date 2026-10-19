<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pending club-portal sign-in code (hash only). Managed exclusively by
 * App\Services\ClubPortal\ClubPortalLoginCodeService.
 */
class ClubPortalLoginCode extends Model
{
    protected $fillable = [
        'challenge_id', 'user_id', 'code_hash', 'sent_at', 'expires_at',
        'attempts', 'sends', 'ip_address', 'user_agent',
    ];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'sent_at'    => 'datetime',
            'expires_at' => 'datetime',
            'attempts'   => 'integer',
            'sends'      => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->lte(now());
    }

    public function isLocked(int $maxAttempts): bool
    {
        return $this->attempts >= $maxAttempts;
    }
}
