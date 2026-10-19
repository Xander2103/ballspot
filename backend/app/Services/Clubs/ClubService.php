<?php

namespace App\Services\Clubs;

use App\Enums\ClubRole;
use App\Enums\ClubStatus;
use App\Enums\ReplayMode;
use App\Exceptions\ClubDomainException;
use App\Models\Badge;
use App\Models\ChallengePack;
use App\Models\Challenge;
use App\Models\Club;
use App\Models\ClubAuditEvent;
use App\Models\ClubMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Club lifecycle (plan §4.2, contracts §4): create, update (optimistic
 * version), suspend / archive / reactivate, settings, delete-if-empty.
 *
 * Input arrives validated by FormRequests; this service enforces the
 * domain rules (status machine, stale versions, history) and writes the
 * audit event inside each transaction.
 */
class ClubService
{
    /** Identity / location / contact fields editable through update(). */
    public const EDITABLE_FIELDS = [
        'name', 'slug', 'description', 'sport_id', 'club_type', 'city', 'region',
        'country_code', 'external_ref', 'contact_name', 'contact_email', 'timezone',
    ];

    /** Fields never echoed into audit metadata (personal data). */
    private const PRIVATE_FIELDS = ['contact_name', 'contact_email'];

    public function __construct(
        private ClubAuditLogger $audit,
        private ClubMembershipService $memberships,
    ) {}

    /**
     * Create a club. Optional `owner_user_id` becomes the first owner;
     * without one the club is flagged needs_owner until an owner is added.
     */
    public function create(array $data, User $actor): Club
    {
        return DB::transaction(function () use ($data, $actor) {
            $attrs = array_intersect_key($data, array_flip(array_merge(self::EDITABLE_FIELDS, ['join_enabled', 'max_members'])));
            $attrs['slug'] = $this->uniqueSlug((string) ($attrs['slug'] ?? '') ?: (string) $attrs['name']);
            if (isset($data['settings']) && is_array($data['settings'])) {
                $attrs['settings'] = $this->cleanSettings($data['settings']);
            }

            $club = new Club($attrs);
            $club->forceFill([
                'status'             => ClubStatus::Active,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
                'needs_owner'        => true,
                'version'            => 1,
            ])->save();

            $this->audit->log($club, 'club.created', $actor, $club, [
                'name' => $club->name,
                'slug' => $club->slug,
            ]);

            if (!empty($data['owner_user_id'])) {
                $owner = User::findOrFail((int) $data['owner_user_id']);
                $this->memberships->add($club, $owner, ClubRole::Owner, $actor, ClubAuditEvent::ACTOR_PLATFORM_ADMIN);
            } else {
                $this->memberships->refreshCounters($club);
            }

            return $club->refresh();
        });
    }

    /** Edit identity/location/contact. A different version than expected → stale_version (409). */
    public function update(Club $club, array $data, User $actor, int $expectedVersion): Club
    {
        return DB::transaction(function () use ($club, $data, $actor, $expectedVersion) {
            $locked = $this->lock($club);

            if ((int) $locked->version !== $expectedVersion) {
                throw new ClubDomainException(ClubDomainException::STALE_VERSION, 409);
            }

            $attrs = array_intersect_key($data, array_flip(self::EDITABLE_FIELDS));
            if (array_key_exists('slug', $attrs) && $attrs['slug'] !== $locked->slug) {
                $attrs['slug'] = $this->uniqueSlug((string) $attrs['slug'] ?: (string) ($attrs['name'] ?? $locked->name), $locked->id);
            }

            $locked->fill($attrs);
            $changed = array_keys($locked->getDirty());

            if ($changed === []) {
                return $locked;
            }

            $locked->forceFill([
                'version'            => $locked->version + 1,
                'updated_by_user_id' => $actor->id,
            ])->save();

            $this->audit->log($locked, 'club.updated', $actor, $locked, [
                'fields' => array_values($changed),
                // Values only for non-personal fields.
                'after'  => array_diff_key(array_intersect_key($locked->getAttributes(), array_flip($changed)), array_flip(self::PRIVATE_FIELDS)),
            ]);

            return $locked;
        });
    }

    public function suspend(Club $club, User $actor, string $reason): Club
    {
        return $this->transition($club, ClubStatus::Suspended, [ClubStatus::Active], 'club.suspended', $actor, $reason, [
            'suspended_at'      => now(),
            'suspension_reason' => mb_substr($reason, 0, 200),
        ]);
    }

    public function archive(Club $club, User $actor, string $reason): Club
    {
        return $this->transition($club, ClubStatus::Archived, [ClubStatus::Active, ClubStatus::Suspended], 'club.archived', $actor, $reason, [
            'archived_at'     => now(),
            'archived_reason' => mb_substr($reason, 0, 200),
        ]);
    }

    public function reactivate(Club $club, User $actor): Club
    {
        return $this->transition($club, ClubStatus::Active, [ClubStatus::Suspended, ClubStatus::Archived], 'club.reactivated', $actor, null, [
            'suspended_at'      => null,
            'suspension_reason' => null,
            'archived_at'       => null,
            'archived_reason'   => null,
        ]);
    }

