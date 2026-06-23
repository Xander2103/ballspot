<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sport extends Model
{
    protected $fillable = ['name', 'slug'];

    public function challenges(): HasMany { return $this->hasMany(Challenge::class); }
    public function leagues(): HasMany { return $this->hasMany(League::class); }
    public function categories(): HasMany { return $this->hasMany(ChallengeCategory::class); }
}
