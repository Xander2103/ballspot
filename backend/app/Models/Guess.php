<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Guess extends Model
{
    protected $fillable = ['league_round_id','user_id','guess_x_ratio','guess_y_ratio','distance','score','submitted_at'];
    protected function casts(): array { return ['submitted_at' => 'datetime', 'guess_x_ratio' => 'float', 'guess_y_ratio' => 'float', 'distance' => 'float']; }
    public function round(): BelongsTo { return $this->belongsTo(LeagueRound::class, 'league_round_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
