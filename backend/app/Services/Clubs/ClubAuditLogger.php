<?php

namespace App\Services\Clubs;

use App\Models\Club;
use App\Models\ClubAuditEvent;
use App\Models\User;
use App\Support\AppLog;
use Illuminate\Database\Eloquent\Model;

/**
 * The one writer of club_audit_events (contracts §3.4).
 *
 * Call it INSIDE the DB transaction of the change it records, so a rolled
 * back change leaves no audit row and a committed one always has one.
 *
 *  - `action` must be in ClubAuditEvent::ACTIONS (else InvalidArgumentException:
 *    a typo is a bug, not data);
 *  - metadata keys are filtered to ClubAuditEvent::METADATA_KEYS and any
 *    nested key that looks like an email / code / secret is dropped, so a
 *    careless caller cannot write personal data or a full invite code;
 *  - every event is mirrored to AppLog::event('club.'.$action) with ids only.
 */
class ClubAuditLogger
{
    /** Nested keys never stored, whatever the caller passes. */
    private const FORBIDDEN_NESTED_KEYS = ['email', 'contact_email', 'code', 'password', 'token', 'secret'];

    public function log(
        Club $club,
        string $action,
        ?User $actor,
        ?Model $subject = null,
        array $metadata = [],
        string $actorKind = ClubAuditEvent::ACTOR_PLATFORM_ADMIN,
    ): ClubAuditEvent {
        if (!in_array($action, ClubAuditEvent::ACTIONS, true)) {
            throw new \InvalidArgumentException("Club audit action [{$action}] is not allow-listed.");
        }
        if (!in_array($actorKind, ClubAuditEvent::ACTOR_KINDS, true)) {
            throw new \InvalidArgumentException("Club audit actor kind [{$actorKind}] is not allow-listed.");
        }

        $clean = $this->cleanMetadata($metadata);

        $event = ClubAuditEvent::create([
            'club_id'       => $club->id,
            'actor_user_id' => $actor?->id,
            'actor_kind'    => $actorKind,
            'action'        => $action,
            'subject_type'  => $subject?->getMorphClass(),
            'subject_id'    => $subject?->getKey(),
            'metadata'      => $clean === [] ? null : $clean,
            'ip_hash'       => $this->ipHash(),
            'created_at'    => now(),
        ]);

        AppLog::event('club.' . $action, [
            'club_id'       => $club->id,
            'audit_id'      => $event->id,
            'actor_kind'    => $actorKind,
            // Mirrors the admin.* AppLog convention.
            'admin_user_id' => $actorKind === ClubAuditEvent::ACTOR_PLATFORM_ADMIN ? $actor?->id : null,
            'actor_user_id' => $actor?->id,
            'subject_type'  => $subject ? class_basename($subject) : null,
            'subject_id'    => $subject?->getKey(),
        ]);

        return $event;
    }

    /** Allow-listed top-level keys; nested arrays scrubbed of sensitive keys. */
    public function cleanMetadata(array $metadata): array
    {
        $out = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || !in_array($key, ClubAuditEvent::METADATA_KEYS, true)) {
                continue;
            }
            $out[$key] = $this->scrub($value);
        }

        return $out;
    }

    private function scrub(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $k => $v) {
                if (is_string($k) && in_array(strtolower($k), self::FORBIDDEN_NESTED_KEYS, true)) {
                    continue;
                }
                $clean[$k] = $this->scrub($v);
            }

            return $clean;
        }
        if (is_string($value) && strlen($value) > 500) {
            return substr($value, 0, 500) . '…';
        }

        return $value;
    }

    /** Keyed hash of the client IP (never the IP itself), or null outside HTTP. */
    private function ipHash(): ?string
    {
        if (app()->runningInConsole() && !app()->runningUnitTests()) {
            return null;
        }
        $ip = request()?->ip();
        if (!$ip) {
            return null;
        }

        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }
}
