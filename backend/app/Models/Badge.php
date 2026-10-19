<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Badge extends Model
{
    protected $fillable = ['code', 'name', 'description', 'icon', 'category', 'rarity', 'sort_order', 'club_id'];

    /**
     * Global catalogue only. Per-pack trophies of club packs carry the club's
     * id and must never appear in /api/badges, /me/badges or a public profile
     * (Clubs phase-0 hardening G3).
     */
    public function scopeGlobal(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('club_id'));
    }

    /** Owning club for a club pack trophy; null = global badge. */
    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function userBadges(): HasMany
    {
        return $this->hasMany(UserBadge::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_badges')
            ->withPivot('earned_at', 'context')
            ->withTimestamps();
    }
}
