<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Badge extends Model
{
    protected $fillable = ['code', 'name', 'description', 'icon', 'category', 'rarity', 'sort_order'];

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
