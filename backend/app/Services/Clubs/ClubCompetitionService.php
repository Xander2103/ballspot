<?php

namespace App\Services\Clubs;

use App\Enums\CompetitionAttemptStatus;
use App\Enums\CompetitionPhase;
use App\Enums\CompetitionState;
use App\Enums\NormalPlayPolicy;
use App\Enums\ReplayMode;
use App\Enums\ScoreRule;
use App\Exceptions\ClubDomainException;
use App\Models\ChallengePack;
use App\Models\Club;
use App\Models\ClubAuditEvent;
use App\Models\ClubCompetition;
use App\Models\ClubCompetitionAttempt;
use App\Models\ClubCompetitionPack;
use App\Models\PackAttempt;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Competition lifecycle for platform admins (later: club admins) — plan §5.7,
 * §6.1, §8.4. Every write runs in a transaction with the competition row
 * locked, checks the optimistic `version` (stale_version), goes through the
 * ClubCompetitionRulesGuard matrix and writes its audit event in the same
 * transaction.
 *
 * Input `$data` (validated by the FormRequests) uses: name, description,
 * starts_at / ends_at (UTC CarbonInterface), replay_mode, max_attempts,
 * score_rule, normal_play_policy, pack_ids (ordered list of pack ids).
 * Only keys present in `$data` are changed on update.
 *
 * Invariants (§4.8): linked packs belong to the competition's club; at
 * publish (and at lock) every linked pack is active with ≥ 1 ready challenge;
 * ends_at > starts_at and the window is at most clubs.competition.max_duration_days.
 */
class ClubCompetitionService
{
    public function __construct(
        private ClubCompetitionRulesGuard $guard,
        private ClubAuditLogger $audit,
        private ClubStandingsService $standings,
        private ClubMembershipService $memberships,
    ) {}

    public function create(Club $club, array $data, User $actor, string $actorKind = ClubAuditEvent::ACTOR_PLATFORM_ADMIN): ClubCompetition
    {
        if ($club->isArchived()) {
            throw new ClubDomainException(ClubDomainException::CLUB_STATUS_INVALID, 422);
        }

        $rules = $this->normaliseRules($data + [
            'replay_mode'        => (string) ($club->settings['default_replay_mode'] ?? config('ballspot.clubs.competition.default_replay_mode', 'single_attempt')),
            'normal_play_policy' => (string) ($club->settings['default_normal_play_policy'] ?? config('ballspot.clubs.competition.default_normal_play_policy', 'blocked')),
        ]);
        $this->assertWindow($data['starts_at'], $data['ends_at']);
        $packIds = $this->assertPacks($club, $data['pack_ids'] ?? []);

        return DB::transaction(function () use ($club, $data, $rules, $packIds, $actor, $actorKind) {
            $competition = ClubCompetition::create([
                'club_id'            => $club->id,
                'name'               => $data['name'],
                'description'        => $data['description'] ?? null,
                'state'              => CompetitionState::Draft,
                'starts_at'          => $data['starts_at'],
                'ends_at'            => $data['ends_at'],
                'timezone'           => $club->timezone,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ] + $rules);

            $this->syncPacks($competition, $packIds, $actor, $actorKind);

            $this->audit->log($club, 'competition.created', $actor, $competition, [
                'competition_id' => $competition->id,
                'name'           => $competition->name,
                'count'          => count($packIds),
            ] + $rules, $actorKind);

            return $competition->fresh();
        });
    }

