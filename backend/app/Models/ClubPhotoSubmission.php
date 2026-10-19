<?php

namespace App\Models;

use App\Enums\PhotoSubmissionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo supplied for a club, stored on the private club_private disk and
 * reviewed/prepared by BallPicker into a club challenge (plan §4.7).
 */
class ClubPhotoSubmission extends Model
{
    use HasFactory;

    public const SOURCE_ADMIN_UPLOAD = 'admin_upload';
    public const SOURCE_CLUB_UPLOAD  = 'club_upload';
    public const SOURCES = [self::SOURCE_ADMIN_UPLOAD, self::SOURCE_CLUB_UPLOAD];

    protected $fillable = [
        'club_id', 'uploaded_by_user_id', 'source', 'disk', 'path', 'mime',
        'width', 'height', 'bytes', 'sha256', 'status', 'club_note',
        'admin_note', 'rejection_reason', 'challenge_id', 'assigned_to_user_id',
        'reviewed_by_user_id', 'reviewed_at', 'rights_confirmed_at',
    ];

    protected $hidden = ['disk', 'path', 'admin_note'];

    protected $attributes = [
        'status' => 'pending',
        'source' => self::SOURCE_ADMIN_UPLOAD,
    ];

    protected function casts(): array
    {
        return [
            'status'              => PhotoSubmissionStatus::class,
            'width'               => 'integer',
            'height'              => 'integer',
            'bytes'               => 'integer',
            'reviewed_at'         => 'datetime',
            'rights_confirmed_at' => 'datetime',
        ];
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /** Waiting for a reviewer (pending / in review) — the "Photo review" nav badge. */
    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PhotoSubmissionStatus::Pending->value,
            PhotoSubmissionStatus::InReview->value,
        ]);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [
            PhotoSubmissionStatus::Pending,
            PhotoSubmissionStatus::InReview,
            PhotoSubmissionStatus::InPreparation,
        ], true);
    }

    /** Still waiting for BallPicker (pending / in review / in preparation). */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PhotoSubmissionStatus::Pending->value,
            PhotoSubmissionStatus::InReview->value,
            PhotoSubmissionStatus::InPreparation->value,
        ]);
    }
}
