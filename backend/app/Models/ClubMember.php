<?php

namespace App\Models;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One (club, user) membership row, kept for history (plan §4.3). Mutate it
 * only through App\Services\Clubs\ClubMembershipService so counters, the
 * sole-owner rule and the audit trail stay consistent.
 */
class ClubMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'club_id', 'user_id', 'role', 'status', 'joined_at', 'left_at',
        'invite_code_id', 'added_by_user_id', 'removed_by_user_id',
        'removal_reason', 'last_seen_at',
    ];

    protected $hidden = ['removal_reason'];

    protected $attributes = [
        'role'   => 'member',
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'role'         => ClubRole::class,
            'status'       => ClubMemberStatus::class,
            'joined_at'    => 'datetime',
            'left_at'      => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inviteCode(): BelongsTo
    {
        return $this->belongsTo(ClubInviteCode::class, 'invite_code_id');
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ClubMemberStatus::Active->value);
    }

    public function scopeWithRole(Builder $query, ClubRole ...$roles): Builder
    {
        return $query->whereIn('role', array_map(fn (ClubRole $r) => $r->value, $roles));
    }

    public function isActive(): bool
    {
        return $this->status === ClubMemberStatus::Active;
    }

    public function isBanned(): bool
    {
        return $this->status === ClubMemberStatus::Banned;
    }

    public function isOwner(): bool
    {
        return $this->isActive() && $this->role === ClubRole::Owner;
    }

    /** Active owner or admin of the club. */
    public function canManage(): bool
    {
        return $this->isActive() && $this->role->canManage();
    }
}