    /**
     * Hard delete — only a club created by mistake: no packs, challenges,
     * badges, competitions or photo submissions, and nobody ever joined
     * (owners/admins assigned by a platform admin do not count). Otherwise
     * `club_has_history`; archive instead. The `club.deleted` audit row
     * survives with club_id = NULL.
     */
    public function deleteIfEmpty(Club $club, User $actor): void
    {
        DB::transaction(function () use ($club, $actor) {
            $locked = $this->lock($club);

            if ($this->hasHistory($locked)) {
                throw new ClubDomainException(ClubDomainException::CLUB_HAS_HISTORY, 422);
            }

            $this->audit->log($locked, 'club.deleted', $actor, null, [
                'name' => $locked->name,
                'slug' => $locked->slug,
            ]);

            $logo = $locked->logo_path;
            $locked->delete();

            if ($logo && str_starts_with($logo, ClubMediaService::LOGO_DIRECTORY . '/')) {
                DB::afterCommit(fn () => Storage::disk('public')->delete($logo));
            }
        });
    }

    public function hasHistory(Club $club): bool
    {
        return ChallengePack::where('club_id', $club->id)->exists()
            || Challenge::where('club_id', $club->id)->exists()
            || Badge::where('club_id', $club->id)->exists()
            || $club->competitions()->exists()
            || $club->photoSubmissions()->exists()
            || ClubMember::where('club_id', $club->id)
                ->where(fn ($q) => $q->whereNotNull('invite_code_id')->orWhere('role', ClubRole::Member->value)->orWhere('status', '!=', 'active'))
                ->exists();
    }

    /**
     * Settings page (plan §8.3): join_enabled, max_members, timezone columns
     * plus the allow-listed `settings` JSON keys (Club::SETTING_DEFAULTS).
     * Unknown keys are ignored; invalid values throw InvalidArgumentException
     * (the FormRequest validates first). One `club.settings_changed` event
     * with before/after of the changed keys.
     */
    public function updateSettings(Club $club, array $settings, User $actor, string $actorKind): Club
    {
        return DB::transaction(function () use ($club, $settings, $actor, $actorKind) {
            $locked = $this->lock($club);

            $before = $this->settingsSnapshot($locked);

            foreach (['join_enabled', 'max_members', 'timezone'] as $column) {
                if (array_key_exists($column, $settings)) {
                    $locked->{$column} = match ($column) {
                        'join_enabled' => (bool) $settings[$column],
                        'max_members'  => $settings[$column] === null || $settings[$column] === '' ? null : max(1, (int) $settings[$column]),
                        'timezone'     => $this->validTimezone((string) $settings[$column]),
                    };
                }
            }

            $json = array_intersect_key($settings, Club::SETTING_DEFAULTS);
            if ($json !== []) {
                $locked->settings = array_merge($locked->settings ?? [], $this->cleanSettings($json));
            }

            $after = $this->settingsSnapshot($locked);
            $changedKeys = array_keys(array_filter($after, fn ($v, $k) => $before[$k] !== $v, ARRAY_FILTER_USE_BOTH));

            if ($changedKeys === []) {
                return $locked;
            }

            $locked->forceFill(['updated_by_user_id' => $actor->id])->save();

            $this->audit->log($locked, 'club.settings_changed', $actor, $locked, [
                'before' => array_intersect_key($before, array_flip($changedKeys)),
                'after'  => array_intersect_key($after, array_flip($changedKeys)),
            ], $actorKind);

            return $locked;
        });
    }

    // ------------------------------------------------------------------

    private function transition(Club $club, ClubStatus $to, array $from, string $action, User $actor, ?string $reason, array $attrs): Club
    {
        return DB::transaction(function () use ($club, $to, $from, $action, $actor, $reason, $attrs) {
            $locked = $this->lock($club);

            if ($locked->status === $to) {
                return $locked; // idempotent
            }
            if (!in_array($locked->status, $from, true)) {
                throw new ClubDomainException(ClubDomainException::CLUB_STATUS_INVALID, 422);
            }

            $fromStatus = $locked->status;
            $locked->forceFill($attrs + [
                'status'             => $to,
                'updated_by_user_id' => $actor->id,
            ])->save();

            $this->audit->log($locked, $action, $actor, $locked, array_filter([
                'from_status' => $fromStatus,
                'to_status'   => $to,
                'reason'      => $reason,
            ], fn ($v) => $v !== null));

            $this->memberships->refreshCounters($locked);

            return $locked;
        });
    }

    private function lock(Club $club): Club
    {
        $locked = Club::whereKey($club->id)->lockForUpdate()->firstOrFail();
        $club->setRawAttributes($locked->getAttributes(), true);

        // Work on the caller's instance so it reflects the result.
        return $club;
    }

    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::limit(Str::slug($source) ?: 'club', 90, '');
        $slug = $base;
        for ($i = 2; Club::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $i++) {
            $slug = $base . '-' . $i;
        }

        return $slug;
    }

    private function cleanSettings(array $settings): array
    {
        $out = [];
        foreach (array_intersect_key($settings, Club::SETTING_DEFAULTS) as $key => $value) {
            $valid = match ($key) {
                'member_list_visibility' => in_array($value, Club::MEMBER_LIST_VISIBILITIES, true),
                'leaderboard_identity'   => in_array($value, Club::LEADERBOARD_IDENTITIES, true),
                'default_replay_mode'    => $value === null || ReplayMode::tryFrom((string) $value) !== null,
                default                  => false,
            };
            if (!$valid) {
                throw new \InvalidArgumentException("Invalid value for club setting [{$key}].");
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private function settingsSnapshot(Club $club): array
    {
        return [
            'join_enabled' => (bool) $club->join_enabled,
            'max_members'  => $club->max_members === null ? null : (int) $club->max_members,
            'timezone'     => (string) $club->timezone,
        ] + $club->resolvedSettings();
    }

    private function validTimezone(string $tz): string
    {
        if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException('Invalid timezone.');
        }

        return $tz;
    }
}
