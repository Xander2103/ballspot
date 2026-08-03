<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One direction of a friendship. Accepting a request writes both directions. */
class Friendship extends Model
{
    protected $fillable = ['user_id', 'friend_id'];

    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function friend(): BelongsTo { return $this->belongsTo(User::class, 'friend_id'); }
}
