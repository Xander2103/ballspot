<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class League extends Model
{
    protected $fillable = ['name','join_code','owner_user_id','sport_id','duration_days','rounds_per_day','starts_at','ends_at','status'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_user_id'); }
    public function sport(): BelongsTo { return $this->belongsTo(Sport::class); }
    public function members(): BelongsToMany { return $this->belongsToMany(User::class, 'league_members')->withPivot('joined_at'); }
    public function rounds(): HasMany { return $this->hasMany(LeagueRound::class); }
}
