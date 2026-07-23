<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ChallengePack extends Model
{
    public const STATUS_DRAFT    = 'draft';
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_ARCHIVED];

    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_HIDDEN = 'hidden';
    public const VISIBILITIES = [self::VISIBILITY_PUBLIC, self::VISIBILITY_HIDDEN];

    protected $fillable = [
        'sport_id', 'name', 'slug', 'description', 'cover_image_path',
        'status', 'visibility', 'difficulty', 'sort_order', 'is_featured',
    ];

    protected function casts(): array
    {
        return [
            'is_featured' => 'boolean',
            'sort_order'  => 'integer',
        ];
    }

    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }

    public function challenges(): BelongsToMany
    {
        return $this->belongsToMany(Challenge::class, 'challenge_pack_challenge')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderBy('challenge_pack_challenge.sort_order');
    }

    public function attempts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PackAttempt::class);
    }

    /** Ready challenges only — safe to surface to normal users. */
    public function readyChallenges()
    {
        return $this->challenges->filter(fn (Challenge $c) => $c->isReadyForDaily());
    }

    /** Public + active — visible to normal app users. */
    public function scopeVisibleToUsers(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where('visibility', self::VISIBILITY_PUBLIC);
    }

    public function coverImageUrl(): ?string
    {
        return $this->cover_image_path ? asset('storage/' . $this->cover_image_path) : null;
    }
}
