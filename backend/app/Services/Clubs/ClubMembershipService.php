<?php

namespace App\Services\Clubs;

use App\Enums\ClubMemberStatus;
use App\Enums\ClubRole;
use App\Enums\ClubStatus;
use App\Enums\CompetitionState;
use App\Exceptions\ClubDomainException;
use App\Models\ChallengePack;
use App\Models\Club;
use App\Models\ClubAuditEvent;
use App\Models\ClubInviteCode;
use App\Models\ClubMember;
use App\Models\User;
use App\Support\AppLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Club memberships: join with a code, leave, admin add/remove/ban/unban,
 * role changes and ownership transfer (plan §4.3, contracts §4).
 *
 * Every mutation runs in a transaction that locks the user row first and the
 * target row(s) second (the LeagueService pattern). Row locks are a no-op on
 * SQLite, so the unique (club_id, user_id) index is the real guard against a
 * double join. Each mutation writes its audit event inside the transaction
 * and keeps the club counters (members_count, needs_owner) in step.
 *
 * Invariant (§4.8.5): a non-archived club keeps ≥ 1 active owner — demoting,
 * removing or banning the last one is refused (`club_last_owner`), and a sole
 * owner cannot leave (`club_owner_must_transfer`). When the last owner
 * disappears anyway (account deletion, D6) the club is flagged needs_owner
 * and stays manageable by platform admins.
 */
class ClubMembershipService
{
    public function __construct(
        private ClubAuditLogger $audit,
        private ClubInviteCodeService $codes,
        private ClubJoinFailureCounter $failures,
        private ClubAccessContext $context,
    ) {}

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /**
     * The user's ACTIVE membership of the club, or null (left / removed /
     * banned / never joined). Memoised per request (ClubAccessContext).
     */
    public function membership(Club $club, User $user): ?ClubMember
    {
        if ($this->context->has($club->id, $user->id)) {
            return $this->context->get($club->id, $user->id);
        }

        $member = ClubMember::where('club_id', $club->id)
            ->where('user_id', $user->id)
            ->where('status', ClubMemberStatus::Active->value)
            ->first();

        $member?->setRelation('club', $club);
        $this->context->put($club->id, $user->id, $member);

        return $member;
    }

    /** The membership row in any status (history), not memoised. */
    public function membershipRecord(Club $club, User $user): ?ClubMember
    {
        return ClubMember::where('club_id', $club->id)->where('user_id', $user->id)->first();
    }

    // ------------------------------------------------------------------
    // Member actions
    // ------------------------------------------------------------------

    /**
     * Join (or re-join) with an invite code.
     *
     * Invalid / revoked / expired / exhausted codes, an inactive club,
     * joining disabled and a banned user all answer the SAME
     * `club_code_invalid` (no signal, D23) and count as a failure; at the
     * failure threshold `club_join_locked`. An active member gets
     * `joined: false` and the code's uses_count is untouched.
     *
     * @return array{club: Club, member: ClubMember, joined: bool}
     */
    public function joinWithCode(User $user, string $rawCode, ?string $ip): array
    {
        $this->failures->assertNotLocked($user, $ip);

        $normalised = $this->codes->normalise($rawCode);

        $outcome = DB::transaction(function () use ($user, $normalised) {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();

            $code   = $this->codes->find($normalised);
            $reason = $this->codes->unusableReason($code);
            if ($reason !== null) {
                return ['failed' => $reason];
            }

            /** @var ClubInviteCode $code */
            $club = Club::whereKey($code->club_id)->lockForUpdate()->firstOrFail();
            $code->setRelation('club', $club);

            $existing = ClubMember::where('club_id', $club->id)->where('user_id', $user->id)->lockForUpdate()->first();

            if ($existing?->isBanned()) {
                return ['failed' => 'banned'];
            }

            if ($existing?->isActive()) {
                $existing->forceFill(['last_seen_at' => now()])->save();

                return ['club' => $club, 'member' => $existing, 'joined' => false];
            }

            $this->assertCapacity($club, $user);

            $member = $this->activate($club, $user, $existing, $code->grants_role, [
                'invite_code_id'     => $code->id,
                'added_by_user_id'   => null,
            ]);
            if ($member === null) {
                // Lost a race with a concurrent join of the same user: the
                // unique index kept one row — answer like an existing member.
                $winner = ClubMember::where('club_id', $club->id)->where('user_id', $user->id)->firstOrFail();

                return ['club' => $club, 'member' => $winner, 'joined' => false];
            }

            $code->increment('uses_count');

            $this->refreshCounters($club, touchActivity: true);

            $this->audit->log($club, 'member.joined', $user, $member, [
                'code_masked' => $code->masked(),
                'code_id'     => $code->id,
                'role'        => $member->role,
                'reactivated' => $existing !== null,
            ], ClubAuditEvent::ACTOR_MEMBER);

            return ['club' => $club, 'member' => $member, 'joined' => true];
        });

        if (isset($outcome['failed'])) {
            $this->failures->record($user, $ip);
            AppLog::event('club.join_failed', ['user_id' => $user->id, 'reason' => $outcome['failed']]);

            throw new ClubDomainException(ClubDomainException::CLUB_CODE_INVALID, 422);
        }

        $this->context->forget($outcome['club']->id, $user->id);

        return $outcome;
    }