    /**
     * Edit (only the keys present in $data). Locked fields that change →
     * competition_rules_locked; an ends_at extension after start is audited
     * separately as competition.ends_at_extended.
     */
    public function update(ClubCompetition $competition, array $data, User $actor, int $expectedVersion, string $actorKind = ClubAuditEvent::ACTOR_PLATFORM_ADMIN): ClubCompetition
    {
        return DB::transaction(function () use ($competition, $data, $actor, $expectedVersion, $actorKind) {
            $row = $this->lockRow($competition, $expectedVersion);

            $changes = $this->changes($row, $data);
            if ($changes === []) {
                return $row;
            }
            $this->guard->assertCanChange($row, $changes);

            $fields = array_keys($changes);
            $ruleKeys = ['replay_mode', 'max_attempts', 'score_rule', 'normal_play_policy'];
            if (array_intersect($ruleKeys, $fields) !== []) {
                $rules = $this->normaliseRules(array_merge([
                    'replay_mode'        => $row->replay_mode->value,
                    'max_attempts'       => $row->max_attempts,
                    'score_rule'         => $row->score_rule->value,
                    'normal_play_policy' => $row->normal_play_policy->value,
                ], array_intersect_key($changes, array_flip($ruleKeys))));
                $row->fill($rules);
            }

            $startsAt = $changes['starts_at'] ?? $row->starts_at;
            $endsAt   = $changes['ends_at'] ?? $row->ends_at;
            if (isset($changes['starts_at']) || isset($changes['ends_at'])) {
                $this->assertWindow($startsAt, $endsAt);
                if ($row->state === CompetitionState::Published && !$row->isLocked() && $startsAt->lte(now())) {
                    // Upcoming: starts_at must stay in the future (it would otherwise start silently).
                    throw ValidationException::withMessages(['starts_at' => 'The start must stay in the future for a published competition.']);
                }
            }

            $extended = $row->isLocked() && isset($changes['ends_at']);
            $endsFrom = $row->ends_at;

            foreach (['name', 'description', 'starts_at', 'ends_at'] as $field) {
                if (array_key_exists($field, $changes)) {
                    $row->{$field} = $changes[$field];
                }
            }

            if (array_key_exists('pack_ids', $changes)) {
                $packIds = $this->assertPacks($row->club, $changes['pack_ids']);
                if ($row->state === CompetitionState::Published) {
                    $this->assertPlayablePacks($row->club, $packIds);
                }
                $this->syncPacks($row, $packIds, $actor, $actorKind);
            }

            $row->updated_by_user_id = $actor->id;
            $row->version = $row->version + 1;
            $row->save();

            if ($extended) {
                $this->audit->log($row->club, 'competition.ends_at_extended', $actor, $row, [
                    'competition_id' => $row->id,
                    'ends_at_from'   => $endsFrom,
                    'ends_at_to'     => $row->ends_at,
                ], $actorKind);
                $fields = array_values(array_diff($fields, ['ends_at']));
            }
            if ($fields !== []) {
                $this->audit->log($row->club, 'competition.updated', $actor, $row, [
                    'competition_id' => $row->id,
                    'fields'         => $fields,
                    'status'         => $row->phase()->value,
                ], $actorKind);
            }

            return $row->fresh();
        });
    }

    /** D20: move ends_at later (only), audited. Allowed before and after the start. */
    public function extend(ClubCompetition $competition, CarbonInterface $endsAt, User $actor, int $expectedVersion, string $actorKind = ClubAuditEvent::ACTOR_PLATFORM_ADMIN): ClubCompetition
    {
        if ($endsAt->lte($competition->ends_at)) {
            throw new ClubDomainException(ClubDomainException::COMPETITION_RULES_LOCKED, 422);
        }

        return $this->update($competition, ['ends_at' => $endsAt], $actor, $expectedVersion, $actorKind);
    }

