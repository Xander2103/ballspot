<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's report of club content (Wave F, plan §11.9): the club itself,
 * one of its competitions or another member. Moderated by platform admins on
 * /admin/club-reports. Write through App\Services\Clubs\ClubContentReportService.
 *
 * Reporter identity is platform-only: report audit events are hidden from
 * the club portal (ClubAuditEvent::PLATFORM_ONLY_ACTIONS).
 */
class ClubContentReport extends Model
{
    use HasFactory;

    public const SUBJECT_CLUB        = 'club';
    public const SUBJECT_COMPETITION = 'competition';
    public const SUBJECT_MEMBER      = 'member';
    public const SUBJECT_TYPES = [self::SUBJECT_CLUB, self::SUBJECT_COMPETITION, self::SUBJECT_MEMBER];

    public const REASONS = ['inappropriate', 'offensive', 'spam', 'privacy', 'other'];

    public const STATUS_OPEN      = 'open';
    public const STATUS_REVIEWED  = 'reviewed';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_ACTIONED  = 'actioned';
    public const STATUSES = [self::STATUS_OPEN, self::STATUS_REVIEWED, self::STATUS_DISMISSED, self::STATUS_ACTIONED];
    /** Statuses an admin can resolve a report to. */
    public const RESOLUTIONS = [self::STATUS_REVIEWED, self::STATUS_DISMISSED, self::STATUS_ACTIONED];

    protected $fillable = [
        'club_id', 'reporter_user_id', 'subject_type', 'subject_ref', 'reason', 'details',
        'status', 'admin_note', 'reviewed_by_user_id', 'reviewed_at',
    ];

    protected $hidden = ['admin_note'];

    protected $attributes = [
        'status' => self::STATUS_OPEN,
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
