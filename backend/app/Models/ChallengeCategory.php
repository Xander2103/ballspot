<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChallengeCategory extends Model
{
    protected $fillable = ['sport_id', 'name', 'slug', 'description', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function sport(): BelongsTo { return $this->belongsTo(Sport::class); }
    public function challenges(): HasMany { return $this->hasMany(Challenge::class, 'challenge_category_id'); }
}
