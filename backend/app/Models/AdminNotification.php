<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminNotification extends Model
{
    public const TARGET_ALL       = 'all';
    public const TARGET_OPTED_IN  = 'opted_in';

    public const STATUS_DRAFT  = 'draft';
    public const STATUS_SENT   = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'title',
        'body',
        'target_type',
        'status',
        'send_at',
        'sent_at',
        'created_by_user_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'send_at'  => 'datetime',
            'sent_at'  => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
