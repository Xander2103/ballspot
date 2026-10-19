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
        'completion_badge_id', 'club_id',
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

    /** Owning club; null = BallPicker (public catalogue) pack. */
    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    /** Competitions this pack is linked to. */
    public function competitions(): BelongsToMany
    {
        return $this->belongsToMany(ClubCompetition::class, 'club_competition_packs')
            ->withPivot('sort_order', 'challenge_ids', 'snapshotted_at')
            ->withTimestamps();
    }

    public function isClubPack(): bool
    {
        return $this->club_id !== null;
    }

    /** Optional trophy awarded on first completion of this pack. */
    public function completionBadge(): BelongsTo
    {
        return $this->belongsTo(Badge::class, 'completion_badge_id');
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

    /**
     * Public + active + BallPicker-owned — visible to normal app users on the
     * public pack endpoints. Club packs (club_id set) are NEVER part of it
     * (Clubs phase-0 hardening G2): they are only reachable through the
     * member-only club routes.
     */
    public function scopeVisibleToUsers(Builder $query): Builder
    {
        return $query->whereNull('club_id')
            ->where('status', self::STATUS_ACTIVE)
            ->where('visibility', self::VISIBILITY_PUBLIC);
    }

    /** Explicit name for what GET /api/packs lists (same as visibleToUsers). */
    public function scopePublicCatalog(Builder $query): Builder
    {
        return $query->visibleToUsers();
    }

    /** BallPicker-owned packs in any status (Trophy Room history, admin default scope). */
    public function scopeWithoutClub(Builder $query): Builder
    {
        return $query->whereNull('club_id');
    }

    /** Packs of one club, any status. */
    public function scopeForClub(Builder $query, Club|int $club): Builder
    {
        return $query->where('club_id', $club instanceof Club ? $club->id : $club);
    }

    public function coverImageUrl(): ?string
    {
        return $this->cover_image_path ? asset('storage/' . $this->cover_image_path) : null;
    }
}