    /** Leave a club. A sole owner must transfer ownership first. */
    public function leave(Club $club, User $user): void
    {
        DB::transaction(function () use ($club, $user) {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $this->lockClub($club);

            $member = ClubMember::where('club_id', $club->id)->where('user_id', $user->id)->lockForUpdate()->first();
            if (!$member?->isActive()) {
                throw ClubDomainException::notFound();
            }

            if ($member->role === ClubRole::Owner && $this->activeOwnerCount($club) <= 1) {
                throw new ClubDomainException(ClubDomainException::CLUB_OWNER_MUST_TRANSFER, 422);
            }

            $member->forceFill([
                'status'  => ClubMemberStatus::Left,
                'left_at' => now(),
            ])->save();

            $this->refreshCounters($club);

            $this->audit->log($club, 'member.left', $user, $member, ['role' => $member->role], ClubAuditEvent::ACTOR_MEMBER);
        });

        $this->context->forget($club->id, $user->id);
    }

    // ------------------------------------------------------------------
    // Admin / club-admin actions
    // ------------------------------------------------------------------

    /**
     * Add an existing user (admin action). Idempotent for an active member
     * (returns the row unchanged). Banned users must be unbanned first.
     */
    public function add(Club $club, User $target, ClubRole $role, User $actor, string $actorKind): ClubMember
    {
        $member = DB::transaction(function () use ($club, $target, $role, $actor, $actorKind) {
            DB::table('users')->where('id', $target->id)->lockForUpdate()->first();
            $this->lockClub($club);

            $existing = ClubMember::where('club_id', $club->id)->where('user_id', $target->id)->lockForUpdate()->first();

            if ($existing?->isBanned()) {
                throw new ClubDomainException(ClubDomainException::CLUB_MEMBER_BANNED, 422);
            }
            if ($existing?->isActive()) {
                return $existing;
            }

            $this->assertCapacity($club, $target);

            $member = $this->activate($club, $target, $existing, $role, [
                'invite_code_id'   => null,
                'added_by_user_id' => $actor->id,
            ]);
            if ($member === null) {
                return ClubMember::where('club_id', $club->id)->where('user_id', $target->id)->firstOrFail();
            }

            $this->refreshCounters($club, touchActivity: true);

            $this->audit->log($club, 'member.added', $actor, $member, [
                'target_user_id' => $target->id,
                'role'           => $role,
                'reactivated'    => $existing !== null,
            ], $actorKind);

            return $member;
        });

        $this->context->forget($club->id, $target->id);

        return $member;
    }

