<?php

namespace App\Models;

use App\Services\ImageVariantService;
use App\Support\AppLog;
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
        'completion_badge_id',
    ];

    protected function casts(): array
    {
        return [
            'is_featured' => 'boolean',
            'sort_order'  => 'integer',
            'cover_image_variant' => 'array',
        ];
    }

    protected $hidden = ['cover_image_variant'];

    protected static function booted(): void
    {
        // Same lifecycle as Challenge::image_variants: built after an upload,
        // never fails the save, removed with the pack.
        static::created(fn (ChallengePack $p) => $p->refreshCoverVariantAfterUpload());
        static::updated(function (ChallengePack $p) {
            if ($p->wasChanged('cover_image_path')) {
                $p->refreshCoverVariantAfterUpload();
            }
        });
        static::deleted(fn (ChallengePack $p) => $p->deleteCoverVariantUnlessShared($p->cover_image_variant));
    }

    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
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

    /** Public + active — visible to normal app users. */
    public function scopeVisibleToUsers(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where('visibility', self::VISIBILITY_PUBLIC);
    }

    public function coverImageUrl(): ?string
    {
        $variant = config('ballspot.images.serve_variants', true)
            && Challenge::variantMatches($this->cover_image_variant, $this->cover_image_path)
            ? $this->cover_image_variant : null;

        return Challenge::storageUrl($this->cover_image_path, $variant);
    }

    /** @return string created|original|kept|failed|none */
    public function refreshCoverVariant(bool $force = false): string
    {
        $service = app(ImageVariantService::class);
        $maxEdge = (int) config('ballspot.images.cover_max_edge', 1200);
        $current = $this->cover_image_variant;
        $source = $this->cover_image_path;

        if (! $force && $source && Challenge::variantMatches($current, $source, $maxEdge)) {
            return 'kept';
        }
        $meta = $source ? $service->generate($source, dirname($source) . '/display', $maxEdge) : null;
        if (is_array($current) && ($current['path'] ?? null) !== ($meta['path'] ?? null)) {
            $this->deleteCoverVariantUnlessShared($current);
        }

        static::whereKey($this->getKey())->toBase()->update(['cover_image_variant' => $meta === null ? null : json_encode($meta)]);
        $this->setAttribute('cover_image_variant', $meta);
        $this->syncOriginalAttribute('cover_image_variant');

        return ! $source ? 'none' : ($meta === null ? 'failed' : ($meta['path'] ? 'created' : 'original'));
    }

    private function deleteCoverVariantUnlessShared(?array $meta): void
    {
        $source = $meta['source'] ?? null;
        if (! is_array($meta) || ! is_string($source) || $source === '') {
            return;
        }
        if (! static::query()->whereKeyNot($this->getKey())->where('cover_image_path', $source)->exists()) {
            app(ImageVariantService::class)->deleteVariant($meta);
        }
    }

    private function refreshCoverVariantAfterUpload(): void
    {
        if (! config('ballspot.images.generate_on_upload', true)) {
            return;
        }
        try {
            $this->refreshCoverVariant();
        } catch (\Throwable $e) {
            AppLog::warn('images.variant_failed', ['pack_id' => $this->id, 'error' => class_basename($e)]);
        }
    }
}