    public function publish(ClubCompetition $competition, User $actor, ?int $expectedVersion = null, string $actorKind = ClubAuditEvent::ACTOR_PLATFORM_ADMIN): ClubCompetition
    {
        $row = DB::transaction(function () use ($competition, $actor, $expectedVersion, $actorKind) {
            $row = $this->lockRow($competition, $expectedVersion);
            if ($row->state !== CompetitionState::Draft) {
                throw new ClubDomainException(ClubDomainException::COMPETITION_NOT_EDITABLE, 422);
            }
            ClubAccess::requireActiveClub($row->club);
            if (now()->gte($row->ends_at)) {
                throw ValidationException::withMessages(['ends_at' => 'The competition window has already ended.']);
            }
            $this->assertWindow($row->starts_at, $row->ends_at);

            $packIds = ClubCompetitionPack::where('club_competition_id', $row->id)->orderBy('sort_order')->pluck('challenge_pack_id')->all();
            if ($packIds === []) {
                throw new ClubDomainException(ClubDomainException::COMPETITION_INVALID_PACK, 422);
            }
            $this->assertPlayablePacks($row->club, $packIds);

            $row->forceFill([
                'state'              => CompetitionState::Published,
                'published_at'       => now(),
                'updated_by_user_id' => $actor->id,
                'version'            => $row->version + 1,
            ])->save();

            $this->audit->log($row->club, 'competition.published', $actor, $row, [
                'competition_id' => $row->id,
                'count'          => count($packIds),
            ], $actorKind);

            return $row;
        });

        // Starts immediately (start time already passed) → lock now.
        if ($row->isLocked()) {
            $this->guard->lock($row);
        }
        $this->memberships->refreshCounters($row->club);

        return $row->fresh();
    }

    /** Cancel before the start (draft or upcoming). */
    public function cancel(ClubCompetition $competition, User $actor, ?string $reason = null, ?int $expectedVersion = null, string $actorKind = ClubAuditEvent::ACTOR_PLATFORM_ADMIN): ClubCompetition
    {
        $row = DB::transaction(function () use ($competition, $actor, $reason, $expectedVersion, $actorKind) {
            $row = $this->lockRow($competition, $expectedVersion);
            if (!in_array($row->state, [CompetitionState::Draft, CompetitionState::Published], true) || $row->isLocked()) {
                throw new ClubDomainException(
                    $row->isLocked() && $row->state === CompetitionState::Published ? ClubDomainException::COMPETITION_RULES_LOCKED : ClubDomainException::COMPETITION_NOT_EDITABLE,
                    422,
                );
            }

            $row->forceFill([
                'state'              => CompetitionState::Cancelled,
                'cancelled_at'       => now(),
                'updated_by_user_id' => $actor->id,
                'version'            => $row->version + 1,
            ])->save();

            $this->audit->log($row->club, 'competition.cancelled', $actor, $row, array_filter([
                'competition_id' => $row->id,
                'reason'         => $reason,
            ], fn ($v) => $v !== null), $actorKind);

            return $row;
        });
        $this->memberships->refreshCounters($row->club);

        return $row->fresh();
    }

    /**
     * Void a started / ended / finalized competition (platform admin, reason
     * required): active attempts are expired, club finishes are withdrawn,
     * the standings stay readable for the audit but the competition is out of
     * every board and list.
     */
    public function void(ClubCompetition $competition, User $actor, string $reason, ?int $expectedVersion = null, string $actorKind = ClubAuditEvent::ACTOR_PLATFORM_ADMIN): ClubCompetition
    {
        $row = DB::transaction(function () use ($competition, $actor, $reason, $expectedVersion, $actorKind) {
            $row = $this->lockRow($competition, $expectedVersion);
            $allowed = ($row->state === CompetitionState::Published && $row->isLocked()) || $row->state === CompetitionState::Finalized;
            if (!$allowed) {
                throw new ClubDomainException(ClubDomainException::COMPETITION_NOT_EDITABLE, 422);
            }

            ClubCompetitionAttempt::where('club_competition_id', $row->id)
                ->where('status', CompetitionAttemptStatus::Active->value)
                ->update(['status' => CompetitionAttemptStatus::Expired->value, 'active_slot' => null, 'updated_at' => now()]);
            $finishes = $row->finishes()->delete();

            $row->forceFill([
                'state'              => CompetitionState::Voided,
                'voided_at'          => now(),
                'void_reason'        => $reason,
                'updated_by_user_id' => $actor->id,
                'version'            => $row->version + 1,
            ])->save();

            $this->audit->log($row->club, 'competition.voided', $actor, $row, [
                'competition_id' => $row->id,
                'reason'         => $reason,
                'placements'     => $finishes,
            ], $actorKind);

            return $row;
        });
        $this->memberships->refreshCounters($row->club);

        return $row->fresh();
    }

