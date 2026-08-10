<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationSetting extends Model
{
    protected $fillable = [
        'user_id',
        'daily_reminder_enabled',
        'tournament_reminder_enabled',
        'admin_notifications_enabled',
        'reminder_time',
        'timezone',
        'last_daily_reminder_date',
    ];

    protected function casts(): array
    {
        return [
            'daily_reminder_enabled'      => 'boolean',
            'tournament_reminder_enabled' => 'boolean',
            'admin_notifications_enabled' => 'boolean',
            'last_daily_reminder_date'    => 'date:Y-m-d',
        ];
    }

    /** Sensible defaults used when creating a settings row lazily. */
    public static function defaults(): array
    {
        return [
            'daily_reminder_enabled'      => true,
            'tournament_reminder_enabled' => true,
            'admin_notifications_enabled' => true,
            'reminder_time'               => config('ballspot.notifications.default_reminder_time', '19:00'),
            'timezone'                    => null,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
