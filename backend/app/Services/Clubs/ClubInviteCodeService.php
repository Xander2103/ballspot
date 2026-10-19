<?php

namespace App\Services\Clubs;

use App\Enums\ClubRole;
use App\Exceptions\ClubDomainException;
use App\Models\Club;
use App\Models\ClubInviteCode;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Invite codes (plan §4.4): generate, normalise, resolve, revoke, regenerate.
 *
 * Codes are 8 characters from an alphabet without look-alikes
 * (config ballspot.clubs.codes), stored normalised and globally unique.
 * Input is case-insensitive and ignores spaces and dashes. The full code is
 * never logged or audited — only ClubInviteCode::masked().
 */
class ClubInviteCodeService
{
    /** Reasons a code is unusable (internal logging only — the API answers one uniform error). */
    public const REASON_UNKNOWN       = 'unknown';
    public const REASON_REVOKED       = 'revoked';
    public const REASON_EXPIRED       = 'expired';
    public const REASON_EXHAUSTED     = 'exhausted';
    public const REASON_CLUB_INACTIVE = 'club_inactive';
    public const REASON_JOIN_DISABLED = 'join_disabled';

    private const MAX_GENERATION_TRIES = 10;

    public function __construct(private ClubAuditLogger $audit) {}

    /** Upper case, spaces/dashes (and other separators) stripped. */
    public function normalise(string $raw): string
    {
        return strtoupper((string) preg_replace('/[\s\-_.]+/u', '', trim($raw)));
    }

    /** Display form ABCD-EFGH. */
    public function format(string $code): string
    {
        return implode('-', str_split($this->normalise($code), 4));
    }

    /**
     * Create a code for the club.
     *
     * @param array{label?: ?string, expires_at?: CarbonInterface|string|null, max_uses?: ?int, grants_role?: ClubRole|string|null} $opts
     */
    public function create(Club $club, array $opts, User $actor, string $actorKind): ClubInviteCode
    {
        return DB::transaction(function () use ($club, $opts, $actor, $actorKind) {
            DB::table('clubs')->where('id', $club->id)->lockForUpdate()->first();

            if ($this->activeCount($club) >= (int) config('ballspot.clubs.limits.max_active_codes', 20)) {
                throw new ClubDomainException(ClubDomainException::CLUB_CODE_LIMIT, 422);
            }

            $code = $this->insert($club, $opts, $actor);

            $this->audit->log($club, 'code.created', $actor, $code, [
                'code_masked' => $code->masked(),
                'label'       => $code->label,
                'expires_at'  => $code->expires_at,
                'max_uses'    => $code->max_uses,
                'grants_role' => $code->grants_role,
            ], $actorKind);

            return $code;
        });
    }