    /**
     * Void one attempt (cheating, reason required): ignored by the standings,
     * the player's results are recomputed, a finalized competition is
     * re-finalized. Allowed from the start on.
     */
    public function voidAttempt(ClubCompetitionAttempt $attempt, User $actor, string $reason, string $actorKind = ClubAuditEvent::ACTOR_PLATFORM_ADMIN): ClubCompetitionAttempt
    {
        $competition = $attempt->competition;

        DB::transaction(function () use ($attempt, $competition, $actor, $reason, $actorKind) {
            // Same lock order as a guess (user row, then attempt row): the
            // player's standings are recomputed by one writer at a time.
            DB::table('users')->where('id', $attempt->user_id)->lockForUpdate()->first();
            $row = ClubCompetitionAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $state = $competition->state;
            $allowed = ($state === CompetitionState::Published && $competition->isLocked()) || $state === CompetitionState::Finalized;
            if (!$allowed || $row->isVoided()) {
                throw new ClubDomainException($row->isVoided() ? ClubDomainException::ATTEMPT_NOT_ACTIVE : ClubDomainException::COMPETITION_NOT_EDITABLE, $row->isVoided() ? 409 : 422);
            }

            $row->markInactive(CompetitionAttemptStatus::Voided);
            $row->forceFill(['voided_at' => now(), 'voided_by_user_id' => $actor->id, 'void_reason' => $reason])->save();

            $this->standings->recomputePlayer($competition, (int) $row->user_id);

            $this->audit->log($competition->club, 'attempt.voided', $actor, $row, [
                'competition_id' => $competition->id,
                'attempt_id'     => $row->id,
                'target_user_id' => $row->user_id,
                'pack_id'        => $row->challenge_pack_id,
                'reason'         => $reason,
            ], $actorKind);
        });

        if ($competition->fresh()->state === CompetitionState::Finalized) {
            app(ClubCompetitionCloseService::class)->finalize($competition->fresh(), $actor, refinalize: true, actorKind: $actorKind);
        }

        return $attempt->fresh();
    }

