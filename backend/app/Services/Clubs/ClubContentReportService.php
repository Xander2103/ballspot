<?php

namespace App\Services\Clubs;

use App\Enums\ClubMemberStatus;
use App\Enums\CompetitionState;
use App\Models\Club;
use App\Models\ClubAuditEvent;
use App\Models\ClubCompetition;
use App\Models\ClubContentReport;
use App\Models\ClubMember;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Member content reports (Wave F, plan §11.9 UGC): any active member reports
 * the club, one of its (non-draft) competitions or another active member;
 * platform admins resolve them on /admin/club-reports.
 *
 *  - The subject must belong to the reporter's club — anything else is a
 *    validation error on `subject_id` (never confirms another club's ids).
 *  - An identical open report by the same reporter (same subject + reason)
 *    is returned instead of duplicated (`created: false`).
 *  - Audit `report.created` (actor kind member) / `report.resolved`
 *    (platform_admin) with ids + enums only — never the free-text details.
 *    Both are platform-only actions (hidden from the club portal).
 */
class ClubContentReportService
{
    public const DETAILS_MAX = 500;
    public const NOTE_MAX    = 1000;

    public function __construct(private ClubAuditLogger $audit) {}

    /**
     * @return array{report: ClubContentReport, created: bool}
     *
     * @throws ValidationException subject not in the club / self-report
     */
    public function create(Club $club, User $reporter, string $subjectType, ?string $subjectId, string $reason, ?string $details): array
    {
        $ref = $this->resolveSubject($club, $reporter, $subjectType, $subjectId);

        return DB::transaction(function () use ($club, $reporter, $subjectType, $ref, $reason, $details) {
            // Serialise per reporter so a double tap never creates two rows.
            DB::table('users')->where('id', $reporter->id)->lockForUpdate()->first();

            $existing = ClubContentReport::where('club_id', $club->id)
                ->where('reporter_user_id', $reporter->id)
                ->where('subject_type', $subjectType)
                ->where('subject_ref', $ref)
                ->where('reason', $reason)
                ->open()
                ->first();
            if ($existing !== null) {
                return ['report' => $existing, 'created' => false];
            }

            $report = ClubContentReport::create([
                'club_id'          => $club->id,
                'reporter_user_id' => $reporter->id,
                'subject_type'     => $subjectType,
                'subject_ref'      => $ref,
                'reason'           => $reason,
                'details'          => $details !== null && trim($details) !== '' ? mb_substr(trim($details), 0, self::DETAILS_MAX) : null,
                'status'           => ClubContentReport::STATUS_OPEN,
            ]);

            $this->audit->log($club, 'report.created', $reporter, $report, [
                'report_id'    => $report->id,
                'subject_kind' => $subjectType,
                'subject_ref'  => $ref,
                'reason'       => $reason,
            ], ClubAuditEvent::ACTOR_MEMBER);

            return ['report' => $report, 'created' => true];
        });
    }

    /** Resolve (or re-resolve) a report: reviewed | dismissed | actioned, with an optional admin note. */
    public function resolve(ClubContentReport $report, User $admin, string $status, ?string $note): ClubContentReport
    {
        if (!in_array($status, ClubContentReport::RESOLUTIONS, true)) {
            throw ValidationException::withMessages(['status' => 'Choose reviewed, dismissed or actioned.']);
        }

        $row = DB::transaction(function () use ($report, $admin, $status, $note) {
            /** @var ClubContentReport $row */
            $row  = ClubContentReport::whereKey($report->id)->lockForUpdate()->firstOrFail();
            $from = $row->status;

            $row->forceFill([
                'status'              => $status,
                'admin_note'          => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, self::NOTE_MAX) : null,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at'         => now(),
            ])->save();

            $this->audit->log($row->club, 'report.resolved', $admin, $row, [
                'report_id'   => $row->id,
                'from_status' => $from,
                'to_status'   => $status,
            ], ClubAuditEvent::ACTOR_PLATFORM_ADMIN);

            return $row;
        });

        $report->setRawAttributes($row->getAttributes(), true);

        return $report;
    }

    /** Admin list: filters status (''|open|…), club id, reason; open first by age, then newest. */
    public function adminList(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $f = $this->normalizeFilters($filters);

        return ClubContentReport::query()
            ->with(['club:id,name,slug,public_id', 'reporter:id,username', 'reviewedBy:id,username'])
            ->when($f['status'] !== '', fn ($q) => $q->where('status', $f['status']))
            ->when($f['club'] !== '', fn ($q) => $q->where('club_id', (int) $f['club']))
            ->when($f['reason'] !== '', fn ($q) => $q->where('reason', $f['reason']))
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN status = 'open' THEN created_at END ASC")
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage)->withQueryString();
    }

    /** @return array{status: string, club: string, reason: string} */
    public function normalizeFilters(array $input): array
    {
        $status = is_scalar($input['status'] ?? null) ? (string) $input['status'] : ClubContentReport::STATUS_OPEN;
        $club   = is_scalar($input['club'] ?? null) && ctype_digit((string) $input['club']) ? (string) $input['club'] : '';
        $reason = is_scalar($input['reason'] ?? null) ? (string) $input['reason'] : '';

        return [
            // '' (all) is explicit: ?status= ; a missing status means "open".
            'status' => $status === 'all' || $status === '' ? '' : (in_array($status, ClubContentReport::STATUSES, true) ? $status : ClubContentReport::STATUS_OPEN),
            'club'   => $club,
            'reason' => in_array($reason, ClubContentReport::REASONS, true) ? $reason : '',
        ];
    }

    public function openCount(): int
    {
        return ClubContentReport::open()->count();
    }

    /**
     * The subject's link target for the admin page.
     *
     * @return array{label: string, competition: ?ClubCompetition, member: ?ClubMember}
     */
    public function subjectFor(ClubContentReport $report): array
    {
        return match ($report->subject_type) {
            ClubContentReport::SUBJECT_COMPETITION => ['label' => 'Competition', 'competition' => ClubCompetition::where('club_id', $report->club_id)->where('public_id', $report->subject_ref)->first(['id', 'club_id', 'name', 'public_id']), 'member' => null],
            ClubContentReport::SUBJECT_MEMBER      => ['label' => 'Member', 'competition' => null, 'member' => ClubMember::with('user:id,username')->where('club_id', $report->club_id)->where('user_id', (int) $report->subject_ref)->first()],
            default                                => ['label' => 'Club', 'competition' => null, 'member' => null],
        };
    }

    // ------------------------------------------------------------------

    /** The stored subject_ref, or a validation error when the subject is not part of the club. */
    private function resolveSubject(Club $club, User $reporter, string $subjectType, ?string $subjectId): ?string
    {
        $invalid = fn () => ValidationException::withMessages(['subject_id' => __('clubs.report_messages.subject_invalid')]);
        $subjectId = $subjectId !== null ? trim($subjectId) : null;

        switch ($subjectType) {
            case ClubContentReport::SUBJECT_CLUB:
                return $club->public_id;

            case ClubContentReport::SUBJECT_COMPETITION:
                if ($subjectId === null || $subjectId === '' || strlen($subjectId) > 64) {
                    throw $invalid();
                }
                $competition = ClubCompetition::where('club_id', $club->id)
                    ->where('public_id', $subjectId)
                    ->where('state', '!=', CompetitionState::Draft->value)
                    ->first(['id', 'public_id']);
                if ($competition === null) {
                    throw $invalid();
                }

                return $competition->public_id;

            case ClubContentReport::SUBJECT_MEMBER:
                if ($subjectId === null || !ctype_digit($subjectId) || strlen($subjectId) > 19) {
                    throw $invalid();
                }
                $userId = (int) $subjectId;
                if ($userId === (int) $reporter->id) {
                    throw ValidationException::withMessages(['subject_id' => __('clubs.report_messages.self_report')]);
                }
                $isMember = ClubMember::where('club_id', $club->id)->where('user_id', $userId)
                    ->where('status', ClubMemberStatus::Active->value)->exists();
                if (!$isMember) {
                    throw $invalid();
                }

                return (string) $userId;
        }

        throw ValidationException::withMessages(['subject_type' => __('clubs.report_messages.subject_invalid')]);
    }
}
