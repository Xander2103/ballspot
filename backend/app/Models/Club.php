<?php

namespace App\Models;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Enums\ClubStatus;
use App\Enums\ClubType;
use App\Enums\NormalPlayPolicy;
use App\Enums\ReplayMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A private BallPicker club (plan §4.2). Created by platform admins (D1).
 *
 * Identifiers: `id` is internal; the mobile API uses the ULID `public_id`
 * (bind member routes with `{club:public_id}`); admin URLs may use `slug`.
 * `contact_name` / `contact_email` are admin-only personal data and are
 * hidden from serialisation.
 */
class Club extends Model
{
    use HasFactory;

    /**
     * Allow-listed keys of the `settings` JSON with their defaults. Anything
     * else is ignored by ClubService::updateSettings().
     */
    public const SETTING_DEFAULTS = [
        // D9: ordinary members do not see the member list by default.
        'member_list_visibility' => 'admins', // admins | members
        // D12: leaderboards show usernames + avatars only.
        'leaderboard_identity'   => 'username',
        // null = config('ballspot.clubs.competition.default_replay_mode').
        'default_replay_mode'    => null,
        // D15 default for new competitions; null = config('ballspot.clubs.competition.default_normal_play_policy').
        'default_normal_play_policy' => null,
    ];

    public const MEMBER_LIST_VISIBILITIES = ['admins', 'members'];
    public const LEADERBOARD_IDENTITIES   = ['username'];

    protected $fillable = [
        'name', 'slug', 'description', 'logo_path', 'sport_id', 'club_type',
        'city', 'region', 'country_code', 'external_ref', 'contact_name',
        'contact_email', 'timezone', 'status', 'join_enabled', 'settings',
        'max_members', 'suspension_reason', 'archived_reason',
        'created_by_user_id', 'updated_by_user_id',
    ];

    protected $hidden = ['contact_name', 'contact_email'];

    protected $attributes = [
        'status'       => 'active',
        'club_type'    => 'sports_club',
        'join_enabled' => true,
        'needs_owner'  => false,
        'version'      => 1,
    ];

    protected function casts(): array
    {
        return [
            'status'                  => ClubStatus::class,
            'club_type'               => ClubType::class,
            'join_enabled'            => 'boolean',
            'needs_owner'             => 'boolean',
            'settings'                => 'array',
            'max_members'             => 'integer',
            'members_count'           => 'integer',
            'active_packs_count'      => 'integer',
            'live_competitions_count' => 'integer',
            'version'                 => 'integer',
            'last_activity_at'        => 'datetime',
            'suspended_at'            => 'datetime',
            'archived_at'             => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Club $club) {
            $club->public_id ??= (string) Str::ulid();
            $club->timezone  ??= (string) (config('ballspot.competition.timezone') ?: config('app.timezone', 'UTC'));
        });
    }

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------

    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }

    /** Every membership row, any status (history included). */
    public function members(): HasMany
    {
        return $this->hasMany(ClubMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->where('status', ClubMemberStatus::Active->value);
    }

    public function owners(): HasMany
    {
        return $this->activeMembers()->where('role', ClubRole::Owner->value);
    }

    /** Users with an ACTIVE membership. */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'club_members')
            ->withPivot('role', 'status', 'joined_at', 'last_seen_at')
            ->wherePivot('status', ClubMemberStatus::Active->value)
            ->withTimestamps();
    }

    public function inviteCodes(): HasMany
    {
        return $this->hasMany(ClubInviteCode::class);
    }

    public function packs(): HasMany
    {
        return $this->hasMany(ChallengePack::class);
    }

    public function challenges(): HasMany
    {
        return $this->hasMany(Challenge::class);
    }

    public function badges(): HasMany
    {
        return $this->hasMany(Badge::class);
    }

    public function competitions(): HasMany
    {
        return $this->hasMany(ClubCompetition::class);
    }

    public function photoSubmissions(): HasMany
    {
        return $this->hasMany(ClubPhotoSubmission::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(ClubAuditEvent::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ClubStatus::Active->value);
    }

    /** Clubs that show up in My Clubs: active, and suspended flagged read-only (D18). */
    public function scopeListedForMembers(Builder $query): Builder
    {
        return $query->whereIn('status', [ClubStatus::Active->value, ClubStatus::Suspended->value]);
    }

    public function scopeNeedsOwner(Builder $query): Builder
    {
        return $query->where('needs_owner', true)->where('status', '!=', ClubStatus::Archived->value);
    }

    // ------------------------------------------------------------------
    // State helpers
    // ------------------------------------------------------------------

    public function isActive(): bool
    {
        return $this->status === ClubStatus::Active;
    }

    public function isSuspended(): bool
    {
        return $this->status === ClubStatus::Suspended;
    }

    public function isArchived(): bool
    {
        return $this->status === ClubStatus::Archived;
    }

    /** A setting with its default (allow-listed keys only). */
    public function setting(string $key): mixed
    {
        if (!array_key_exists($key, self::SETTING_DEFAULTS)) {
            throw new \InvalidArgumentException("Unknown club setting [{$key}].");
        }

        $value = ($this->settings ?? [])[$key] ?? self::SETTING_DEFAULTS[$key];

        if ($key === 'default_replay_mode' && $value === null) {
            $value = (string) config('ballspot.clubs.competition.default_replay_mode', ReplayMode::SingleAttempt->value);
        }
        if ($key === 'default_normal_play_policy' && $value === null) {
            $value = (string) config('ballspot.clubs.competition.default_normal_play_policy', NormalPlayPolicy::Blocked->value);
        }

        return $value;
    }

    /** All settings merged over their defaults. */
    public function resolvedSettings(): array
    {
        $out = [];
        foreach (array_keys(self::SETTING_DEFAULTS) as $key) {
            $out[$key] = $this->setting($key);
        }

        return $out;
    }

    public function memberListVisibleToMembers(): bool
    {
        return $this->setting('member_list_visibility') === 'members';
    }

    /** Member cap: the club override, else ballspot.clubs.limits.max_members. */
    public function effectiveMaxMembers(): int
    {
        return (int) ($this->max_members ?: config('ballspot.clubs.limits.max_members', 500));
    }

    /** Public URL of the logo (public disk, random name — D24), or null. */
    public function logoUrl(): ?string
    {
        return $this->logo_path ? asset('storage/' . $this->logo_path) : null;
    }
}