    /**
     * Rebuild the standings of one competition (admin button / console).
     * Dry run: the diff only. Apply: rewrite the derived rows and audit
     * `competition.rebuilt`; a finalized competition is re-finalized
     * (finishes rewritten) by ClubCompetitionCloseService.
     *
     * @return array{players: int, changes: list<array>, participants: array}
     */
    public function rebuildStandings(ClubCompetition $competition, ?User $actor, bool $dryRun, string $actorKind = ClubAuditEvent::ACTOR_PLATFORM_ADMIN, string $source = 'admin'): array
    {
        if (in_array($competition->state, [CompetitionState::Draft, CompetitionState::Cancelled], true)) {
            throw new ClubDomainException(ClubDomainException::COMPETITION_NOT_EDITABLE, 422);
        }

        $result = $this->standings->rebuild($competition, $dryRun);
        if ($dryRun) {
            return $result;
        }

        if ($competition->state === CompetitionState::Finalized) {
            app(ClubCompetitionCloseService::class)->finalize($competition, $actor, refinalize: true, actorKind: $actorKind);
        } else {
            DB::transaction(fn () => $this->audit->log($competition->club, 'competition.rebuilt', $actor, $competition, [
                'competition_id' => $competition->id,
                'count'          => count($result['changes']),
                'source'         => $source,
            ], $actor ? $actorKind : ClubAuditEvent::ACTOR_SYSTEM));
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Helpers (also used by the admin editor)
    // ------------------------------------------------------------------

    /**
     * Mode → max_attempts / score_rule invariants: no_replay and
     * single_attempt force 1 + first; multiple_attempts needs 2…cap.
     *
     * @return array{replay_mode: string, max_attempts: int, score_rule: string, normal_play_policy: string}
     */
    public function normaliseRules(array $data): array
    {
        $mode = ReplayMode::tryFrom((string) ($data['replay_mode'] ?? '')) ?? ReplayMode::SingleAttempt;
        $policy = NormalPlayPolicy::tryFrom((string) ($data['normal_play_policy'] ?? '')) ?? NormalPlayPolicy::Blocked;

        if ($mode !== ReplayMode::MultipleAttempts) {
            return ['replay_mode' => $mode->value, 'max_attempts' => 1, 'score_rule' => ScoreRule::First->value, 'normal_play_policy' => $policy->value];
        }

        $cap = max(2, (int) config('ballspot.clubs.competition.max_attempts_cap', 10));
        $max = (int) ($data['max_attempts'] ?? 0);
        if ($max < 2 || $max > $cap) {
            throw ValidationException::withMessages(['max_attempts' => "Multiple attempts needs a maximum between 2 and {$cap}."]);
        }
        $rule = ScoreRule::tryFrom((string) ($data['score_rule'] ?? '')) ?? ScoreRule::Best;

        return ['replay_mode' => $mode->value, 'max_attempts' => $max, 'score_rule' => $rule->value, 'normal_play_policy' => $policy->value];
    }

    public function assertWindow(CarbonInterface $startsAt, CarbonInterface $endsAt): void
    {
        if ($endsAt->lte($startsAt)) {
            throw ValidationException::withMessages(['ends_at' => 'The end must be after the start.']);
        }
        $maxDays = (int) config('ballspot.clubs.competition.max_duration_days', 120);
        if ($maxDays > 0 && Carbon::instance($startsAt)->addDays($maxDays)->lt($endsAt)) {
            throw ValidationException::withMessages(['ends_at' => "A competition can run at most {$maxDays} days."]);
        }
    }

    /**
     * Linked packs must belong to the club (AT-ADM-07: another club's pack or
     * a public pack is a validation error). Returns the de-duplicated, ordered ids.
     *
     * @return list<int>
     */
    public function assertPacks(Club $club, array $packIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $packIds)));
        if ($ids === []) {
            return [];
        }
        $own = ChallengePack::whereIn('id', $ids)->where('club_id', $club->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($own) !== count($ids)) {
            throw ValidationException::withMessages(['pack_ids' => 'Only packs of this club can be linked to its competitions.']);
        }

