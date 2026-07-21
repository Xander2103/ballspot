<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    protected $fillable = ['name', 'slug', 'type'];

    public function challenges(): BelongsToMany
    {
        return $this->belongsToMany(Challenge::class, 'challenge_tag');
    }

    /**
     * Find or create a text tag by name. Text tags only — never store
     * copyrighted logos or imagery.
     */
    public static function findOrCreateByName(string $name, ?string $type = null): self
    {
        $name = trim($name);
        $slug = Str::slug($name);

        return static::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'type' => $type],
        );
    }
}
