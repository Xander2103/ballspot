<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Sport extends Model
{
    /** Availability statuses. `status` is the source of truth; is_active mirrors it. */
    public const STATUS_ACTIVE      = 'active';       // visible + playable/selectable
    public const STATUS_COMING_SOON = 'coming_soon';  // visible, disabled ("Coming soon")
    public const STATUS_HIDDEN      = 'hidden';       // not shown to normal users

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_COMING_SOON, self::STATUS_HIDDEN];

    protected $fillable = [
        'name', 'slug', 'emoji', 'object_name',
        'primary_color', 'status', 'is_active', 'sort_order', 'scoring_profile',
    ];

    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Setting the status keeps the legacy is_active flag in sync so existing
     * queries (Sport::active(), preference/tournament validation) keep working.
     */
    public function setStatusAttribute(?string $value): void
    {
        $this->attributes['status'] = $value;
        $this->attributes['is_active'] = $value === self::STATUS_ACTIVE;
    }

    public function challenges(): HasMany { return $this->hasMany(Challenge::class); }
    public function leagues(): HasMany { return $this->hasMany(League::class); }
    public function categories(): HasMany { return $this->hasMany(ChallengeCategory::class); }

    public function isPlayable(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isComingSoon(): bool
    {
        return $this->status === self::STATUS_COMING_SOON;
    }

    public function isVisible(): bool
    {
        return $this->status !== self::STATUS_HIDDEN;
    }

    /** Active/playable sports. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /** Visible in the app for selection (active + coming_soon, not hidden). */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_HIDDEN);
    }
}