    /** Idempotent: revoking a revoked code changes nothing and writes no audit row. */
    public function revoke(ClubInviteCode $code, User $actor, string $actorKind): void
    {
        DB::transaction(function () use ($code, $actor, $actorKind) {
            $locked = ClubInviteCode::whereKey($code->id)->lockForUpdate()->firstOrFail();
            if ($locked->isRevoked()) {
                return;
            }

            $locked->forceFill(['revoked_at' => now(), 'revoked_by_user_id' => $actor->id])->save();

            $this->audit->log($locked->club, 'code.revoked', $actor, $locked, [
                'code_masked' => $locked->masked(),
            ], $actorKind);

            $code->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Revoke the code and issue a new one with the same options (label,
     * max_uses, role, a still-future expiry). One `code.regenerated` event.
     * Not subject to the active-code limit: it replaces a code one-for-one.
     */
    public function regenerate(ClubInviteCode $code, User $actor, string $actorKind): ClubInviteCode
    {
        return DB::transaction(function () use ($code, $actor, $actorKind) {
            $locked = ClubInviteCode::whereKey($code->id)->lockForUpdate()->firstOrFail();
            $club   = $locked->club;

            if (!$locked->isRevoked()) {
                $locked->forceFill(['revoked_at' => now(), 'revoked_by_user_id' => $actor->id])->save();
            }

            $new = $this->insert($club, [
                'label'       => $locked->label,
                'expires_at'  => $locked->expires_at && $locked->expires_at->isFuture() ? $locked->expires_at : null,
                'max_uses'    => $locked->max_uses,
                'grants_role' => $locked->grants_role,
            ], $actor);

            $this->audit->log($club, 'code.regenerated', $actor, $new, [
                'code_masked'      => $new->masked(),
                'replaced_code_id' => $locked->id,
            ], $actorKind);

            $code->setRawAttributes($locked->getAttributes(), true);

            return $new;
        });
    }

    /**
     * The code row when it can be used to join right now (code not revoked,
     * expired or exhausted; club active with joining enabled), else null.
     * Locks the row when called inside a transaction.
     */
    public function resolveUsable(string $normalised): ?ClubInviteCode
    {
        $code = $this->find($normalised);

        return $this->unusableReason($code) === null ? $code : null;
    }

    /** @internal the code row by normalised value, locked for update. */
    public function find(string $normalised): ?ClubInviteCode
    {
        if ($normalised === '' || strlen($normalised) > 16) {
            return null;
        }

        return ClubInviteCode::where('code', $normalised)->lockForUpdate()->first();
    }

    /** Why a code cannot be used (null = usable). For logs only. */
    public function unusableReason(?ClubInviteCode $code): ?string
    {
        return match (true) {
            $code === null                => self::REASON_UNKNOWN,
            $code->isRevoked()            => self::REASON_REVOKED,
            $code->isExpired()            => self::REASON_EXPIRED,
            $code->isExhausted()          => self::REASON_EXHAUSTED,
            !$code->club?->isActive()     => self::REASON_CLUB_INACTIVE,
            !$code->club->join_enabled    => self::REASON_JOIN_DISABLED,
            default                       => null,
        };
    }

    /** Codes that still count against max_active_codes. */
    public function activeCount(Club $club): int
    {
        return $club->inviteCodes()->usable()->count();
    }

    /** A fresh random code (not yet checked for uniqueness). */
    public function generate(): string
    {
        $alphabet = (string) config('ballspot.clubs.codes.alphabet', 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789');
        $length   = max(6, (int) config('ballspot.clubs.codes.length', 8));
        $max      = strlen($alphabet) - 1;

        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    private function insert(Club $club, array $opts, User $actor): ClubInviteCode
    {
        $role = $opts['grants_role'] ?? ClubRole::Member;
        $role = $role instanceof ClubRole ? $role : ClubRole::from((string) $role);
        if (!in_array($role, ClubRole::grantable(), true)) {
            // Ownership is never handed out by a code.
            throw new \InvalidArgumentException('An invite code can grant member or admin only.');
        }

        $expiresAt = $opts['expires_at'] ?? null;
        if ($expiresAt !== null && !$expiresAt instanceof CarbonInterface) {
            $expiresAt = Carbon::parse($expiresAt);
        }
        $maxUses = isset($opts['max_uses']) && $opts['max_uses'] !== '' ? max(1, (int) $opts['max_uses']) : null;

        for ($try = 1; ; $try++) {
            $value = $this->generate();
            if (ClubInviteCode::where('code', $value)->exists()) {
                continue;
            }

            try {
                // Savepoint: a lost race on the unique index rolls back only this insert.
                return DB::transaction(fn () => ClubInviteCode::create([
                    'club_id'            => $club->id,
                    'code'               => $value,
                    'label'              => isset($opts['label']) && $opts['label'] !== '' ? mb_substr((string) $opts['label'], 0, 60) : null,
                    'grants_role'        => $role,
                    'max_uses'           => $maxUses,
                    'uses_count'         => 0,
                    'expires_at'         => $expiresAt,
                    'created_by_user_id' => $actor->id,
                ]));
            } catch (UniqueConstraintViolationException $e) {
                if ($try >= self::MAX_GENERATION_TRIES) {
                    throw $e;
                }
            }
        }
    }
}