        return $ids;
    }

    /** Publish / lock invariant: active with ≥ 1 ready challenge. */
    public function assertPlayablePacks(Club $club, array $packIds): void
    {
        if ($packIds === []) {
            throw new ClubDomainException(ClubDomainException::COMPETITION_INVALID_PACK, 422);
        }
        $packs = ChallengePack::with('challenges')->whereIn('id', $packIds)->where('club_id', $club->id)->get();
        foreach ($packIds as $id) {
            $pack = $packs->firstWhere('id', $id);
            if ($pack === null || $pack->status !== ChallengePack::STATUS_ACTIVE || $pack->readyChallenges()->isEmpty()) {
                throw new ClubDomainException(ClubDomainException::COMPETITION_INVALID_PACK, 422, ['pack_id' => (int) $id]);
            }
        }
    }

    /**
     * Normal attempts in progress per club pack (editor warning: they may
     * still finish after the pack is reserved).
     *
     * @return array<int, int> pack id => active normal attempts
     */
    public function inProgressNormalAttempts(Club $club): array
    {
        return PackAttempt::query()
            ->join('challenge_packs', 'challenge_packs.id', '=', 'pack_attempts.challenge_pack_id')
            ->where('challenge_packs.club_id', $club->id)
            ->where('pack_attempts.status', PackAttempt::STATUS_ACTIVE)
            ->groupBy('pack_attempts.challenge_pack_id')
            ->selectRaw('pack_attempts.challenge_pack_id as pack_id, COUNT(*) as n')
            ->pluck('n', 'pack_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    private function lockRow(ClubCompetition $competition, ?int $expectedVersion): ClubCompetition
    {
        /** @var ClubCompetition $row */
        $row = ClubCompetition::whereKey($competition->id)->lockForUpdate()->firstOrFail();
        if ($expectedVersion !== null && (int) $row->version !== $expectedVersion) {
            throw new ClubDomainException(ClubDomainException::STALE_VERSION, 409);
        }

        return $row;
    }

    /** Only the keys of $data whose value differs from the stored one. */
    private function changes(ClubCompetition $row, array $data): array
    {
        $changes = [];
        foreach (['name', 'description'] as $f) {
            if (array_key_exists($f, $data) && $data[$f] !== $row->{$f}) {
                $changes[$f] = $data[$f];
            }
        }
        foreach (['starts_at', 'ends_at'] as $f) {
            if (isset($data[$f]) && !$data[$f]->equalTo($row->{$f})) {
                $changes[$f] = Carbon::instance($data[$f])->utc();
            }
        }
        foreach (['replay_mode', 'score_rule', 'normal_play_policy'] as $f) {
            if (array_key_exists($f, $data) && $data[$f] !== null && (string) $data[$f] !== $row->{$f}->value) {
                $changes[$f] = (string) $data[$f];
            }
        }
        if (array_key_exists('max_attempts', $data) && $data['max_attempts'] !== null) {
            // For the single-attempt modes the stored max is always 1: a submitted
            // max only matters (and only differs) in multiple mode.
            $mode = (string) ($data['replay_mode'] ?? $row->replay_mode->value);
            if ($mode === ReplayMode::MultipleAttempts->value && (int) $data['max_attempts'] !== (int) $row->max_attempts) {
                $changes['max_attempts'] = (int) $data['max_attempts'];
            }
        }
        if (isset($changes['score_rule']) && (string) ($data['replay_mode'] ?? $row->replay_mode->value) !== ReplayMode::MultipleAttempts->value) {
            unset($changes['score_rule']); // forced `first` outside multiple mode
        }
        if (array_key_exists('pack_ids', $data)) {
            $current = ClubCompetitionPack::where('club_competition_id', $row->id)->orderBy('sort_order')->orderBy('id')->pluck('challenge_pack_id')->map(fn ($id) => (int) $id)->all();
            $new = array_values(array_unique(array_map('intval', (array) $data['pack_ids'])));
            if ($new !== $current) {
                $changes['pack_ids'] = $new;
            }
        }

        return $changes;
    }

    /** Replace the linked packs (order = sort_order), auditing links/unlinks. */
    private function syncPacks(ClubCompetition $competition, array $packIds, User $actor, string $actorKind): void
    {
        $existing = ClubCompetitionPack::where('club_competition_id', $competition->id)->get()->keyBy('challenge_pack_id');

        foreach ($existing as $packId => $link) {
            if (!in_array((int) $packId, $packIds, true)) {
                $link->delete();
                $this->audit->log($competition->club, 'pack.unlinked', $actor, $competition, ['competition_id' => $competition->id, 'pack_id' => (int) $packId], $actorKind);
            }
        }
        foreach ($packIds as $i => $packId) {
            $link = $existing->get($packId);
            if ($link) {
                if ((int) $link->sort_order !== $i) {
                    $link->forceFill(['sort_order' => $i])->save();
                }
                continue;
            }
            ClubCompetitionPack::create(['club_competition_id' => $competition->id, 'challenge_pack_id' => $packId, 'sort_order' => $i]);
            $this->audit->log($competition->club, 'pack.linked', $actor, $competition, ['competition_id' => $competition->id, 'pack_id' => $packId], $actorKind);
        }
    }

    /** Phase label for the admin UI. */
    public static function phaseLabel(ClubCompetition $competition): string
    {
        return match ($competition->phase()) {
            CompetitionPhase::Draft     => 'Draft',
            CompetitionPhase::Upcoming  => 'Upcoming',
            CompetitionPhase::Active    => 'Active',
            CompetitionPhase::Ended     => 'Ended',
            CompetitionPhase::Finalized => 'Finalized',
            CompetitionPhase::Cancelled => 'Cancelled',
            CompetitionPhase::Voided    => 'Voided',
        };
    }
}