    /** Change an active member's role. Demoting the last owner → club_last_owner. */
    public function changeRole(ClubMember $member, ClubRole $role, User $actor, string $actorKind): ClubMember
    {
        $club = $member->club;

        $result = DB::transaction(function () use ($member, $club, $role, $actor, $actorKind) {
            $this->lockClub($club);
            $locked = $this->lockMember($member);

            if (!$locked->isActive()) {
                throw new ClubDomainException(ClubDomainException::CLUB_MEMBER_NOT_FOUND, 404);
            }
            if ($locked->role === $role) {
                return $locked;
            }
            if ($locked->role === ClubRole::Owner && $this->activeOwnerCount($club) <= 1) {
                throw new ClubDomainException(ClubDomainException::CLUB_LAST_OWNER, 422);
            }

            $from = $locked->role;
            $locked->forceFill(['role' => $role])->save();

            $this->refreshCounters($club);

            $this->audit->log($club, 'member.role_changed', $actor, $locked, [
                'target_user_id' => $locked->user_id,
                'from_role'      => $from,
                'to_role'        => $role,
            ], $actorKind);

            return $locked;
        });

        $this->syncModel($member, $result);

        return $member;
    }

    /** Remove an active member (they may re-join later with a valid code). */
    public function remove(ClubMember $member, User $actor, string $actorKind, ?string $reason): void
    {
        $this->endMembership($member, ClubMemberStatus::Removed, 'member.removed', $actor, $actorKind, $reason);
    }

    /** Ban a member (any status): they can never re-join with a code (D23). */
    public function ban(ClubMember $member, User $actor, string $actorKind, ?string $reason): void
    {
        $this->endMembership($member, ClubMemberStatus::Banned, 'member.banned', $actor, $actorKind, $reason);
    }

    /** Lift a ban. The row becomes `removed`: no membership, but re-joining is possible again. */
    public function unban(ClubMember $member, User $actor, string $actorKind): void
    {
        $club = $member->club;

        $result = DB::transaction(function () use ($member, $club, $actor, $actorKind) {
            $this->lockClub($club);
            $locked = $this->lockMember($member);

            if (!$locked->isBanned()) {
                return $locked;
            }

            $locked->forceFill(['status' => ClubMemberStatus::Removed])->save();

            $this->audit->log($club, 'member.unbanned', $actor, $locked, [
                'target_user_id' => $locked->user_id,
            ], $actorKind);

            return $locked;
        });

        $this->syncModel($member, $result);
        $this->context->forget($club->id, $member->user_id);
    }

    /**
     * Make an active member the owner. Every other active owner becomes an
     * admin, so a club has exactly one owner after a transfer.
     */
    public function transferOwnership(Club $club, User $newOwner, User $actor, string $actorKind): void
    {
        DB::transaction(function () use ($club, $newOwner, $actor, $actorKind) {
            DB::table('users')->where('id', $newOwner->id)->lockForUpdate()->first();
            $this->lockClub($club);

            $target = ClubMember::where('club_id', $club->id)->where('user_id', $newOwner->id)->lockForUpdate()->first();
            if (!$target?->isActive()) {
                throw new ClubDomainException(ClubDomainException::CLUB_MEMBER_NOT_FOUND, 404);
            }

            $previous = ClubMember::where('club_id', $club->id)
                ->where('status', ClubMemberStatus::Active->value)
                ->where('role', ClubRole::Owner->value)
                ->where('id', '!=', $target->id)
                ->lockForUpdate()
                ->get();

            foreach ($previous as $owner) {
                $owner->forceFill(['role' => ClubRole::Admin])->save();
                $this->context->forget($club->id, $owner->user_id);
            }

            $fromRole = $target->role;
            $target->forceFill(['role' => ClubRole::Owner])->save();

            $this->refreshCounters($club);

            $this->audit->log($club, 'member.ownership_transferred', $actor, $target, [
                'new_owner_id'       => $newOwner->id,
                'previous_owner_ids' => $previous->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all(),
                'from_role'          => $fromRole,
            ], $actorKind);
        });

        $this->context->forget($club->id, $newOwner->id);
    }

