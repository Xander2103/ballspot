<?php

namespace App\Models;

use App\Enums\ClubRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Invite / access code of a club (plan §4.4). `code` is stored normalised
 * (see ClubInviteCodeService::normalise()). Never log or audit it in full —
 * use masked().
 */
class ClubInviteCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'club_id', 'code', 'label', 'grants_role', 'max_uses', 'uses_count',
        'expires_at', 'revoked_at', 'created_by_user_id', 'revoked_by_user_id',
    ];

    protected $hidden = ['code'];

    protected $attributes = [
        'grants_role' => 'member',
        'uses_count'  => 0,
    ];

    protected function casts(): array
    {
        return [
            'grants_role' => ClubRole::class,
            'max_uses'    => 'integer',
            'uses_count'  => 'integer',
            'expires_at'  => 'datetime',
            'revoked_at'  => 'datetime',
        ];
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    /** Memberships that were created/reactivated with this code. */
    public function members(): HasMany
    {
        return $this->hasMany(ClubMember::class, 'invite_code_id');
    }

    /** Not revoked, not expired, not exhausted (club status is checked by the service). */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn (Builder $q) => $q->whereNull('max_uses')->orWhereColumn('uses_count', '<', 'max_uses'));
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lte(now());
    }

    public function isExhausted(): bool
    {
        return $this->max_uses !== null && $this->uses_count >= $this->max_uses;
    }

    public function isUsable(): bool
    {
        return !$this->isRevoked() && !$this->isExpired() && !$this->isExhausted();
    }

    /** Display form ABCD-EFGH. */
    public function formatted(): string
    {
        return implode('-', str_split((string) $this->code, 4));
    }

    /** Safe for logs/audit: ABCD-••••. */
    public function masked(): string
    {
        $code = (string) $this->code;

        return substr($code, 0, 4) . '-' . str_repeat('•', max(4, strlen($code) - 4));
    }
}
