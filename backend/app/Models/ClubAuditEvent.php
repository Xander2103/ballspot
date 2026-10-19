<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only audit row (plan §4.7, §6.6). Write only through
 * App\Services\Clubs\ClubAuditLogger::log(), inside the transaction of the
 * change it records.
 */
class ClubAuditEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const ACTOR_PLATFORM_ADMIN = 'platform_admin';
    public const ACTOR_CLUB_ADMIN     = 'club_admin';
    public const ACTOR_MEMBER         = 'member';
    public const ACTOR_SYSTEM         = 'system';
    public const ACTOR_KINDS = [self::ACTOR_PLATFORM_ADMIN, self::ACTOR_CLUB_ADMIN, self::ACTOR_MEMBER, self::ACTOR_SYSTEM];

    /** Allow-listed actions (plan §6.6). Labels: lang/<locale>/clubs.php `audit.<action>`. */
    public const ACTIONS = [
        'club.created', 'club.updated', 'club.suspended', 'club.archived', 'club.reactivated', 'club.deleted',
        'club.settings_changed',
        'member.added', 'member.joined', 'member.left', 'member.removed', 'member.banned', 'member.unbanned',
        'member.role_changed', 'member.ownership_transferred',
        'code.created', 'code.revoked', 'code.regenerated',
        'pack.created', 'pack.updated', 'pack.archived', 'pack.linked', 'pack.unlinked',
        'competition.created', 'competition.updated', 'competition.published', 'competition.cancelled',
        'competition.rules_locked', 'competition.ends_at_extended', 'competition.voided', 'competition.finalized',
        'competition.rebuilt',
        'attempt.voided',
        'photo.uploaded', 'photo.assigned', 'photo.rejected', 'photo.converted',
    ];

    /**
     * Allow-listed metadata keys. Values are ids, counts, statuses, roles,
     * reasons and masked codes — never an email address or a full invite code.
     * `before`/`after` carry setting diffs (allow-listed setting keys only).
     */
    public const METADATA_KEYS = [
        'reason', 'before', 'after', 'fields', 'name', 'slug', 'status', 'from_status', 'to_status',
        'role', 'from_role', 'to_role', 'user_id', 'target_user_id', 'previous_owner_ids', 'new_owner_id',
        'code_masked', 'code_id', 'replaced_code_id', 'label', 'expires_at', 'max_uses', 'grants_role',
        'pack_id', 'competition_id', 'attempt_id', 'submission_id', 'challenge_id',
        'ends_at_from', 'ends_at_to', 'count', 'dry_run', 'diff', 'source', 'reactivated', 'replay_mode',
        'max_attempts', 'score_rule', 'normal_play_policy', 'placements', 'participants',
    ];

    protected $fillable = [
        'club_id', 'actor_user_id', 'actor_kind', 'action', 'subject_type',
        'subject_id', 'metadata', 'ip_hash', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata'   => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** Translated label for the action (falls back to the raw action). */
    public function label(): string
    {
        $key = 'clubs.audit.' . $this->action;

        return \Illuminate\Support\Facades\Lang::has($key) ? __($key) : $this->action;
    }
}