    /**
     * Account deletion hook (D6, plan §11.7): every membership of the user
     * becomes `removed` (banned rows stay banned). A club that loses its last
     * owner this way is flagged needs_owner for platform admins. Call it from
     * AccountDeletionService inside its transaction.
     */
    public function removeAllForDeletedUser(User $user): void
    {
        DB::transaction(function () use ($user) {
            $rows = ClubMember::where('user_id', $user->id)
                ->where('status', ClubMemberStatus::Active->value)
                ->lockForUpdate()
                ->get();

            foreach ($rows as $member) {
                $club = Club::whereKey($member->club_id)->lockForUpdate()->first();
                if (!$club) {
                    continue;
                }

                $member->forceFill([
                    'status'         => ClubMemberStatus::Removed,
                    'left_at'        => now(),
                    'removal_reason' => 'account_deleted',
                ])->save();

                $this->refreshCounters($club);

                $this->audit->log($club, 'member.removed', null, $member, [
                    'reason' => 'account_deleted',
                    'role'   => $member->role,
                ], ClubAuditEvent::ACTOR_SYSTEM);

                $this->context->forget($club->id, $user->id);
            }
        });
    }

    /**
     * Recompute the denormalised counters from source rows (never
     * increments, so it is safe to call any time) and the needs_owner flag.
     * Saves quietly: counters never bump `version` or updated_at.
     */
    public function refreshCounters(Club $club, bool $touchActivity = false): void
    {
        $now = now();

        $members = ClubMember::where('club_id', $club->id)->where('status', ClubMemberStatus::Active->value);

        $values = [
            'members_count'           => (clone $members)->count(),
            'active_packs_count'      => ChallengePack::where('club_id', $club->id)->where('status', ChallengePack::STATUS_ACTIVE)->count(),
            'live_competitions_count' => $club->competitions()
                ->where('state', CompetitionState::Published->value)
                ->where('ends_at', '>', $now)
                ->count(),
            'needs_owner'             => !(clone $members)->where('role', ClubRole::Owner->value)->exists(),
        ];
        if ($touchActivity) {
            $values['last_activity_at'] = $now;
        }

        DB::table('clubs')->where('id', $club->id)->update($values);

        foreach ($values as $key => $value) {
            $club->setAttribute($key, $value);
        }
        $club->syncOriginalAttributes(array_keys($values));
    }

    public function activeOwnerCount(Club $club): int
    {
        return ClubMember::where('club_id', $club->id)
            ->where('status', ClubMemberStatus::Active->value)
            ->where('role', ClubRole::Owner->value)
            ->count();
    }

