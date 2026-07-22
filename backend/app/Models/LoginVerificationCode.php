<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginVerificationCode extends Model
{
    protected $fillable = [
        'verification_id', 'user_id', 'email', 'code_hash',
        'code_sent_at', 'expires_at', 'attempts', 'consumed_at',
        'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'code_sent_at' => 'datetime',
            'expires_at'   => 'datetime',
            'consumed_at'  => 'datetime',
            'attempts'     => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isLocked(int $maxAttempts): bool
    {
        return $this->attempts >= $maxAttempts;
    }

    /**
     * A code that can still be used: not consumed, not expired, not attempt-locked.
     */
    public function isUsable(int $maxAttempts): bool
    {
        return !$this->isConsumed() && !$this->isExpired() && !$this->isLocked($maxAttempts);
    }
}
