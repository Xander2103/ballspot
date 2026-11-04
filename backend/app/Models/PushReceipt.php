<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An accepted Expo push ticket awaiting (or holding) its delivery receipt.
 * See the create_push_receipts_table migration for what is and is not stored.
 */
class PushReceipt extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_OK      = 'ok';
    public const STATUS_ERROR   = 'error';
    /** Expo keeps receipts for about a day; none came back in that time. */
    public const STATUS_EXPIRED = 'expired';

    public const SOURCE_ANNOUNCEMENT   = 'announcement';
    public const SOURCE_DAILY_REMINDER = 'daily_reminder';
    public const SOURCE_TEST           = 'test';

    protected $fillable = [
        'ticket_id',
        'push_token_id',
        'platform',
        'source',
        'status',
        'error',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
        ];
    }

    public function pushToken(): BelongsTo
    {
        return $this->belongsTo(PushToken::class);
    }
}
