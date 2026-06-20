<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeagueRound extends Model
{
    protected $fillable = ['league_id','challenge_id','round_number','opens_at','closes_at','status'];
    protected function casts(): array { return ['opens_at' => 'datetime', 'closes_at' => 'datetime']; }
    public function league(): BelongsTo { return $this->belongsTo(League::class); }
    public function challenge(): BelongsTo { return $this->belongsTo(Challenge::class); }
    public function guesses(): HasMany { return $this->hasMany(Guess::class); }
}
