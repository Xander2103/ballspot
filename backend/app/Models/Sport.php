<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Sport extends Model
{
    protected $fillable = [
        'name', 'slug', 'emoji', 'object_name',
        'primary_color', 'is_active', 'sort_order', 'scoring_profile',
    ];

    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function challenges(): HasMany { return $this->hasMany(Challenge::class); }
    public function leagues(): HasMany { return $this->hasMany(League::class); }
    public function categories(): HasMany { return $this->hasMany(ChallengeCategory::class); }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
