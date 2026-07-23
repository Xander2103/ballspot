<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ChallengeSubcategory extends Model
{
    public const TYPES = [
        'team', 'country', 'league', 'club',
        'difficulty', 'moment_type', 'player_type', 'custom',
    ];

    protected $fillable = [
        'sport_id', 'name', 'slug', 'type',
        'description', 'color', 'icon', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }

    public function challenges(): BelongsToMany
    {
        return $this->belongsToMany(Challenge::class, 'challenge_subcategory')->withTimestamps();
    }

    /** Active subcategories only — what normal app filters should surface. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