    /** Active memberships of a user that count against max_clubs_per_user (archived clubs do not). */
    public function activeClubCount(User $user): int
    {
        return ClubMember::where('user_id', $user->id)
            ->where('status', ClubMemberStatus::Active->value)
            ->whereHas('club', fn ($q) => $q->where('status', '!=', ClubStatus::Archived->value))
            ->count();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function assertCapacity(Club $club, User $user): void
    {
        $active = ClubMember::where('club_id', $club->id)->where('status', ClubMemberStatus::Active->value)->count();
        if ($active >= $club->effectiveMaxMembers()) {
            AppLog::event('club.join_rejected', ['club_id' => $club->id, 'user_id' => $user->id, 'reason' => 'club_full']);
            throw new ClubDomainException(ClubDomainException::CLUB_FULL, 422);
        }

        if ($this->activeClubCount($user) >= (int) config('ballspot.clubs.limits.max_clubs_per_user', 50)) {
            AppLog::event('club.join_rejected', ['club_id' => $club->id, 'user_id' => $user->id, 'reason' => 'membership_limit']);
            throw new ClubDomainException(ClubDomainException::CLUB_MEMBERSHIP_LIMIT, 422);
        }
    }

    /**
     * Create the row, or reactivate a left/removed one. Returns null when a
     * concurrent insert won the unique (club_id, user_id) race.
     */
    private function activate(Club $club, User $user, ?ClubMember $existing, ClubRole $role, array $extra): ?ClubMember
    {
        $now = now();
        $attrs = [
            'role'               => $role,
            'status'             => ClubMemberStatus::Active,
            'joined_at'          => $now,
            'left_at'            => null,
            'last_seen_at'       => $now,
            'removed_by_user_id' => null,
            'removal_reason'     => null,
        ] + $extra;

        if ($existing) {
            $existing->forceFill($attrs)->save();

            return $existing;
        }

        try {
            // Savepoint so a lost race rolls back only the insert.
            return DB::transaction(fn () => ClubMember::create(['club_id' => $club->id, 'user_id' => $user->id] + $attrs));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function endMembership(ClubMember $member, ClubMemberStatus $status, string $action, User $actor, string $actorKind, ?string $reason): void
    {
        $club = $member->club;

        $result = DB::transaction(function () use ($member, $club, $status, $action, $actor, $actorKind, $reason) {
            DB::table('users')->where('id', $member->user_id)->lockForUpdate()->first();
            $this->lockClub($club);
            $locked = $this->lockMember($member);

            if ($locked->status === $status) {
                return $locked;
            }
            if ($status === ClubMemberStatus::Removed && !$locked->isActive()) {
                throw new ClubDomainException(ClubDomainException::CLUB_MEMBER_NOT_FOUND, 404);
            }
            if ($locked->isOwner() && $this->activeOwnerCount($club) <= 1) {
                throw new ClubDomainException(ClubDomainException::CLUB_LAST_OWNER, 422);
            }

            $wasActive = $locked->isActive();
            $locked->forceFill([
                'status'             => $status,
                'left_at'            => $wasActive ? now() : $locked->left_at,
                'removed_by_user_id' => $actor->id,
                'removal_reason'     => $reason !== null && $reason !== '' ? mb_substr($reason, 0, 200) : null,
            ])->save();

            $this->refreshCounters($club);

            $this->audit->log($club, $action, $actor, $locked, [
                'target_user_id' => $locked->user_id,
                'role'           => $locked->role,
                'reason'         => $reason,
            ], $actorKind);

            return $locked;
        });

        $this->syncModel($member, $result);
        $this->context->forget($club->id, $member->user_id);
    }

    /**
     * Run a manager action on another member with the role rules
     * (ClubPortalMemberRules) re-checked under the club lock — the lock every
     * membership change takes first — on freshly loaded actor and target rows,
     * so a concurrent demotion/transfer cannot slip between check and act.
     *
     * @template T
     * @param \Closure(ClubMember $target): T $act
     * @return T
     */
    public function actAsManager(Club $club, User $actor, ClubMember $target, string $action, \Closure $act): mixed
    {
        return DB::transaction(function () use ($club, $actor, $target, $action, $act) {
            $this->lockClub($club);

            $freshActor  = ClubMember::where('club_id', $club->id)->where('user_id', $actor->id)->first();
            $freshTarget = ClubMember::where('club_id', $club->id)->whereKey($target->getKey())->first();
            if ($freshActor === null || $freshTarget === null
                || !\App\Services\ClubPortal\ClubPortalMemberRules::allows($freshActor, $freshTarget, $action)) {
                throw ClubDomainException::forbidden();
            }
            $freshTarget->setRelation('club', $club);

            return $act($freshTarget);
        });
    }

    private function lockClub(Club $club): void
    {
        DB::table('clubs')->where('id', $club->id)->lockForUpdate()->first();
    }

    private function lockMember(ClubMember $member): ClubMember
    {
        $locked = ClubMember::whereKey($member->id)->lockForUpdate()->firstOrFail();
        $locked->setRelation('club', $member->club);

        return $locked;
    }

    /** Copy the committed state back into the caller's model instance. */
    private function syncModel(ClubMember $target, ClubMember $source): void
    {
        if ($target !== $source) {
            $target->setRawAttributes($source->getAttributes(), true);
        }
    }
}
