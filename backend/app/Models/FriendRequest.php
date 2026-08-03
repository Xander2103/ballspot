<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FriendRequest extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACCEPTED  = 'accepted';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['requester_id', 'recipient_id', 'status'];

    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requester_id'); }
    public function recipient(): BelongsTo { return $this->belongsTo(User::class, 'recipient_id'); }

    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
}
