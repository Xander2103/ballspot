<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeagueMember extends Model
{
    protected $fillable = ['league_id','user_id','joined_at'];
    protected function casts(): array { return ['joined_at' => 'datetime']; }
    public function league(): BelongsTo { return $this->belongsTo(League::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
